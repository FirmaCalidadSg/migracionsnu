<?php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/modelos/Database.php';

echo "Iniciando instalación y seeding del módulo de sincronización...\n\n";

// 1. Validar que las conexiones PDO de la aplicación funcionen
try {
    echo "Probando conexión de destino con credenciales de la app... ";
    $destinoApp = Database::getDestinoConnection();
    echo "¡Conexión establecida!\n";

    echo "Probando conexión de origen con credenciales de la app... ";
    $origenApp = Database::getOrigenConnection();
    echo "¡Conexión establecida!\n\n";
} catch (Exception $e) {
    echo "ADVERTENCIA/ERROR con credenciales de la app: " . $e->getMessage() . "\n";
    echo "Asegúrate de que los usuarios y permisos se configuraron en MySQL.\n\n";
}

// 2. Establecer conexión de administrador (root)
$adminConn = null;
try {
    $adminConn = new PDO("mysql:host=localhost;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "Conexión de administrador (root) establecida con éxito.\n\n";
} catch (Exception $e) {
    echo "ERROR CRÍTICO: El instalador requiere conectarse como 'root' sin contraseña en local para crear bases de datos y tablas.\n";
    echo "Detalle del error: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Crear bases de datos principales si no existen
try {
    echo "Creando base de datos principal de ORIGEN si no existe... ";
    $adminConn->exec("CREATE DATABASE IF NOT EXISTS `fugzcdpo_snu_origen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "¡OK!\n";

    echo "Creando base de datos principal de DESTINO si no existe... ";
    $adminConn->exec("CREATE DATABASE IF NOT EXISTS `fugzcdpo_snu_destino` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "¡OK!\n\n";
} catch (PDOException $e) {
    echo "ERROR al crear bases de datos principales: " . $e->getMessage() . "\n";
    exit(1);
}

// SQL para tablas de control (en Destino)
$controlQueries = [
    "sync_jobs" => "
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
    ",
    "sync_progress" => "
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
    ",
    "sync_logs" => "
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
    ",
    "database_sync_runs" => "
        CREATE TABLE IF NOT EXISTS `database_sync_runs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `started_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            `status` ENUM('pending', 'running', 'completed', 'completed_with_errors', 'failed') DEFAULT 'pending',
            `total_databases` INT DEFAULT 0,
            `processed_databases` INT DEFAULT 0,
            `successful_databases` INT DEFAULT 0,
            `failed_databases` INT DEFAULT 0,
            `skipped_databases` INT DEFAULT 0,
            `total_duration_seconds` INT DEFAULT 0,
            `trigger_type` VARCHAR(50) DEFAULT 'scheduled',
            `pid` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
    ",
    "database_sync_jobs" => "
        CREATE TABLE IF NOT EXISTS `database_sync_jobs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `run_id` INT NOT NULL,
            `database_name` VARCHAR(100) NOT NULL,
            `status` ENUM('pending', 'checking', 'syncing', 'completed', 'failed', 'skipped_unchanged', 'skipped_excluded') DEFAULT 'pending',
            `started_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            `duration_seconds` INT DEFAULT 0,
            `source_size_bytes` BIGINT DEFAULT 0,
            `destination_size_bytes` BIGINT DEFAULT 0,
            `table_count` INT DEFAULT 0,
            `estimated_rows` BIGINT DEFAULT 0,
            `metadata_signature` VARCHAR(64) NULL,
            `previous_metadata_signature` VARCHAR(64) NULL,
            `change_detected` TINYINT(1) DEFAULT 1,
            `skip_reason` VARCHAR(255) NULL,
            `error_message` TEXT NULL,
            `attempts` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`run_id`),
            INDEX (`database_name`),
            FOREIGN KEY (`run_id`) REFERENCES `database_sync_runs`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
    ",
    "database_sync_state" => "
        CREATE TABLE IF NOT EXISTS `database_sync_state` (
            `database_name` VARCHAR(100) PRIMARY KEY,
            `last_successful_run_id` INT NULL,
            `last_successful_at` DATETIME NULL,
            `last_source_size_bytes` BIGINT DEFAULT 0,
            `last_table_count` INT DEFAULT 0,
            `last_estimated_rows` BIGINT DEFAULT 0,
            `last_metadata_signature` VARCHAR(64) NULL,
            `last_status` VARCHAR(50) DEFAULT 'completed',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
    "
];

// SQL para tablas de negocio (en Origen y Destino)
$businessQueries = [
    "clientes" => "
        CREATE TABLE IF NOT EXISTS `clientes` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `nombre` varchar(100) DEFAULT NULL,
         `direccion` varchar(500) DEFAULT NULL,
         `telefono` varchar(100) DEFAULT NULL,
         `correos` longtext DEFAULT NULL,
         `salario` varchar(20) DEFAULT '0',
         `matriz` varchar(500) DEFAULT NULL,
         `fechainicio` date NOT NULL,
         `rector` varchar(255) DEFAULT NULL COMMENT 'Escribir el nombre del rector',
         `rect_telefono` varchar(50) DEFAULT NULL COMMENT 'telefono del recto',
         `filename` varchar(1000) DEFAULT NULL,
         `dir` varchar(1000) NOT NULL,
         PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci ROW_FORMAT=COMPACT;
    ",
    "squemas" => "
        CREATE TABLE IF NOT EXISTS `squemas` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `squema` varchar(255) NOT NULL,
         `cliente_id` varchar(255) NOT NULL,
         `created` date NOT NULL,
         `modified` date NOT NULL,
         PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    "
];

// 4. Crear tablas de control en base de datos DESTINO
try {
    $adminConn->exec("USE `fugzcdpo_snu_destino`;");
    $adminConn->exec("SET FOREIGN_KEY_CHECKS = 0;");
    foreach ($controlQueries as $tableName => $sql) {
        echo "Creando tabla de control `$tableName` en destino... ";
        $adminConn->exec("DROP TABLE IF EXISTS `$tableName`;");
        $adminConn->exec($sql);
        echo "¡OK!\n";
    }
    $adminConn->exec("SET FOREIGN_KEY_CHECKS = 1;");
} catch (PDOException $e) {
    try { $adminConn->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch(Exception $ex) {}
    echo "ERROR al crear tablas de control en destino: " . $e->getMessage() . "\n";
    exit(1);
}

// 5. Crear tablas de negocio en base de datos ORIGEN
try {
    $adminConn->exec("USE `fugzcdpo_snu_origen`;");
    foreach ($businessQueries as $tableName => $sql) {
        echo "Creando tabla de negocio `$tableName` en ORIGEN... ";
        $adminConn->exec("DROP TABLE IF EXISTS `$tableName`;");
        $adminConn->exec($sql);
        echo "¡OK!\n";
    }
} catch (PDOException $e) {
    echo "ERROR al crear tablas de negocio en ORIGEN: " . $e->getMessage() . "\n";
    exit(1);
}

// 6. Crear tablas de negocio en base de datos DESTINO
try {
    $adminConn->exec("USE `fugzcdpo_snu_destino`;");
    foreach ($businessQueries as $tableName => $sql) {
        echo "Creando tabla de negocio `$tableName` en DESTINO... ";
        $adminConn->exec("DROP TABLE IF EXISTS `$tableName`;");
        $adminConn->exec($sql);
        echo "¡OK!\n";
    }
} catch (PDOException $e) {
    echo "ERROR al crear tablas de negocio en DESTINO: " . $e->getMessage() . "\n";
    exit(1);
}

// 7. Seeding de datos de negocio
echo "\nInsertando datos de prueba de clientes en base principal...\n";
try {
    // Seeding en Origen
    $adminConn->exec("USE `fugzcdpo_snu_origen`;");
    $adminConn->exec("
        INSERT INTO `clientes` (`id`, `nombre`, `direccion`, `telefono`, `fechainicio`, `dir`) VALUES
        (1, 'Empresa A', 'Calle Falsa 123', '555-0001', '2026-07-01', 'empresa_a'),
        (2, 'Empresa B', 'Calle Falsa 456', '555-0002', '2026-07-01', 'empresa_b'),
        (3, 'Empresa C', 'Calle Falsa 789', '555-0003', '2026-07-01', 'empresa_c'),
        (4, 'Empresa D', 'Calle Falsa 101', '555-0004', '2026-07-01', 'empresa_d');
    ");
    $adminConn->exec("
        INSERT INTO `squemas` (`id`, `squema`, `cliente_id`, `created`, `modified`) VALUES
        (1, 'empresa_a', '1', '2026-07-01', '2026-07-01'),
        (2, 'empresa_b', '2', '2026-07-01', '2026-07-01'),
        (3, 'empresa_c', '3', '2026-07-01', '2026-07-01'),
        (4, 'empresa_d', '4', '2026-07-01', '2026-07-01');
    ");
    echo "Seeding de tablas de negocio en ORIGEN completado.\n";

    // Seeding en Destino
    $adminConn->exec("USE `fugzcdpo_snu_destino`;");
    $adminConn->exec("
        INSERT INTO `clientes` (`id`, `nombre`, `direccion`, `telefono`, `fechainicio`, `dir`) VALUES
        (1, 'Empresa A', 'Calle Falsa 123', '555-0001', '2026-07-01', 'empresa_a'),
        (3, 'Empresa C', 'Calle Falsa 789', '555-0003', '2026-07-01', 'empresa_c'),
        (4, 'Empresa D', 'Calle Falsa 101', '555-0004', '2026-07-01', 'empresa_d');
    ");
    $adminConn->exec("
        INSERT INTO `squemas` (`id`, `squema`, `cliente_id`, `created`, `modified`) VALUES
        (1, 'empresa_a', '1', '2026-07-01', '2026-07-01'),
        (4, 'empresa_d', '4', '2026-07-01', '2026-07-01');
    ");
    echo "Seeding de tablas de negocio en DESTINO completado.\n";
} catch (PDOException $e) {
    echo "ERROR en seeding de tablas de negocio: " . $e->getMessage() . "\n";
    exit(1);
}

// 8. Crear y poblar esquemas de clientes
echo "\nCreando esquemas físicos de base de datos para simulación de clientes...\n";
$schemasToCreate = [
    'fugzcdpo_empresa_a_origen',
    'fugzcdpo_empresa_b_origen',
    'fugzcdpo_empresa_c_origen',
    'fugzcdpo_empresa_d_origen',
    'fugzcdpo_empresa_a_destino',
    'fugzcdpo_empresa_d_destino'
];

foreach ($schemasToCreate as $sName) {
    try {
        $adminConn->exec("DROP DATABASE IF EXISTS `$sName`;");
        $adminConn->exec("CREATE DATABASE `$sName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        echo "Base de datos `$sName` creada.\n";
    } catch (PDOException $e) {
        echo "ERROR al crear base de datos `$sName`: " . $e->getMessage() . "\n";
    }
}

// Re-otorgar privilegios en los nuevos esquemas
try {
    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_a_origen`.* TO 'snu'@'localhost';");
    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_a_origen`.* TO 'snu'@'::1';");
    $adminConn->exec("GRANT ALL PRIVILEGES ON `fugzcdpo_empresa_a_destino`.* TO 'snu'@'127.0.0.1';");

    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_b_origen`.* TO 'snu'@'localhost';");
    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_b_origen`.* TO 'snu'@'::1';");

    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_c_origen`.* TO 'snu'@'localhost';");
    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_c_origen`.* TO 'snu'@'::1';");

    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_d_origen`.* TO 'snu'@'localhost';");
    $adminConn->exec("GRANT SELECT, SHOW VIEW ON `fugzcdpo_empresa_d_origen`.* TO 'snu'@'::1';");
    $adminConn->exec("GRANT ALL PRIVILEGES ON `fugzcdpo_empresa_d_destino`.* TO 'snu'@'127.0.0.1';");

    $adminConn->exec("FLUSH PRIVILEGES;");
    echo "Privilegios otorgados a usuarios `snu` para las bases de datos de clientes.\n";
} catch (PDOException $e) {
    echo "ERROR al otorgar privilegios: " . $e->getMessage() . "\n";
}

// 9. Crear tablas e insertar datos de negocio en schemas de clientes
echo "\nCreando tablas e insertando registros en schemas de clientes...\n";

$tableUsuarios = "
    CREATE TABLE IF NOT EXISTS `usuarios` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nombre` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL,
        `activo` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$tableVentas = "
    CREATE TABLE IF NOT EXISTS `ventas` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `usuario_id` INT NOT NULL,
        `monto` DECIMAL(10,2) NOT NULL,
        `fecha` DATE NOT NULL,
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

// Inicializar Empresa A (Origen)
try {
    $adminConn->exec("USE `fugzcdpo_empresa_a_origen`;");
    $adminConn->exec($tableUsuarios);
    $adminConn->exec($tableVentas);

    $adminConn->exec("
        INSERT INTO `usuarios` (`id`, `nombre`, `email`, `activo`) VALUES
        (1, 'Administrador', 'admin@empresa-a.com', 1),
        (2, 'Juan Perez', 'juan@empresa-a.com', 1),
        (3, 'Maria Gomez', 'maria@empresa-a.com', 1),
        (4, 'Pedro Rodriguez', 'pedro@empresa-a.com', 0),
        (5, 'Ana Martinez', 'ana@empresa-a.com', 1);
    ");

    $adminConn->exec("
        INSERT INTO `ventas` (`id`, `usuario_id`, `monto`, `fecha`) VALUES
        (1, 2, 150.50, '2026-07-01'),
        (2, 2, 99.99, '2026-07-02'),
        (3, 3, 450.00, '2026-07-02'),
        (4, 5, 25.00, '2026-07-03'),
        (5, 5, 120.30, '2026-07-03'),
        (6, 1, 5000.00, '2026-07-04');
    ");
    echo "Estructuras y datos creados en `fugzcdpo_empresa_a_origen`.\n";
} catch (PDOException $e) {
    echo "ERROR en inicialización de `empresa_a_origen`: " . $e->getMessage() . "\n";
}

// Inicializar Empresa A (Destino - Estructura idéntica pero vacía)
try {
    $adminConn->exec("USE `fugzcdpo_empresa_a_destino`;");
    $adminConn->exec($tableUsuarios);
    $adminConn->exec($tableVentas);
    echo "Estructuras (vacías) creadas en `fugzcdpo_empresa_a_destino`.\n";
} catch (PDOException $e) {
    echo "ERROR en inicialización de `empresa_a_destino`: " . $e->getMessage() . "\n";
}

// Inicializar Empresa D (Origen - Estructura con tablas)
try {
    $adminConn->exec("USE `fugzcdpo_empresa_d_origen`;");
    $adminConn->exec($tableUsuarios);
    echo "Estructuras creadas en `fugzcdpo_empresa_d_origen`.\n";
} catch (PDOException $e) {
    echo "ERROR en inicialización de `empresa_d_origen`: " . $e->getMessage() . "\n";
}

// Inicializar Empresa D (Destino - Estructura vacía)
try {
    $adminConn->exec("USE `fugzcdpo_empresa_d_destino`;");
    $adminConn->exec($tableUsuarios);
    echo "Estructuras creadas en `fugzcdpo_empresa_d_destino`.\n";
} catch (PDOException $e) {
    echo "ERROR en inicialización de `empresa_d_destino`: " . $e->getMessage() . "\n";
}

echo "\n¡Instalación, Seeding y Entorno de Simulación creados con éxito!\n";
echo "Los datos para pruebas de dashboard son los siguientes:\n";
echo "- Empresa A: OK (Listo para Sincronizar, con datos de negocio en origen).\n";
echo "- Empresa B: Error (No existe en destino).\n";
echo "- Empresa C: Error (Schema faltante en destino).\n";
echo "- Empresa D: OK (Listo para Sincronizar, sin datos de negocio).\n";
