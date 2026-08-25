<?php

declare(strict_types=1);

// Script CLI de ejecución en segundo plano para el proceso de Backup
if (php_sapi_name() !== 'cli' && !defined('BACKUP_WORKER_ALLOW_HTTP')) {
    // Si se accede via web directamente, verificar que sea llamada interna controlada
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['migrador_user'])) {
        header('HTTP/1.0 403 Forbidden');
        echo "Acceso denegado.";
        exit;
    }
}

// Desactivar límites de tiempo para backup
set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

require_once dirname(__DIR__) . '/modelos/BackupService.php';

try {
    BackupService::runBackup();
} catch (Throwable $e) {
    BackupService::log("Error no capturado en worker de backup: " . $e->getMessage(), 'error');
}
