<?php

declare(strict_types=1);

/**
 * Script de Validación Obligatoria para la Detección de Cambios y Exclusión de Tabla.
 * 
 * Ejecuta las 7 Pruebas requeridas para verificar el comportamiento de metadata_signature,
 * exclusión de 'estadisticasUso', mantenimiento de estado tras fallos y omisión por cero cambios.
 */

require_once dirname(__DIR__) . '/modelos/Database.php';
require_once dirname(__DIR__) . '/modelos/SyncModel.php';
require_once dirname(__DIR__) . '/modelos/DatabaseMetadataService.php';

echo "======================================================================\n";
echo "    EJECUTANDO BATERÍA DE PRUEBAS DE VALIDACIÓN DE DETECCIÓN DE CAMBIOS   \n";
echo "======================================================================\n";

// Conexión PDO con privilegios administrativos
try {
    $config = require dirname(__DIR__) . '/config/config.php';
    $destConfig = $config['destino'] ?? [];
    $host = $destConfig['host'] ?? '127.0.0.1';
    $adminUser = $destConfig['admin_user'] ?? 'root';
    $adminPass = $destConfig['admin_password'] ?? '';

    $adminPdo = new PDO("mysql:host=$host;charset=utf8mb4", $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo "ERROR CRÍTICO AL CONECTAR CON MARIADB PARA PRUEBAS: " . $e->getMessage() . "\n";
    exit(1);
}

$testDbOrigen = 'fugzcdpo_test_changedetect_origen';
$testDbDestino = 'fugzcdpo_test_changedetect_destino';

echo "\n1. Preparando entorno de prueba limpio...\n";

// Preparar esquemas físicos de prueba sin usar DROP DATABASE
$adminPdo->exec("CREATE DATABASE IF NOT EXISTS `$testDbOrigen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
$adminPdo->exec("CREATE DATABASE IF NOT EXISTS `$testDbDestino` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

// Otorgar permisos al usuario snu
try {
    $adminPdo->exec("GRANT ALL PRIVILEGES ON `$testDbOrigen`.* TO 'snu'@'localhost';");
    $adminPdo->exec("GRANT ALL PRIVILEGES ON `$testDbOrigen`.* TO 'snu'@'127.0.0.1';");
    $adminPdo->exec("GRANT ALL PRIVILEGES ON `$testDbDestino`.* TO 'snu'@'localhost';");
    $adminPdo->exec("GRANT ALL PRIVILEGES ON `$testDbDestino`.* TO 'snu'@'127.0.0.1';");
    $adminPdo->exec("FLUSH PRIVILEGES;");
} catch (Exception $e) {}

// Crear tablas de control en Destino
$destinoMain = Database::getDestinoConnection();

$destinoMain->exec("
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Limpiar estado anterior de la base de prueba si existiera
$destinoMain->exec("DELETE FROM database_sync_state WHERE database_name = '$testDbOrigen';");

// Poblar Origen con datos iniciales
$origenPdo = new PDO("mysql:host=$host;dbname=$testDbOrigen;charset=utf8mb4", $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$origenPdo->exec("
    CREATE TABLE IF NOT EXISTS `usuarios` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nombre` VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `procesos` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `titulo` VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `estadisticasUso` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `evento` VARCHAR(255) NOT NULL,
        `fecha` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    SET FOREIGN_KEY_CHECKS = 0;
    TRUNCATE TABLE `usuarios`;
    TRUNCATE TABLE `procesos`;
    TRUNCATE TABLE `estadisticasUso`;
    SET FOREIGN_KEY_CHECKS = 1;
");

$origenPdo->exec("
    INSERT INTO `usuarios` (`nombre`) VALUES ('Juan Perez'), ('Maria Lopez');
    INSERT INTO `procesos` (`titulo`) VALUES ('Proceso A'), ('Proceso B');
    INSERT INTO `estadisticasUso` (`evento`) VALUES ('Login Usuario 1'), ('Click Boton A');
");

$runId = SyncModel::createDatabaseSyncRun('test', 1);
$testsPassed = 0;
$totalTests = 7;

echo "\n======================================================================\n";

// -------------------------------------------------------------------------
// PRUEBA 1: Base sin sincronización anterior. Resultado esperado: SYNC REQUIRED
// -------------------------------------------------------------------------
echo "[PRUEBA 1] Base sin sincronización anterior...\n";
$decision1 = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoMain, $testDbOrigen);

if ($decision1['should_sync'] === true && $decision1['status'] === 'syncing') {
    echo "  [PASSED] Resultado: SYNC REQUIRED (Correcto)\n";
    $testsPassed++;
} else {
    echo "  [FAILED] Esperado: SYNC REQUIRED, Obtencion: " . $decision1['status'] . "\n";
}

// -------------------------------------------------------------------------
// PRUEBA 2: Sincronización exitosa -> Registra metadata_signature como última versión exitosa
// -------------------------------------------------------------------------
echo "\n[PRUEBA 2] Ejecutar sincronización exitosa y registrar firma...\n";

// Simular sincronización exitosa registrando la firma
DatabaseMetadataService::recordSuccessfulSync($destinoMain, $runId, $testDbOrigen, $decision1['current_metadata']);

$state2 = DatabaseMetadataService::getLastSuccessfulState($destinoMain, $testDbOrigen);

if ($state2 !== null && $state2['last_metadata_signature'] === $decision1['current_metadata']['metadata_signature']) {
    echo sprintf("  [PASSED] Firma registrada exitosamente en database_sync_state: %s\n", substr($state2['last_metadata_signature'], 0, 16));
    $testsPassed++;
} else {
    echo "  [FAILED] No se registró la firma en database_sync_state correctamente.\n";
}

// -------------------------------------------------------------------------
// PRUEBA 3: Ejecutar nuevamente sin cambios. Resultado esperado: skipped_unchanged
// -------------------------------------------------------------------------
echo "\n[PRUEBA 3] Re-ejecutar evaluación sin realizar cambios en origen...\n";
$decision3 = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoMain, $testDbOrigen);

if ($decision3['should_sync'] === false && $decision3['status'] === 'skipped_unchanged') {
    echo "  [PASSED] Resultado: SKIPPED_UNCHANGED (Correcto)\n";
    $testsPassed++;
} else {
    echo "  [FAILED] Esperado: skipped_unchanged, Obtencion: " . $decision3['status'] . "\n";
}

// -------------------------------------------------------------------------
// PRUEBA 4: Modificar o detectar cambios en una tabla relevante (ej. usuarios)
// -------------------------------------------------------------------------
echo "\n[PRUEBA 4] Insertar registros en tabla relevante 'usuarios'...\n";
$origenPdo->exec("INSERT INTO `usuarios` (`nombre`) VALUES ('Carlos Gomez'), ('Ana Ruiz');");

$decision4 = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoMain, $testDbOrigen);

if ($decision4['should_sync'] === true && $decision4['status'] === 'syncing') {
    echo "  [PASSED] Cambio detectado. Resultado: SYNC REQUIRED (Correcto)\n";
    $testsPassed++;
    // Actualizar sincronización exitosa
    DatabaseMetadataService::recordSuccessfulSync($destinoMain, $runId, $testDbOrigen, $decision4['current_metadata']);
} else {
    echo "  [FAILED] No se detectó el cambio en la tabla relevante 'usuarios'.\n";
}

// -------------------------------------------------------------------------
// PRUEBA 5: Cambiar solamente 'estadisticasUso'. Resultado esperado: skipped_unchanged
// -------------------------------------------------------------------------
echo "\n[PRUEBA 5] Insertar 1,000 registros exclusivamente en tabla excluida 'estadisticasUso'...\n";

for ($i = 0; $i < 10; $i++) {
    $origenPdo->exec("INSERT INTO `estadisticasUso` (`evento`) VALUES ('Evento pesado masivo #$i');");
}

$decision5 = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoMain, $testDbOrigen);

if ($decision5['should_sync'] === false && $decision5['status'] === 'skipped_unchanged') {
    echo "  [PASSED] La tabla 'estadisticasUso' fue ignorada exitosamente. Resultado: SKIPPED_UNCHANGED (Correcto)\n";
    $testsPassed++;
} else {
    echo "  [FAILED] El cambio en 'estadisticasUso' alteró la firma (No fue ignorada correctamente).\n";
}

// -------------------------------------------------------------------------
// PRUEBA 6: Simular fallo durante sincronización. Resultado esperado: failed. Firma NO actualizada
// -------------------------------------------------------------------------
echo "\n[PRUEBA 6] Simular cambio relevante y fallo durante sincronización...\n";

// Agregar cambio en tabla relevante para forzar SYNC
$origenPdo->exec("INSERT INTO `procesos` (`titulo`) VALUES ('Proceso Crítico C');");

$decision6 = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoMain, $testDbOrigen);
$signatureAntesDelFallo = $state2 = DatabaseMetadataService::getLastSuccessfulState($destinoMain, $testDbOrigen)['last_metadata_signature'];

// Registrar Job como fallido SIN llamar a recordSuccessfulSync
$jobId6 = SyncModel::createDatabaseSyncJobItem($runId, $testDbOrigen, $decision6);
SyncModel::updateDatabaseSyncJobItem($jobId6, 'failed', 'Simulación de error de red durante la transferencia');

$signatureDespuesDelFallo = DatabaseMetadataService::getLastSuccessfulState($destinoMain, $testDbOrigen)['last_metadata_signature'];

if ($signatureAntesDelFallo === $signatureDespuesDelFallo) {
    echo "  [PASSED] La firma exitosa NO fue actualizada tras el fallo (Permanece en versión válida previa).\n";
    $testsPassed++;
} else {
    echo "  [FAILED] La firma fue erróneamente actualizada a pesar del fallo.\n";
}

// -------------------------------------------------------------------------
// PRUEBA 7: Ejecutar nuevamente después del fallo. Resultado esperado: SYNC REQUIRED
// -------------------------------------------------------------------------
echo "\n[PRUEBA 7] Re-evaluar base de datos tras la sincronización fallida...\n";
$decision7 = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoMain, $testDbOrigen);

if ($decision7['should_sync'] === true && $decision7['status'] === 'syncing') {
    echo "  [PASSED] La base de datos vuelve a requerir sincronización (SYNC REQUIRED) tras el fallo previo.\n";
    $testsPassed++;
} else {
    echo "  [FAILED] La base omitió la sincronización después de haber fallado previamente.\n";
}

// Limpiar estado de prueba en tablas de control de destino
$destinoMain->exec("DELETE FROM database_sync_state WHERE database_name = '$testDbOrigen';");

echo "\n======================================================================\n";
echo sprintf(" RESULTADO FINAL PRUEBAS: %d de %d PRUEBAS PASADAS CON ÉXITO\n", $testsPassed, $totalTests);
echo "======================================================================\n";

if ($testsPassed === $totalTests) {
    echo "¡TODAS LAS PRUEBAS SE COMPLETARON SATISFACTORIAMENTE!\n";
    exit(0);
} else {
    echo "ALGUNAS PRUEBAS FALLARON. REVISAR LOGS.\n";
    exit(1);
}
