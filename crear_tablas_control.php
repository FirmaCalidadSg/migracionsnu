<?php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/modelos/Database.php';

echo "Iniciando creación de tablas de control en el servidor de destino...\n\n";

try {
    $db = Database::getDestinoConnection();
    
    // Desactivar temporalmente foreign key checks para la creación segura
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    // 1. Tabla sync_jobs
    echo "Creando tabla `sync_jobs`... ";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `sync_jobs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `cliente_id` INT NOT NULL,
            `schema_name` VARCHAR(100) NOT NULL,
            `estado` ENUM('pendiente', 'en_progreso', 'completado', 'fallido', 'pausado') DEFAULT 'pendiente',
            `fecha_inicio` DATETIME NOT NULL,
            `fecha_fin` DATETIME NULL,
            `total_tablas` INT DEFAULT 0,
            `tablas_completadas` INT DEFAULT 0,
            `error_mensaje` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
    ");
    echo "¡OK!\n";

    // 2. Tabla sync_progress
    echo "Creando tabla `sync_progress`... ";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `sync_progress` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `job_id` INT NOT NULL,
            `tabla_nombre` VARCHAR(100) NOT NULL,
            `total_registros_origen` INT DEFAULT 0,
            `registros_migrados` INT DEFAULT 0,
            `ultimo_offset` INT DEFAULT 0,
            `estado` ENUM('pendiente', 'en_progreso', 'completado', 'fallido') DEFAULT 'pendiente',
            `fecha_inicio` DATETIME NULL,
            `fecha_fin` DATETIME NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`job_id`) REFERENCES `sync_jobs`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
    ");
    echo "¡OK!\n";

    // 3. Tabla sync_logs
    echo "Creando tabla `sync_logs`... ";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `sync_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `job_id` INT NULL,
            `nivel` ENUM('info', 'advertencia', 'error') NOT NULL DEFAULT 'info',
            `cliente` VARCHAR(150) NOT NULL,
            `schema_name` VARCHAR(100) NOT NULL,
            `tabla_nombre` VARCHAR(100) NULL,
            `mensaje` TEXT NOT NULL,
            `detalles_tecnicos` LONGTEXT NULL,
            `fecha_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`job_id`) REFERENCES `sync_jobs`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
    ");
    echo "¡OK!\n";

    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "\n¡Tablas de control creadas con éxito en el destino!\n";
    echo "Ya puedes borrar este archivo de tu servidor por seguridad.";
    
} catch (Exception $e) {
    try { $db->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch(Exception $ex) {}
    echo "ERROR CRÍTICO: " . $e->getMessage() . "\n";
}
