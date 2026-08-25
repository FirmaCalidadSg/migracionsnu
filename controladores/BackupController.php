<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelos/BackupService.php';

/**
 * Controlador Independiente para la Gestión y Descarga de Backups de Bases de Datos.
 */
class BackupController {

    /**
     * Muestra la vista principal del módulo de backups.
     */
    public function index(): void {
        $dirs = BackupService::ensureDirectories();
        $databases = BackupService::getDatabasesToBackup();
        $readyBackups = BackupService::getReadyBackups();
        $isRunning = BackupService::isBackupRunning();
        $currentState = BackupService::getJobState();

        require_once __DIR__ . '/../vistas/backup.php';
    }

    /**
     * Endpoint AJAX para consultar el estado del servicio y lista de backups.
     */
    public function getStatus(): void {
        header('Content-Type: application/json');
        try {
            $databases = BackupService::getDatabasesToBackup();
            $readyBackups = BackupService::getReadyBackups();
            $isRunning = BackupService::isBackupRunning();
            $currentState = BackupService::getJobState();
            $basePath = BackupService::getBackupBasePath();
            $freeBytes = @disk_free_space($basePath);

            echo json_encode([
                'success' => true,
                'is_running' => $isRunning,
                'current_state' => $currentState,
                'total_databases' => count($databases),
                'databases' => $databases,
                'ready_backups' => $readyBackups,
                'disk_free' => $freeBytes ? BackupService::formatBytes((int)$freeBytes) : 'N/A',
                'storage_path' => $basePath
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Endpoint AJAX para iniciar el proceso de backup.
     */
    public function start(): void {
        header('Content-Type: application/json');

        try {
            if (BackupService::isBackupRunning()) {
                echo json_encode([
                    'success' => false,
                    'is_running' => true,
                    'error' => 'Ya existe un proceso de backup en ejecución.'
                ]);
                return;
            }

            // Iniciar estado inicial
            $databases = BackupService::getDatabasesToBackup();
            $total = count($databases);
            $backupId = 'backup_' . date('Ymd_His');

            BackupService::updateJobState([
                'id' => $backupId,
                'status' => BackupService::STATE_QUEUED,
                'phase' => 'starting',
                'total' => $total,
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'current_database' => '',
                'percent' => 0,
                'message' => 'Iniciando proceso de volcado de bases de datos...'
            ]);

            // Ejecutar el proceso en segundo plano para no bloquear la petición HTTP
            $workerPath = realpath(__DIR__ . '/../bin/backup_worker.php');

            if ($workerPath && file_exists($workerPath)) {
                // Comando en segundo plano multiplataforma
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    pclose(popen("start /B php " . escapeshellarg($workerPath) . " > NUL 2>&1", "r"));
                } else {
                    exec("php " . escapeshellarg($workerPath) . " > /dev/null 2>&1 &");
                }
            } else {
                // Si por alguna razón no se localiza el worker, ejecutar inline de forma segura
                BackupService::runBackup();
            }

            echo json_encode([
                'success' => true,
                'backup_id' => $backupId,
                'status' => BackupService::STATE_QUEUED,
                'total_databases' => $total,
                'message' => 'Proceso de backup iniciado correctamente en segundo plano.'
            ]);

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'Error al iniciar backup: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Endpoint AJAX para consultar el progreso en tiempo real (Polling).
     */
    public function poll(): void {
        header('Content-Type: application/json');
        try {
            $isRunning = BackupService::isBackupRunning();
            $state = BackupService::getJobState();

            echo json_encode([
                'success' => true,
                'is_running' => $isRunning,
                'state' => $state
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Endpoint para descarga segura de un archivo ZIP de backup.
     * Requiere autenticación activa y valida estrictamente el nombre del archivo.
     */
    public function download(): void {
        // Validar autenticación
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['migrador_user'])) {
            header('HTTP/1.0 403 Forbidden');
            echo "Acceso denegado. Debe iniciar sesión.";
            exit;
        }

        $filename = $_GET['file'] ?? $_GET['id'] ?? '';
        $filename = basename(trim($filename));

        // Validación estricta contra Path Traversal y formato de nombre
        if (!preg_match('/^SNU_BACKUP_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $filename)) {
            header('HTTP/1.0 400 Bad Request');
            echo "Nombre de archivo inválido o formato no permitido.";
            exit;
        }

        $dirs = BackupService::ensureDirectories();
        $filePath = realpath($dirs['ready'] . '/' . $filename);
        $readyPath = realpath($dirs['ready']);

        // Validar que el archivo esté estrictamente dentro de ready/
        if (!$filePath || !$readyPath || strpos($filePath, $readyPath) !== 0 || !file_exists($filePath)) {
            header('HTTP/1.0 404 Not Found');
            echo "El archivo de backup solicitado no existe en el servidor.";
            exit;
        }

        // Limpiar buffers de salida previos
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Enviar encabezados HTTP para descarga forzada por stream
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Transmitir por trozos de 1MB para no sobrecargar la memoria
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 1048576);
                flush();
            }
            fclose($handle);
        } else {
            readfile($filePath);
        }
        exit;
    }

    /**
     * Endpoint AJAX para eliminar un backup disponible en ready/.
     */
    public function delete(): void {
        header('Content-Type: application/json');
        try {
            $filename = $_POST['file'] ?? $_POST['id'] ?? '';
            $filename = basename(trim($filename));

            if (!preg_match('/^SNU_BACKUP_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $filename)) {
                echo json_encode(['success' => false, 'error' => 'Nombre de archivo inválido.']);
                return;
            }

            $dirs = BackupService::ensureDirectories();
            $filePath = $dirs['ready'] . '/' . $filename;
            $metaFile = $dirs['ready'] . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.meta.json';

            if (file_exists($filePath)) {
                @unlink($filePath);
            }
            if (file_exists($metaFile)) {
                @unlink($metaFile);
            }

            BackupService::log("[Backup] Manual deletion of $filename", 'info');

            echo json_encode([
                'success' => true,
                'message' => "El archivo $filename ha sido eliminado correctamente."
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
