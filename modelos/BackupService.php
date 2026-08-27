<?php

declare(strict_types=1);

/**
 * Servicio Independiente de Copia de Seguridad de Bases de Datos MariaDB.
 * 
 * Genera dumps individuales por cada base de datos del cliente (fugzcdpo_*),
 * genera manifest.json, README.txt, empaqueta todo en un archivo ZIP único,
 * valida integridad mediante SHA-256 y gestiona la retención y descargas seguras.
 */
class BackupService {

    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_DUMPING = 'dumping';
    public const STATE_PACKAGING = 'packaging';
    public const STATE_VALIDATING = 'validating';
    public const STATE_COMPLETED = 'completed';
    public const STATE_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATE_FAILED = 'failed';

    private static ?PDO $pdo = null;
    private static $lockHandle = null;

    /**
     * Obtiene la ruta base del directorio de backups fuera de la raíz pública.
     */
    public static function getBackupBasePath(): string {
        $config = self::getConfig();
        $configuredPath = $config['backup_storage_path'] ?? null;

        if ($configuredPath && is_dir($configuredPath)) {
            return rtrim($configuredPath, '/\\');
        }

        // En el servidor Linux de producción: /home/snuquality/backups
        $linuxPath = '/home/snuquality/backups';
        if (is_dir('/home/snuquality')) {
            if (!is_dir($linuxPath)) {
                @mkdir($linuxPath, 0750, true);
            }
            return $linuxPath;
        }

        // En desarrollo local (Windows/XAMPP): ./storage/backups
        $localPath = dirname(__DIR__) . '/storage/backups';
        if (!is_dir($localPath)) {
            @mkdir($localPath, 0750, true);
        }
        return $localPath;
    }

    /**
     * Asegura la existencia de los subdirectorios requeridos para el ciclo de backups.
     */
    public static function ensureDirectories(): array {
        $base = self::getBackupBasePath();
        $dirs = [
            'base' => $base,
            'working' => $base . '/working',
            'ready' => $base . '/ready',
            'locks' => $base . '/locks',
            'logs' => $base . '/logs'
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }

        return $dirs;
    }

    /**
     * Intenta adquirir un bloqueo exclusivo de backup.
     * Devuelve true si el bloqueo fue adquirido, o false si ya existe otro proceso en ejecución.
     */
    public static function acquireLock(): bool {
        $dirs = self::ensureDirectories();
        $lockFile = $dirs['locks'] . '/backup.lock';

        self::$lockHandle = @fopen($lockFile, 'c+');
        if (!self::$lockHandle) {
            return false;
        }

        if (!flock(self::$lockHandle, LOCK_EX | LOCK_NB)) {
            fclose(self::$lockHandle);
            self::$lockHandle = null;
            return false;
        }

        ftruncate(self::$lockHandle, 0);
        fwrite(self::$lockHandle, json_encode([
            'pid' => getmypid(),
            'time' => date('Y-m-d H:i:s'),
            'user' => get_current_user()
        ]));
        fflush(self::$lockHandle);

        return true;
    }

    /**
     * Libera el bloqueo exclusivo de backup.
     */
    public static function releaseLock(): void {
        if (self::$lockHandle) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    /**
     * Comprueba si actualmente hay un proceso de backup en ejecución.
     */
    public static function isBackupRunning(): bool {
        $dirs = self::ensureDirectories();
        $lockFile = $dirs['locks'] . '/backup.lock';

        if (!file_exists($lockFile)) {
            return false;
        }

        $fp = @fopen($lockFile, 'r+');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return true; // Está bloqueado por otro proceso
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    /**
     * Detecta dinámicamente todas las bases de datos de clientes MariaDB (fugzcdpo_*).
     * Excluye expresamente bases del sistema (information_schema, mysql, performance_schema, sys, etc.).
     * 
     * @return string[] Lista ordenada de nombres de bases de datos
     */
    public static function getDatabasesToBackup(): array {
        $pdo = self::getConnection();
        $config = self::getConfig();
        $prefix = $config['destino']['prefix'] ?? $config['database']['prefix'] ?? 'fugzcdpo_';

        $databases = [];
        try {
            $stmt = $pdo->prepare("
                SELECT SCHEMA_NAME AS db_name 
                FROM INFORMATION_SCHEMA.SCHEMATA 
                WHERE LOWER(SCHEMA_NAME) LIKE :prefix
                ORDER BY SCHEMA_NAME ASC
            ");
            $stmt->execute(['prefix' => strtolower($prefix) . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', 'snuquality', 'snuqualityapp', 'snuqualityapp_wordpress'];

            foreach ($rows as $row) {
                $db = trim($row);
                if (!in_array(strtolower($db), $systemDbs, true) && preg_match('/^fugzcdpo_[a-zA-Z0-9_]+$/i', $db)) {
                    $databases[] = $db;
                }
            }
        } catch (Exception $e) {
            self::log("Error al consultar INFORMATION_SCHEMA: " . $e->getMessage(), 'error');
            // Fallback
            try {
                $stmt = $pdo->query("SHOW DATABASES LIKE '" . addcslashes($prefix, '%_') . "%'");
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $row) {
                    $db = strtolower(trim($row));
                    if (preg_match('/^fugzcdpo_[a-zA-Z0-9_]+$/', $db)) {
                        $databases[] = $db;
                    }
                }
            } catch (Exception $ex) {
                self::log("Error en fallback SHOW DATABASES: " . $ex->getMessage(), 'error');
            }
        }

        return array_values(array_unique($databases));
    }

    /**
     * Detecta la herramienta de dump disponible en el servidor.
     */
    public static function detectDumpTool(): string {
        $candidates = [
            '/usr/bin/mariadb-dump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mariadb-dump',
            '/usr/local/bin/mysqldump',
            'mariadb-dump',
            'mysqldump'
        ];

        foreach ($candidates as $tool) {
            $output = [];
            $code = 0;
            exec("which " . escapeshellarg($tool) . " 2>/dev/null", $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
            if (file_exists($tool) && is_executable($tool)) {
                return $tool;
            }
        }

        return 'mariadb-dump'; // Default fallback
    }

    /**
     * Ejecuta el dump individual de una base de datos de forma segura.
     * 
     * @param string $dbName Nombre validado de la base
     * @param string $outputDir Directorio de destino para el archivo .sql
     * @param string $cnfFile Archivo temporal de credenciales seguras (chmod 0600)
     * @return array{success: bool, file: string, size: int, error?: string}
     */
    public static function dumpDatabase(string $dbName, string $outputDir, string $cnfFile): array {
        if (!preg_match('/^fugzcdpo_[a-zA-Z0-9_]+$/', $dbName)) {
            throw new InvalidArgumentException("Nombre de base de datos inválido: $dbName");
        }

        $dumpTool = self::detectDumpTool();
        $sqlFile = $outputDir . '/' . $dbName . '.sql';

        // Parámetros seguros y optimizados para MariaDB
        // --single-transaction: consistencia transaccional sin bloquear lecturas
        // --quick: envía filas por stream sin cargarlas todas en memoria
        // --skip-lock-tables: no bloquea tablas
        // --routines --triggers: incluye procedimientos y triggers si existen
        $cmd = escapeshellcmd($dumpTool)
            . ' --defaults-extra-file=' . escapeshellarg($cnfFile)
            . ' --single-transaction'
            . ' --quick'
            . ' --skip-lock-tables'
            . ' --routines'
            . ' --triggers'
            . ' ' . escapeshellarg($dbName)
            . ' > ' . escapeshellarg($sqlFile)
            . ' 2>&1';

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outText = implode("\n", $output);

        if ($exitCode !== 0 || !file_exists($sqlFile) || filesize($sqlFile) === 0) {
            $errorMsg = !empty($outText) ? $outText : "Fallo al generar archivo SQL o archivo vacío (código: $exitCode)";
            self::log("Error al hacer dump de '$dbName': $errorMsg", 'error');
            return [
                'success' => false,
                'file' => $sqlFile,
                'size' => file_exists($sqlFile) ? filesize($sqlFile) : 0,
                'error' => $errorMsg
            ];
        }

        $size = filesize($sqlFile);
        return [
            'success' => true,
            'file' => $sqlFile,
            'size' => $size
        ];
    }

    /**
     * Ejecuta el proceso completo de generación del Backup de Bases de Datos.
     * 
     * @param callable|null $progressCallback Callback opcional para reportar progreso
     * @return array
     */
    public static function runBackup(?callable $progressCallback = null): array {
        if (!self::acquireLock()) {
            return [
                'success' => false,
                'status' => self::STATE_FAILED,
                'error' => 'Ya existe un proceso de backup en ejecución.'
            ];
        }

        $dirs = self::ensureDirectories();
        $timestamp = date('Ymd_His');
        $backupId = 'backup_' . $timestamp;
        $zipFilename = 'SNU_BACKUP_' . date('Y-m-d_His') . '.zip';
        $finalZipPath = $dirs['ready'] . '/' . $zipFilename;
        $workingDir = $dirs['working'] . '/' . $backupId;
        $databasesDir = $workingDir . '/databases';

        @mkdir($databasesDir, 0750, true);

        self::log("=== [Backup] Starting backup $backupId ===", 'info');

        // Crear archivo de credenciales temporal con permisos restrictivos (0600)
        $config = self::getConfig();
        $dest = $config['destino'] ?? $config['database'] ?? [];
        $dbHost = $dest['host'] ?? '127.0.0.1';
        $dbUser = $dest['admin_user'] ?? $dest['user'] ?? 'root';
        $dbPass = $dest['admin_password'] ?? $dest['password'] ?? '';

        $tempCnf = $workingDir . '/.my.cnf';
        $cnfContent = "[client]\n"
            . "host=" . $dbHost . "\n"
            . "user=" . $dbUser . "\n"
            . "password=" . $dbPass . "\n";

        file_put_contents($tempCnf, $cnfContent);
        chmod($tempCnf, 0600);

        try {
            // PASO 1: Descubrimiento de Bases
            $databases = self::getDatabasesToBackup();
            $total = count($databases);
            self::log("[Backup] Databases detected: $total", 'info');

            self::updateJobState([
                'id' => $backupId,
                'status' => self::STATE_RUNNING,
                'phase' => 'discovery',
                'total' => $total,
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'current_database' => '',
                'percent' => 0,
                'message' => "Bases de datos detectadas: $total. Iniciando volcado..."
            ]);

            if ($total === 0) {
                throw new RuntimeException("No se detectaron bases de datos que cumplan el patrón 'fugzcdpo_*'.");
            }

            // PASO 2: Generar dumps individuales
            $dbResults = [];
            $successfulCount = 0;
            $failedCount = 0;

            foreach ($databases as $index => $dbName) {
                $currentNum = $index + 1;
                $pct = round(($currentNum / $total) * 75); // 0% a 75% para la fase de dump

                self::updateJobState([
                    'id' => $backupId,
                    'status' => self::STATE_DUMPING,
                    'phase' => 'dumping',
                    'total' => $total,
                    'processed' => $currentNum,
                    'successful' => $successfulCount,
                    'failed' => $failedCount,
                    'current_database' => $dbName,
                    'percent' => $pct,
                    'message' => "Volcando base $currentNum de $total ($dbName)..."
                ]);

                if ($progressCallback) {
                    $progressCallback($dbName, $currentNum, $total);
                }

                self::log("[Backup] Dumping $dbName ($currentNum/$total)", 'info');
                $dumpRes = self::dumpDatabase($dbName, $databasesDir, $tempCnf);

                if ($dumpRes['success']) {
                    $successfulCount++;
                    $dbResults[] = [
                        'name' => $dbName,
                        'file' => 'databases/' . $dbName . '.sql',
                        'status' => 'success',
                        'size' => $dumpRes['size']
                    ];
                    self::log("[Backup] Success $dbName (" . self::formatBytes($dumpRes['size']) . ")", 'info');
                } else {
                    $failedCount++;
                    $dbResults[] = [
                        'name' => $dbName,
                        'file' => 'databases/' . $dbName . '.sql',
                        'status' => 'failed',
                        'error' => $dumpRes['error'] ?? 'Error desconocido durante mariadb-dump'
                    ];
                    self::log("[Backup] Failed $dbName: " . ($dumpRes['error'] ?? ''), 'error');
                }
            }

            // Eliminar archivo temporal de credenciales inmediatamente después del volcado
            if (file_exists($tempCnf)) {
                @unlink($tempCnf);
            }

            // PASO 3: Generar manifest.json y README.txt
            self::updateJobState([
                'id' => $backupId,
                'status' => self::STATE_PACKAGING,
                'phase' => 'packaging',
                'total' => $total,
                'processed' => $total,
                'successful' => $successfulCount,
                'failed' => $failedCount,
                'current_database' => '',
                'percent' => 80,
                'message' => "Generando metadatos y empaquetando archivo ZIP..."
            ]);

            $manifestData = [
                'backup_id' => $backupId,
                'created_at' => date('c'),
                'database_pattern' => 'fugzcdpo_*',
                'total_databases' => $total,
                'successful' => $successfulCount,
                'failed' => $failedCount,
                'archive' => $zipFilename,
                'sha256' => '', // Se actualizará tras comprimir
                'databases' => $dbResults
            ];

            file_put_contents($workingDir . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            file_put_contents($workingDir . '/README.txt', self::generateReadmeContent($backupId, $total, $successfulCount, $failedCount));

            // PASO 4: Empaquetar en archivo ZIP
            self::log("[Backup] Creating ZIP archive $zipFilename...", 'info');
            $zipSuccess = self::createZipArchive($workingDir, $finalZipPath);

            if (!$zipSuccess || !file_exists($finalZipPath) || filesize($finalZipPath) === 0) {
                throw new RuntimeException("Error crítico al crear o validar el archivo ZIP empaquetado.");
            }

            // PASO 5: Validación del ZIP y Cálculo de SHA-256
            self::updateJobState([
                'id' => $backupId,
                'status' => self::STATE_VALIDATING,
                'phase' => 'validating',
                'total' => $total,
                'processed' => $total,
                'successful' => $successfulCount,
                'failed' => $failedCount,
                'current_database' => '',
                'percent' => 95,
                'message' => "Validando integridad del archivo ZIP y calculando SHA-256..."
            ]);

            self::log("[Backup] Validating ZIP and calculating SHA-256...", 'info');
            $sha256 = hash_file('sha256', $finalZipPath);
            $zipSize = filesize($finalZipPath);

            // Re-inyectar SHA-256 en el manifest y dentro del zip si es posible
            $manifestData['sha256'] = $sha256;
            file_put_contents($workingDir . '/manifest.json', json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Guardar archivo .meta.json al lado del ZIP para lectura rápida sin descomprimir
            $metaFile = $dirs['ready'] . '/' . pathinfo($zipFilename, PATHINFO_FILENAME) . '.meta.json';
            file_put_contents($metaFile, json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            self::log("[Backup] SHA-256 generated: $sha256", 'info');
            self::log("[Backup] Total ZIP size: " . self::formatBytes($zipSize), 'info');

            // PASO 6: Limpieza de archivos de working
            self::cleanupDir($workingDir);

            // PASO 7: Aplicar política de retención
            self::applyRetentionPolicy(5);

            $finalStatus = ($failedCount === 0) ? self::STATE_COMPLETED : self::STATE_COMPLETED_WITH_ERRORS;
            $msg = ($failedCount === 0)
                ? "Copia de seguridad completada exitosamente. Total bases: $total."
                : "Copia de seguridad completada con observaciones. Exitosas: $successfulCount, Fallidas: $failedCount.";

            self::log("=== [Backup] Completed ($finalStatus) ===", 'info');

            $finalResult = [
                'success' => true,
                'backup_id' => $backupId,
                'status' => $finalStatus,
                'total_databases' => $total,
                'successful' => $successfulCount,
                'failed' => $failedCount,
                'archive' => $zipFilename,
                'size' => $zipSize,
                'size_formatted' => self::formatBytes($zipSize),
                'sha256' => $sha256,
                'created_at' => date('Y-m-d H:i:s'),
                'message' => $msg,
                'databases' => $dbResults
            ];

            self::updateJobState(array_merge($finalResult, [
                'percent' => 100,
                'phase' => 'finished'
            ]));

            return $finalResult;

        } catch (Exception $e) {
            self::log("Error fatal durante el proceso de backup: " . $e->getMessage(), 'error');
            if (isset($tempCnf) && file_exists($tempCnf)) {
                @unlink($tempCnf);
            }
            if (isset($workingDir) && is_dir($workingDir)) {
                self::cleanupDir($workingDir);
            }

            $errorResult = [
                'success' => false,
                'backup_id' => $backupId,
                'status' => self::STATE_FAILED,
                'error' => $e->getMessage(),
                'percent' => 100,
                'phase' => 'failed'
            ];

            self::updateJobState($errorResult);
            return $errorResult;

        } finally {
            self::releaseLock();
        }
    }

    /**
     * Crea un archivo ZIP empaquetando todo el contenido del directorio working.
     */
    public static function createZipArchive(string $sourceDir, string $outZipPath): bool {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException("La extensión PHP ZipArchive no está instalada.");
        }

        $zip = new ZipArchive();
        if ($zip->open($outZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $sourceDir = rtrim($sourceDir, '/\\');
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                // Normalizar separadores a barra diagonal
                $relativePath = str_replace('\\', '/', $relativePath);

                // No incluir archivos de credenciales ocultos
                if (basename($filePath) === '.my.cnf') {
                    continue;
                }

                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
    }

    /**
     * Obtiene la lista de backups disponibles en la carpeta ready/.
     * 
     * @return array[] Lista de backups con metadatos
     */
    public static function getReadyBackups(): array {
        $dirs = self::ensureDirectories();
        $readyDir = $dirs['ready'];

        $backups = [];
        $files = glob($readyDir . '/SNU_BACKUP_*.zip');

        if (!$files) {
            return [];
        }

        // Ordenar del más reciente al más antiguo
        rsort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            $metaFile = $readyDir . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.meta.json';

            $meta = [];
            if (file_exists($metaFile)) {
                $json = @file_get_contents($metaFile);
                $meta = $json ? json_decode($json, true) : [];
            }

            $size = filesize($file);
            $sha256 = $meta['sha256'] ?? '';
            if (empty($sha256)) {
                $sha256 = hash_file('sha256', $file);
            }

            $backups[] = [
                'filename' => $filename,
                'backup_id' => $meta['backup_id'] ?? pathinfo($filename, PATHINFO_FILENAME),
                'created_at' => $meta['created_at'] ?? date('Y-m-d H:i:s', filemtime($file)),
                'total_databases' => $meta['total_databases'] ?? 0,
                'successful' => $meta['successful'] ?? 0,
                'failed' => $meta['failed'] ?? 0,
                'size' => $size,
                'size_formatted' => self::formatBytes($size),
                'sha256' => $sha256,
                'status' => ($meta['failed'] ?? 0) > 0 ? self::STATE_COMPLETED_WITH_ERRORS : self::STATE_COMPLETED
            ];
        }

        return $backups;
    }

    /**
     * Aplica la política de retención eliminando backups antiguos más allá del límite configurado.
     */
    public static function applyRetentionPolicy(int $maxBackups = 5): void {
        $dirs = self::ensureDirectories();
        $files = glob($dirs['ready'] . '/SNU_BACKUP_*.zip');

        if ($files && count($files) > $maxBackups) {
            rsort($files);
            $toDelete = array_slice($files, $maxBackups);

            foreach ($toDelete as $oldFile) {
                @unlink($oldFile);
                $metaFile = $dirs['ready'] . '/' . pathinfo(basename($oldFile), PATHINFO_FILENAME) . '.meta.json';
                if (file_exists($metaFile)) {
                    @unlink($metaFile);
                }
                self::log("[Backup] Retention policy deleted old archive: " . basename($oldFile), 'info');
            }
        }
    }

    /**
     * Actualiza el archivo de estado en memoria/disco para sondeo AJAX en tiempo real.
     */
    public static function updateJobState(array $state): void {
        $dirs = self::ensureDirectories();
        $stateFile = $dirs['locks'] . '/current_state.json';
        $state['updated_at'] = date('Y-m-d H:i:s');
        @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Obtiene el estado actual del job de backup.
     */
    public static function getJobState(): ?array {
        $dirs = self::ensureDirectories();
        $stateFile = $dirs['locks'] . '/current_state.json';
        if (!file_exists($stateFile)) {
            return null;
        }

        $json = @file_get_contents($stateFile);
        return $json ? json_decode($json, true) : null;
    }

    /**
     * Genera el texto estándar de README.txt para incluir dentro del ZIP.
     */
    private static function generateReadmeContent(string $backupId, int $total, int $success, int $failed): string {
        $date = date('Y-m-d H:i:s');
        return <<<TXT
================================================================================
SNU QUALITY - COPIA DE SEGURIDAD DE BASES DE DATOS MULTI-TENANT
================================================================================
Identificador de Backup: $backupId
Fecha y Hora de Generación: $date
Patrón de Esquemas: fugzcdpo_*

Resumen de Ejecución:
- Total de bases de datos detectadas: $total
- Copias individuales exitosas: $success
- Copias fallidas: $failed

Estructura del Contenido:
- manifest.json   : Metadatos estructurados con listado de bases, tamaños y hashes.
- README.txt      : Esta guía de información y restauración.
- databases/      : Carpeta con los archivos SQL independientes por cada cliente.

================================================================================
INSTRUCCIONES GENERALES DE RESTAURACIÓN INDIVIDUAL
================================================================================
Cada base de datos se encuentra en un archivo SQL independiente (.sql) dentro de 
la carpeta "databases/". Para restaurar un esquema de cliente específico:

1. Crear o seleccionar la base de datos de destino en MariaDB:
   CREATE DATABASE IF NOT EXISTS `fugzcdpo_nombre_cliente` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

2. Importar el archivo SQL correspondiente mediante la herramienta cliente:
   mariadb -u <USUARIO> -p <NOMBRE_BASE> < databases/<NOMBRE_BASE>.sql
   (o mysql -u <USUARIO> -p <NOMBRE_BASE> < databases/<NOMBRE_BASE>.sql)

3. Verificar la integridad de las tablas y registros restaurados.

Nota de Seguridad:
Este archivo de backup no contiene contraseñas ni claves de acceso. Conserve
este archivo en un almacenamiento seguro y restringido.
================================================================================
TXT;
    }

    /**
     * Limpia recursivamente un directorio temporal.
     */
    public static function cleanupDir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dir);
    }

    /**
     * Formatea un número de bytes en unidades legibles (B, KB, MB, GB).
     */
    public static function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Obtiene la conexión PDO usando la configuración existente sin modificar nada.
     */
    private static function getConnection(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = self::getConfig();
        $dbConfig = $config['destino'] ?? $config['database'] ?? [];

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $charset = $dbConfig['charset'] ?? 'utf8mb4';
        $adminUser = !empty($dbConfig['admin_user']) ? $dbConfig['admin_user'] : ($dbConfig['user'] ?? 'root');
        $adminPass = isset($dbConfig['admin_password']) ? $dbConfig['admin_password'] : ($dbConfig['password'] ?? '');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $dsn = "mysql:host=$host;charset=$charset";
            self::$pdo = new PDO($dsn, $adminUser, $adminPass, $options);
            return self::$pdo;
        } catch (PDOException $e) {
            $mainDb = ($dbConfig['prefix'] ?? '') . ($dbConfig['name'] ?? 'snu');
            $dsn = "mysql:host=$host;dbname=$mainDb;charset=$charset";
            self::$pdo = new PDO($dsn, $adminUser, $adminPass, $options);
            return self::$pdo;
        }
    }

    /**
     * Carga el archivo central de configuración.
     */
    private static function getConfig(): array {
        $configPath = dirname(__DIR__) . '/config/config.php';
        if (!file_exists($configPath)) {
            throw new RuntimeException("Archivo de configuración no encontrado en: $configPath");
        }
        return require $configPath;
    }

    /**
     * Registra logs sin exponer contraseñas ni datos sensibles.
     */
    public static function log(string $message, string $level = 'info'): void {
        $sanitized = preg_replace('/(password|pass|identified by)\s*[:=]?\s*[\'"][^\'"]+[\'"]/i', '$1: [PROTECTED]', $message);
        $logLine = sprintf("[%s] [Backup] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $sanitized);

        error_log($logLine);

        try {
            $dirs = self::ensureDirectories();
            $logFile = $dirs['logs'] . '/backup.log';
            @file_put_contents($logFile, $logLine, FILE_APPEND);
        } catch (Exception $ignore) {}
    }
}
