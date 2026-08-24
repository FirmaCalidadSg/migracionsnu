<?php

declare(strict_types=1);

/**
 * Servicio de Aprovisionamiento e Integración de Bases de Datos MariaDB y Virtualmin.
 * 
 * Gestiona el ciclo de vida completo de creación física de esquemas, usuarios,
 * permisos granulares y asociación formal con el Virtual Server de Virtualmin.
 */
class DatabaseProvisioningService {

    // Constantes de estado
    public const STATE_DATABASE_CREATED = 'DATABASE_CREATED';
    public const STATE_DATABASE_ALREADY_EXISTS = 'DATABASE_ALREADY_EXISTS';
    public const STATE_USER_CREATED = 'USER_CREATED';
    public const STATE_USER_ALREADY_EXISTS = 'USER_ALREADY_EXISTS';
    public const STATE_PRIVILEGES_GRANTED = 'PRIVILEGES_GRANTED';
    public const STATE_VIRTUALMIN_ASSOCIATED = 'VIRTUALMIN_ASSOCIATED';
    public const STATE_VIRTUALMIN_ALREADY_ASSOCIATED = 'VIRTUALMIN_ALREADY_ASSOCIATED';
    public const STATE_COMPLETED = 'COMPLETED';
    public const STATE_VIRTUALMIN_ASSOCIATION_FAILED = 'VIRTUALMIN_ASSOCIATION_FAILED';
    public const STATE_ERROR = 'ERROR';

    private static ?PDO $adminPdo = null;

    /**
     * Valida estrictamente un identificador SQL (base de datos o usuario)
     * para prevenir cualquier tipo de inyección SQL o command injection.
     */
    public static function validateIdentifier(string $identifier, string $type = 'database'): void {
        $identifier = trim($identifier);
        if (empty($identifier)) {
            throw new InvalidArgumentException("El nombre de $type no puede estar vacío.");
        }
        if (strlen($identifier) > 64) {
            throw new InvalidArgumentException("El nombre de $type excede la longitud máxima de 64 caracteres.");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException(
                "El nombre de $type '$identifier' contiene caracteres no permitidos. Solo se permiten letras, números y guiones bajos."
            );
        }
    }

    /**
     * Obtiene la conexión administrativa PDO a MariaDB usando la configuración existente.
     */
    public static function getAdminConnection(): PDO {
        if (self::$adminPdo !== null) {
            return self::$adminPdo;
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
            self::$adminPdo = new PDO($dsn, $adminUser, $adminPass, $options);
            return self::$adminPdo;
        } catch (PDOException $e) {
            // Intentar conectar apuntando a una base existente si no permite conexión global
            $mainDb = ($dbConfig['prefix'] ?? '') . ($dbConfig['name'] ?? 'snu');
            try {
                $dsn = "mysql:host=$host;dbname=$mainDb;charset=$charset";
                self::$adminPdo = new PDO($dsn, $adminUser, $adminPass, $options);
                return self::$adminPdo;
            } catch (PDOException $ex2) {
                self::log("Error al conectar a MariaDB con credenciales administrativas: " . $e->getMessage(), 'error');
                throw new RuntimeException("No se pudo establecer conexión administrativa con el servidor MariaDB.");
            }
        }
    }

    /**
     * Comprueba si una base de datos existe físicamente en MariaDB.
     */
    public static function databaseExists(string $dbName): bool {
        self::validateIdentifier($dbName, 'database');
        $pdo = self::getAdminConnection();

        try {
            $stmt = $pdo->prepare("
                SELECT SCHEMA_NAME 
                FROM INFORMATION_SCHEMA.SCHEMATA 
                WHERE LOWER(SCHEMA_NAME) = LOWER(:dbname)
            ");
            $stmt->execute(['dbname' => $dbName]);
            return (bool)$stmt->fetchColumn();
        } catch (Exception $e) {
            // Fallback usando SHOW DATABASES con escape seguro
            $escaped = addcslashes($dbName, '%_');
            $stmt = $pdo->prepare("SHOW DATABASES LIKE :dbname");
            $stmt->execute(['dbname' => $escaped]);
            return (bool)$stmt->fetchColumn();
        }
    }

    /**
     * Crea la base de datos física en MariaDB si no existe (idempotente).
     * 
     * @return array{created: bool, status: string, message: string}
     */
    public static function createDatabase(string $dbName, string $charset = 'utf8mb4', string $collate = 'utf8mb4_unicode_ci'): array {
        self::validateIdentifier($dbName, 'database');

        if (self::databaseExists($dbName)) {
            self::log("La base de datos '$dbName' ya existe físicamente.", 'info');
            return [
                'created' => false,
                'status' => self::STATE_DATABASE_ALREADY_EXISTS,
                'message' => "La base de datos '$dbName' ya existe."
            ];
        }

        self::log("Creando base de datos '$dbName' en MariaDB...", 'info');
        $pdo = self::getAdminConnection();

        // Validar charset y collate contra lista segura
        $allowedCharsets = ['utf8mb4', 'utf8', 'latin1'];
        $allowedCollates = ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_spanish_ci', 'utf8_general_ci', 'utf8_unicode_ci'];
        
        $safeCharset = in_array($charset, $allowedCharsets, true) ? $charset : 'utf8mb4';
        $safeCollate = in_array($collate, $allowedCollates, true) ? $collate : 'utf8mb4_unicode_ci';

        $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET $safeCharset COLLATE $safeCollate;");

        // Verificar creación
        if (!self::databaseExists($dbName)) {
            throw new RuntimeException("Error: La base de datos '$dbName' no pudo ser verificada tras la ejecución de CREATE DATABASE.");
        }

        self::log("Base de datos '$dbName' creada exitosamente.", 'info');
        return [
            'created' => true,
            'status' => self::STATE_DATABASE_CREATED,
            'message' => "Base de datos '$dbName' creada exitosamente."
        ];
    }

    /**
     * Crea un usuario de MariaDB si no existe.
     * 
     * @return array{created: bool, status: string, message: string}
     */
    public static function createDatabaseUser(string $username, string $password, string $host = 'localhost'): array {
        self::validateIdentifier($username, 'usuario');
        $safeHost = ($host === '127.0.0.1') ? '127.0.0.1' : 'localhost';

        $pdo = self::getAdminConnection();

        // Verificar si el usuario ya existe
        $stmt = $pdo->prepare("SELECT 1 FROM mysql.user WHERE User = :user AND Host = :host");
        $stmt->execute(['user' => $username, 'host' => $safeHost]);
        $exists = (bool)$stmt->fetchColumn();

        if ($exists) {
            self::log("El usuario de base de datos '$username'@'$safeHost' ya existe.", 'info');
            return [
                'created' => false,
                'status' => self::STATE_USER_ALREADY_EXISTS,
                'message' => "El usuario '$username'@'$safeHost' ya existe."
            ];
        }

        self::log("Creando usuario de base de datos '$username'@'$safeHost'...", 'info');
        
        // Usar prepared statement / escape seguro de contraseña
        $stmtCreate = $pdo->prepare("CREATE USER :user@:host IDENTIFIED BY :pass");
        $stmtCreate->execute([
            'user' => $username,
            'host' => $safeHost,
            'pass' => $password
        ]);

        return [
            'created' => true,
            'status' => self::STATE_USER_CREATED,
            'message' => "Usuario '$username'@'$safeHost' creado exitosamente."
        ];
    }

    /**
     * Otorga privilegios al usuario ÚNICAMENTE sobre su base de datos correspondiente.
     * 
     * @return array{granted: bool, status: string, message: string}
     */
    public static function grantDatabasePrivileges(string $dbName, string $username, string $host = 'localhost'): array {
        self::validateIdentifier($dbName, 'database');
        self::validateIdentifier($username, 'usuario');
        $safeHost = ($host === '127.0.0.1') ? '127.0.0.1' : 'localhost';

        $pdo = self::getAdminConnection();

        self::log("Asignando privilegios sobre `$dbName` al usuario '$username'@'$safeHost'...", 'info');

        // Otorgar permisos sobre la base de datos específica
        $pdo->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$username'@'$safeHost';");
        
        // Si el host es localhost, asegurar también para 127.0.0.1 si el usuario existe para ambas conexiones
        if ($safeHost === 'localhost') {
            try {
                $pdo->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$username'@'127.0.0.1';");
            } catch (Exception $e) {
                // Ignorar si el usuario no existe en 127.0.0.1
            }
        }

        try {
            $pdo->exec("FLUSH PRIVILEGES;");
        } catch (Exception $e) {
            // Ignorar advertencias menores en FLUSH PRIVILEGES
        }

        self::log("Privilegios otorgados exitosamente para `$dbName`.", 'info');
        return [
            'granted' => true,
            'status' => self::STATE_PRIVILEGES_GRANTED,
            'message' => "Privilegios otorgados sobre '$dbName' para '$username'."
        ];
    }

    /**
     * Obtiene el listado de bases de datos de clientes existentes físicamente en MariaDB (fugzcdpo_*).
     * Excluye expresamente bases del sistema o de infraestructura.
     * 
     * @return string[] Lista ordenada de nombres de bases de datos de clientes
     */
    public static function getMariaDbClientDatabases(): array {
        $config = self::getConfig();
        $prefix = $config['destino']['prefix'] ?? $config['database']['prefix'] ?? 'fugzcdpo_';
        $pdo = self::getAdminConnection();

        $databases = [];
        try {
            $stmt = $pdo->prepare("
                SELECT LOWER(SCHEMA_NAME) AS db_name 
                FROM INFORMATION_SCHEMA.SCHEMATA 
                WHERE LOWER(SCHEMA_NAME) LIKE :prefix
                ORDER BY SCHEMA_NAME ASC
            ");
            $stmt->execute(['prefix' => strtolower($prefix) . '%']);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'snuquality', 'snuqualityapp', 'snuqualityapp_wordpress'];
            
            foreach ($rows as $row) {
                $db = strtolower(trim($row));
                if (!in_array($db, $systemDbs, true) && preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
                    $databases[] = $db;
                }
            }
        } catch (Exception $e) {
            self::log("Error al consultar bases de MariaDB: " . $e->getMessage(), 'error');
            // Fallback usando SHOW DATABASES
            try {
                $stmt = $pdo->query("SHOW DATABASES LIKE '" . addcslashes($prefix, '%_') . "%'");
                $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $row) {
                    $db = strtolower(trim($row));
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $db)) {
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
     * Ejecuta y obtiene el listado detallado de bases de datos asociadas a un dominio en Virtualmin.
     * 
     * @return array{
     *   success: bool,
     *   databases: string[],
     *   command: string,
     *   exit_code: int,
     *   stdout: string,
     *   stderr: string,
     *   error?: string
     * }
     */
    public static function getVirtualminDatabasesDetailed(?string $domain = null): array {
        $config = self::getConfig();
        $vConfig = $config['destino']['virtualmin'] ?? $config['virtualmin'] ?? [];
        $targetDomain = $domain ?: ($vConfig['domain'] ?? 'snuquality.tech');
        $virtualminCmd = $vConfig['command'] ?? 'sudo /usr/sbin/virtualmin';

        if (!preg_match('/^[a-zA-Z0-9\.\-]+$/', $targetDomain)) {
            throw new InvalidArgumentException("Nombre de dominio inválido para Virtualmin: $targetDomain");
        }

        $cmd = $virtualminCmd . ' list-databases --domain ' . escapeshellarg($targetDomain) . ' --name-only';

        $descriptors = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } else {
            // Fallback con exec
            $output = [];
            exec($cmd . ' 2>&1', $output, $exitCode);
            $stdout = implode("\n", $output);
        }

        $databases = [];
        $success = ($exitCode === 0);

        if ($success) {
            $lines = explode("\n", $stdout);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!empty($trimmed) && !preg_match('/^(Warning|Error|sudo:|\[sudo\])/i', $trimmed)) {
                    $databases[] = strtolower($trimmed);
                }
            }
            $databases = array_values(array_unique($databases));
        } else {
            $errDetail = trim($stderr ?: $stdout);
            self::log("Virtualmin list-databases devolvió código $exitCode. Detalle: $errDetail", 'warning');
        }

        return [
            'success' => $success,
            'databases' => $databases,
            'command' => $cmd,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'error' => !$success ? ($stderr ?: $stdout) : null
        ];
    }

    /**
     * Obtiene el listado simple de bases de datos asociadas a un dominio en Virtualmin.
     * 
     * @return string[] Lista de nombres de bases de datos registradas en Virtualmin
     */
    public static function getVirtualminDatabases(?string $domain = null): array {
        $detailed = self::getVirtualminDatabasesDetailed($domain);
        return $detailed['databases'];
    }

    /**
     * Compara los catálogos de bases de datos entre MariaDB (A) y Virtualmin (B).
     * Calcula pendientes = A - B y ya sincronizadas = A ∩ B.
     * 
     * @return array{
     *   mariadb_databases: string[],
     *   virtualmin_databases: string[],
     *   already_synced: string[],
     *   pending: string[],
     *   total_mariadb: int,
     *   total_virtualmin: int,
     *   total_pending: int,
     *   total_already_synced: int,
     *   virtualmin_diagnostics: array
     * }
     */
    public static function compareDatabaseCatalogs(?string $domain = null): array {
        $mariadbDbs = self::getMariaDbClientDatabases();
        $vDetailed = self::getVirtualminDatabasesDetailed($domain);
        $virtualminDbs = $vDetailed['databases'];

        $alreadySynced = array_values(array_intersect($mariadbDbs, $virtualminDbs));
        $pending = array_values(array_diff($mariadbDbs, $virtualminDbs));

        return [
            'mariadb_databases' => $mariadbDbs,
            'virtualmin_databases' => $virtualminDbs,
            'already_synced' => $alreadySynced,
            'pending' => $pending,
            'total_mariadb' => count($mariadbDbs),
            'total_virtualmin' => count($virtualminDbs),
            'total_pending' => count($pending),
            'total_already_synced' => count($alreadySynced),
            'virtualmin_diagnostics' => $vDetailed
        ];
    }

    /**
     * Verifica si una base de datos ya está asociada dentro de Virtualmin para el dominio.
     */
    public static function verifyVirtualminAssociation(string $dbName, ?string $domain = null): bool {
        self::validateIdentifier($dbName, 'database');
        $associatedList = self::getVirtualminDatabases($domain);
        return in_array(strtolower($dbName), $associatedList, true);
    }

    /**
     * Alias de verifyVirtualminAssociation para compatibilidad previa.
     */
    public static function isAssociatedWithVirtualmin(string $dbName, ?string $domain = null): bool {
        return self::verifyVirtualminAssociation($dbName, $domain);
    }

    /**
     * Asocia una base de datos MariaDB existente al Virtual Server de Virtualmin mediante import-database.
     * NO crea la base, NO la elimina, NO modifica sus datos.
     * 
     * @return array{
     *   success: bool,
     *   associated: bool,
     *   database: string,
     *   status: string,
     *   message: string,
     *   exit_code: int,
     *   command: string,
     *   stdout: string,
     *   stderr: string,
     *   error?: string
     * }
     */
    public static function associateDatabaseWithVirtualmin(string $dbName, ?string $domain = null): array {
        self::validateIdentifier($dbName, 'database');

        $config = self::getConfig();
        $vConfig = $config['destino']['virtualmin'] ?? $config['virtualmin'] ?? [];
        
        if (isset($vConfig['enabled']) && $vConfig['enabled'] === false) {
            return [
                'success' => true,
                'associated' => true,
                'database' => $dbName,
                'status' => self::STATE_VIRTUALMIN_ALREADY_ASSOCIATED,
                'message' => 'Integración con Virtualmin deshabilitada por configuración.',
                'exit_code' => 0,
                'command' => '',
                'stdout' => '',
                'stderr' => ''
            ];
        }

        $targetDomain = $domain ?: ($vConfig['domain'] ?? 'snuquality.tech');
        $virtualminCmd = $vConfig['command'] ?? 'sudo /usr/sbin/virtualmin';

        if (!preg_match('/^[a-zA-Z0-9\.\-]+$/', $targetDomain)) {
            throw new InvalidArgumentException("Nombre de dominio inválido para Virtualmin: $targetDomain");
        }

        // 1. Validar si ya está asociada
        if (self::verifyVirtualminAssociation($dbName, $targetDomain)) {
            self::log("[SyncCatalog] Base '$dbName' ya se encuentra asociada en Virtualmin ($targetDomain).", 'info');
            return [
                'success' => true,
                'associated' => true,
                'database' => $dbName,
                'status' => self::STATE_VIRTUALMIN_ALREADY_ASSOCIATED,
                'message' => "La base de datos '$dbName' ya está asociada a Virtualmin.",
                'exit_code' => 0,
                'command' => '',
                'stdout' => '',
                'stderr' => ''
            ];
        }

        // 2. Ejecutar import-database en Virtualmin
        self::log("[SyncCatalog] Associating $dbName with Virtualmin ($targetDomain)...", 'info');

        $cmd = $virtualminCmd . ' import-database'
            . ' --domain ' . escapeshellarg($targetDomain)
            . ' --name ' . escapeshellarg($dbName)
            . ' --type mysql';

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        $stdout = '';
        $stderr = '';
        $exitCode = -1;

        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } else {
            $output = [];
            exec($cmd . ' 2>&1', $output, $exitCode);
            $stdout = implode("\n", $output);
        }

        $associated = ($exitCode === 0) || self::verifyVirtualminAssociation($dbName, $targetDomain);

        if ($associated) {
            self::log("[SyncCatalog] Association successful for $dbName", 'info');
            return [
                'success' => true,
                'associated' => true,
                'database' => $dbName,
                'status' => self::STATE_VIRTUALMIN_ASSOCIATED,
                'message' => "Base de datos '$dbName' asociada exitosamente a Virtualmin.",
                'exit_code' => $exitCode,
                'command' => $cmd,
                'stdout' => $stdout,
                'stderr' => $stderr
            ];
        }

        // Error al asociar
        $errDetail = trim($stderr ?: $stdout);
        self::log("[SyncCatalog] Error al asociar $dbName (código $exitCode): $errDetail", 'error');

        return [
            'success' => false,
            'associated' => false,
            'database' => $dbName,
            'status' => self::STATE_VIRTUALMIN_ASSOCIATION_FAILED,
            'message' => "No fue posible asociar la base de datos '$dbName' con Virtualmin.",
            'exit_code' => $exitCode,
            'command' => $cmd,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'error' => $errDetail
        ];
    }

    /**
     * Alias de associateDatabaseWithVirtualmin para compatibilidad.
     */
    public static function associateWithVirtualmin(string $dbName, ?string $domain = null): array {
        return self::associateDatabaseWithVirtualmin($dbName, $domain);
    }

    /**
     * Sincroniza el catálogo de bases de datos existentes en MariaDB con Virtualmin (NO crea bases).
     * 
     * Flujo:
     * 1. Detecta bases existentes en MariaDB (fugzcdpo_*).
     * 2. Detecta bases asociadas en Virtualmin.
     * 3. Compara y obtiene la lista de bases pendientes.
     * 4. Asocia cada base pendiente a Virtualmin (import-database).
     * 5. Asegura privilegios exclusivos para el usuario (fugzcdpo_snu) sobre cada base existente.
     * 6. Realiza verificación final consultando nuevamente a Virtualmin.
     * 7. Devuelve un resultado estructurado con el balance final.
     * 
     * @param string|null $domain Dominio del Virtual Server (por defecto snuquality.tech)
     * @param string|null $targetUser Usuario para verificar permisos (por defecto fugzcdpo_snu)
     * @return array
     */
    public static function syncDatabaseCatalog(?string $domain = null, ?string $targetUser = 'fugzcdpo_snu'): array {
        $config = self::getConfig();
        $targetDomain = $domain ?: ($config['destino']['virtualmin']['domain'] ?? 'snuquality.tech');
        $user = $targetUser ?: ($config['destino']['user'] ?? 'fugzcdpo_snu');

        self::log("[SyncCatalog] Starting synchronization for domain '$targetDomain' (User: '$user')", 'info');

        // PASO 1: Obtener bases existentes en MariaDB (NO CREAR BASES)
        $mariadbDbs = self::getMariaDbClientDatabases();
        $totalMariaDb = count($mariadbDbs);
        self::log("[SyncCatalog] MariaDB databases detected: $totalMariaDb", 'info');

        // PASO 2: Obtener bases asociadas en Virtualmin
        $vInitial = self::getVirtualminDatabasesDetailed($targetDomain);
        $virtualminInitialDbs = $vInitial['databases'];
        $totalVirtualminInitial = count($virtualminInitialDbs);
        self::log("[SyncCatalog] Virtualmin databases detected: $totalVirtualminInitial", 'info');

        // PASO 3: Comparar catálogos
        $alreadySynced = array_values(array_intersect($mariadbDbs, $virtualminInitialDbs));
        $pendingToAssociate = array_values(array_diff($mariadbDbs, $virtualminInitialDbs));
        $totalPendingInitial = count($pendingToAssociate);
        self::log("[SyncCatalog] Databases pending association: $totalPendingInitial", 'info');

        $associatedList = [];
        $failedList = [];
        $privilegesCount = 0;

        // PASO 4 & 5: Procesar bases pendientes de asociación en Virtualmin
        foreach ($pendingToAssociate as $dbName) {
            $assocRes = self::associateDatabaseWithVirtualmin($dbName, $targetDomain);
            if ($assocRes['associated']) {
                $associatedList[] = $dbName;
            } else {
                $failedList[] = [
                    'database' => $dbName,
                    'exit_code' => $assocRes['exit_code'],
                    'error' => $assocRes['error'] ?? $assocRes['stderr'] ?? $assocRes['stdout'],
                    'command' => $assocRes['command']
                ];
            }

            // PASO 7: Asegurar permisos exclusivos sobre la base existente para fugzcdpo_snu
            try {
                self::grantDatabasePrivileges($dbName, $user, 'localhost');
                $privilegesCount++;
            } catch (Exception $e) {
                self::log("Aviso al otorgar permisos en '$dbName': " . $e->getMessage(), 'warning');
            }
        }

        // Asegurar permisos también para las bases que ya estaban asociadas
        foreach ($alreadySynced as $dbName) {
            try {
                self::grantDatabasePrivileges($dbName, $user, 'localhost');
                $privilegesCount++;
            } catch (Exception $e) {}
        }

        try {
            $adminPdo = self::getAdminConnection();
            $adminPdo->exec("FLUSH PRIVILEGES;");
        } catch (Exception $ignore) {}

        // PASO 6: Verificación Final
        self::log("[SyncCatalog] Final verification...", 'info');
        $vFinal = self::getVirtualminDatabasesDetailed($targetDomain);
        $virtualminFinalDbs = $vFinal['databases'];
        $remainingPending = array_values(array_diff($mariadbDbs, $virtualminFinalDbs));
        $totalRemainingPending = count($remainingPending);

        self::log("[SyncCatalog] MariaDB: $totalMariaDb", 'info');
        self::log("[SyncCatalog] Virtualmin: " . count($virtualminFinalDbs), 'info');
        self::log("[SyncCatalog] Pending: $totalRemainingPending", 'info');

        $isComplete = ($totalRemainingPending === 0 && count($failedList) === 0);
        $status = $isComplete ? 'completed' : (count($associatedList) > 0 ? 'incomplete' : 'failed');

        if ($isComplete) {
            self::log("[SyncCatalog] Synchronization completed successfully", 'info');
            $msg = "Sincronización completada. Total MariaDB: $totalMariaDb, Ya asociadas: " . count($alreadySynced) . ", Asociadas ahora: " . count($associatedList) . ", Pendientes: 0.";
        } else {
            self::log("[SyncCatalog] Synchronization incomplete. Pending: $totalRemainingPending, Failed: " . count($failedList), 'warning');
            $msg = "Sincronización incompleta. Total MariaDB: $totalMariaDb, Asociadas ahora: " . count($associatedList) . ", Pendientes restantes: $totalRemainingPending, Errores: " . count($failedList) . ".";
        }

        return [
            'success' => $isComplete,
            'total_mariadb' => $totalMariaDb,
            'total_virtualmin' => count($virtualminFinalDbs),
            'already_synced' => count($alreadySynced),
            'associated' => count($associatedList),
            'failed' => count($failedList),
            'pending' => $totalRemainingPending,
            'status' => $status,
            'message' => $msg,
            'bases_mariadb' => $mariadbDbs,
            'bases_virtualmin' => $virtualminFinalDbs,
            'bases_ya_asociadas' => $alreadySynced,
            'bases_asociadas_ahora' => $associatedList,
            'bases_fallidas' => $failedList,
            'bases_pendientes' => $remainingPending,
            'virtualmin_diagnostics' => [
                'command' => $vFinal['command'],
                'exit_code' => $vFinal['exit_code'],
                'error' => $vFinal['error'],
                'causa_probable' => ($vFinal['exit_code'] !== 0) ? 'Falta regla en /etc/sudoers.d/virtualmin-snuquality para ejecutar /usr/sbin/virtualmin sin contraseña.' : null
            ]
        ];
    }

    /**
     * Flujo maestro de aprovisionamiento integral de bases de datos.
     * 
     * Ejecuta secuencialmente:
     * 1. Creación/Verificación física de MariaDB
     * 2. Creación/Verificación de usuario (si se especifica o utiliza el de destino)
     * 3. Asignación de permisos exclusivos
     * 4. Verificación de acceso
     * 5. Asociación con Virtualmin (import-database)
     * 6. Verificación de asociación en Virtualmin
     * 
     * @param string $schema Nombre del esquema del cliente (ej. 'vitalcare' o 'empresa_demo')
     * @param string|null $customUser Usuario opcional. Si es null, utiliza el usuario normal de destino
     * @param string|null $customPass Contraseña opcional
     * @return array{
     *   success: bool,
     *   database: string,
     *   database_created: bool,
     *   user_created: bool,
     *   privileges_granted: bool,
     *   virtualmin_associated: bool,
     *   status: string,
     *   message: string,
     *   steps: array<string, mixed>
     * }
     */
    public static function provisionDatabase(
        string $schema, 
        ?string $customUser = null, 
        ?string $customPass = null
    ): array {
        $config = self::getConfig();
        $dbConfig = $config['destino'] ?? $config['database'] ?? [];
        $prefix = $dbConfig['prefix'] ?? 'fugzcdpo_';
        
        // Calcular nombre canónico de la base de datos
        $schemaClean = strtolower(trim($schema));
        $dbName = str_starts_with($schemaClean, $prefix) ? $schemaClean : $prefix . $schemaClean;
        
        self::validateIdentifier($dbName, 'database');

        $user = $customUser ?: ($dbConfig['user'] ?? 'fugzcdpo_snu');
        $pass = $customPass !== null ? $customPass : ($dbConfig['password'] ?? '');
        $host = $dbConfig['host'] ?? '127.0.0.1';
        $charset = $dbConfig['charset'] ?? 'utf8mb4';

        $steps = [];
        $dbCreated = false;
        $userCreated = false;
        $privilegesGranted = false;
        $virtualminAssociated = false;

        self::log("=== Iniciando aprovisionamiento para esquema '$schema' (`$dbName`) ===", 'info');

        try {
            // PASO 1: Crear o verificar base de datos física MariaDB
            $dbRes = self::createDatabase($dbName, $charset);
            $dbCreated = $dbRes['created'];
            $steps['database'] = $dbRes;

            // PASO 2: Crear usuario si es personalizado y no existe
            if ($customUser !== null && !empty($customPass)) {
                $userRes = self::createDatabaseUser($customUser, $customPass, 'localhost');
                $userCreated = $userRes['created'];
                $steps['user'] = $userRes;
            } else {
                $steps['user'] = [
                    'created' => false,
                    'status' => self::STATE_USER_ALREADY_EXISTS,
                    'message' => "Utilizando usuario preconfigurado '$user'."
                ];
            }

            // PASO 3: Otorgar privilegios exclusivamente sobre esta base
            $privRes = self::grantDatabasePrivileges($dbName, $user, 'localhost');
            $privilegesGranted = $privRes['granted'];
            $steps['privileges'] = $privRes;

            // PASO 4: Probar conexión con el usuario de trabajo para verificar permisos
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $dsn = "mysql:host=$host;dbname=$dbName;charset=$charset";
            try {
                new PDO($dsn, $user, $pass, $options);
                $steps['connection_verification'] = [
                    'verified' => true,
                    'message' => "Conexión verificada exitosamente para '$user' sobre '$dbName'."
                ];
            } catch (PDOException $exConn) {
                $steps['connection_verification'] = [
                    'verified' => false,
                    'message' => "Aviso en conexión de trabajo: " . $exConn->getMessage()
                ];
            }

            // PASO 5: Asociar con Virtualmin
            $virtRes = self::associateWithVirtualmin($dbName);
            $virtualminAssociated = $virtRes['associated'];
            $steps['virtualmin'] = $virtRes;

            $overallSuccess = $virtualminAssociated;
            $finalStatus = $overallSuccess ? self::STATE_COMPLETED : self::STATE_VIRTUALMIN_ASSOCIATION_FAILED;
            $finalMessage = $overallSuccess 
                ? "Base de datos '$dbName' aprovisionada y asociada a Virtualmin exitosamente."
                : "Base de datos '$dbName' verificada en MariaDB, pero requiere completar la asociación con Virtualmin.";

            self::log("Aprovisionamiento finalizado para `$dbName` con estado: $finalStatus", 'info');

            return [
                'success' => $overallSuccess,
                'database' => $dbName,
                'database_created' => $dbCreated,
                'user_created' => $userCreated,
                'privileges_granted' => $privilegesGranted,
                'virtualmin_associated' => $virtualminAssociated,
                'status' => $finalStatus,
                'message' => $finalMessage,
                'steps' => $steps
            ];

        } catch (Exception $e) {
            self::log("Error durante el aprovisionamiento de '$dbName': " . $e->getMessage(), 'error');
            return [
                'success' => false,
                'database' => $dbName,
                'database_created' => $dbCreated,
                'user_created' => $userCreated,
                'privileges_granted' => $privilegesGranted,
                'virtualmin_associated' => false,
                'status' => self::STATE_ERROR,
                'message' => "Error al aprovisionar la base de datos: " . $e->getMessage(),
                'steps' => $steps
            ];
        }
    }

    /**
     * Sincroniza permisos y asociación de Virtualmin para todas las bases de datos del catálogo
     * asignando los privilegios al usuario especificado (por defecto 'fugzcdpo_snu').
     * 
     * @param PDO $dbDestino Conexión a la base de datos de destino
     * @param string|null $targetUser Usuario de base de datos a asociar (ej. 'fugzcdpo_snu')
     * @return array{
     *   total_procesadas: int,
     *   permisos_asignados: int,
     *   virtualmin_asociadas: int,
     *   user: string,
     *   detalles: array
     * }
     */
    public static function syncCatalogPrivilegesAndVirtualmin(PDO $dbDestino, ?string $targetUser = null): array {
        $config = self::getConfig();
        $dbConfig = $config['destino'] ?? $config['database'] ?? [];
        $user = $targetUser ?: ($dbConfig['user'] ?? 'fugzcdpo_snu');
        $prefix = $dbConfig['prefix'] ?? 'fugzcdpo_';

        self::log("=== Iniciando sincronización de permisos y Virtualmin para usuario '$user' en catálogo ===", 'info');

        // 1. Obtener todos los esquemas únicos registrados en la tabla squemas de destino
        $squemas = [];
        try {
            $stmt = $dbDestino->query("SELECT DISTINCT LOWER(TRIM(squema)) AS squema FROM squemas WHERE squema IS NOT NULL AND squema != ''");
            $squemas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            self::log("Aviso al consultar tabla squemas en destino: " . $e->getMessage(), 'warning');
        }

        // 2. Obtener también todas las bases de datos físicas existentes que coincidan con el prefijo
        $adminPdo = self::getAdminConnection();
        $stmtDb = $adminPdo->query("SHOW DATABASES LIKE '" . addcslashes($prefix, '%_') . "%'");
        $existingDbs = $stmtDb->fetchAll(PDO::FETCH_COLUMN);

        // 3. Unir bases de datos del catálogo y bases físicas existentes
        $databasesToProcess = [];
        foreach ($squemas as $sq) {
            $clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $sq);
            if (!empty($clean)) {
                $dbName = str_starts_with($clean, $prefix) ? $clean : $prefix . $clean;
                $databasesToProcess[strtolower($dbName)] = true;
            }
        }
        foreach ($existingDbs as $edb) {
            $edbLower = strtolower($edb);
            if (preg_match('/^[a-zA-Z0-9_]+$/', $edbLower)) {
                $databasesToProcess[$edbLower] = true;
            }
        }

        $totalProcesadas = 0;
        $permisosAsignados = 0;
        $virtualminAsociadas = 0;
        $detalles = [];

        // Obtener listado de bases ya en Virtualmin para optimizar llamadas
        $virtualminList = self::getVirtualminDatabases();

        foreach (array_keys($databasesToProcess) as $dbName) {
            $totalProcesadas++;
            $dbInfo = ['database' => $dbName];

            // A. Asegurar que existe físicamente en MariaDB
            if (!self::databaseExists($dbName)) {
                try {
                    self::createDatabase($dbName);
                    $dbInfo['database_created'] = true;
                } catch (Exception $e) {
                    $dbInfo['database_created'] = false;
                    $dbInfo['error_db'] = $e->getMessage();
                }
            } else {
                $dbInfo['database_created'] = false;
            }

            // B. Otorgar privilegios completos al usuario sobre esta base de datos
            try {
                self::grantDatabasePrivileges($dbName, $user, 'localhost');
                $permisosAsignados++;
                $dbInfo['privileges_granted'] = true;
            } catch (Exception $e) {
                $dbInfo['privileges_granted'] = false;
                $dbInfo['error_privileges'] = $e->getMessage();
            }

            // C. Asociar a Virtualmin si no está asociada
            if (!in_array(strtolower($dbName), $virtualminList, true)) {
                $virtRes = self::associateWithVirtualmin($dbName);
                if ($virtRes['associated']) {
                    $virtualminAsociadas++;
                    $virtualminList[] = strtolower($dbName);
                }
                $dbInfo['virtualmin'] = $virtRes;
            } else {
                $dbInfo['virtualmin'] = [
                    'associated' => true,
                    'status' => self::STATE_VIRTUALMIN_ALREADY_ASSOCIATED,
                    'message' => 'Ya asociada previamente en Virtualmin.'
                ];
            }

            $detalles[] = $dbInfo;
        }

        try {
            $adminPdo->exec("FLUSH PRIVILEGES;");
        } catch (Exception $ignore) {}

        self::log("Sincronización de catálogo finalizada: $totalProcesadas bases procesadas, $permisosAsignados permisos asignados a '$user', $virtualminAsociadas asociadas a Virtualmin.", 'info');

        return [
            'total_procesadas' => $totalProcesadas,
            'permisos_asignados' => $permisosAsignados,
            'virtualmin_asociadas' => $virtualminAsociadas,
            'user' => $user,
            'detalles' => $detalles
        ];
    }

    /**
     * Carga el archivo de configuración central.
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
    private static function log(string $message, string $level = 'info'): void {
        // Sanitizar cualquier posible contraseña antes de registrar
        $sanitized = preg_replace('/(password|pass|identified by)\s*[:=]?\s*[\'"][^\'"]+[\'"]/i', '$1: [PROTECTED]', $message);
        
        $logLine = sprintf("[%s] [DatabaseProvisioning] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $sanitized);
        
        // Registrar en error_log de PHP y opcionalmente en archivo de log
        error_log($logLine);

        // Si existe el modelo de SyncModel, registrar en logs del sistema
        if (class_exists('SyncModel', false)) {
            try {
                SyncModel::log($level, 'SISTEMA', 'virtualmin', $sanitized);
            } catch (Exception $ignore) {}
        }
    }
}
