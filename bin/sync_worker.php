<?php

declare(strict_types=1);

/**
 * Worker CLI de Sincronización Automática con Detección de Cambios por Metadatos.
 * 
 * Evalúa las bases de datos origen y omite la sincronización si la firma (metadata_signature)
 * no ha cambiado desde la última sincronización exitosa registrada.
 * Excluye expresamente la tabla 'estadisticasUso' de la firmas y del proceso de sincronización.
 */

if (php_sapi_name() !== 'cli' && !defined('SYNC_WORKER_ALLOW_HTTP')) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['migrador_user'])) {
        header('HTTP/1.0 403 Forbidden');
        echo "Acceso denegado.";
        exit;
    }
}

set_time_limit(0);
ini_set('memory_limit', '1024M');
ignore_user_abort(true);

require_once dirname(__DIR__) . '/modelos/Database.php';
require_once dirname(__DIR__) . '/modelos/SyncModel.php';
require_once dirname(__DIR__) . '/modelos/DatabaseMetadataService.php';
require_once dirname(__DIR__) . '/modelos/BackupService.php';

function runAutomaticSync(string $triggerType = 'cron'): array {
    $startTime = microtime(true);
    echo "======================================================================\n";
    echo "  INICIANDO SINCRONIZACIÓN AUTOMÁTICA DE BASES DE DATOS (SNU QUALITY)\n";
    echo "======================================================================\n";
    echo "Fecha de Inicio: " . date('Y-m-d H:i:s') . "\n";
    echo "Tabla excluida del proceso y firmas: " . implode(', ', DatabaseMetadataService::EXCLUDED_TABLES) . "\n\n";

    try {
        $origenConnMain = Database::getOrigenConnection();
    } catch (Exception $eOrig) {
        $origenConnMain = null;
    }

    try {
        $destinoConnMain = Database::getDestinoConnection();
    } catch (Exception $eDest) {
        echo "ERROR: No se pudo conectar a la base de datos de destino: " . $eDest->getMessage() . "\n";
        return ['success' => false, 'error' => $eDest->getMessage()];
    }

    // 1. Descubrir bases de datos a procesar alineado con el Mapeo de Clientes (Fase 1)
    $databases = [];
    try {
        $clientesMap = SyncModel::getClientesMap();
        foreach ($clientesMap as $c) {
            if (empty($c['schema'])) {
                continue;
            }
            // Incluir si el estado de validación es OK o si solo requiere aprovisionamiento en destino
            if ($c['status'] === SyncModel::STATUS_OK || $c['status'] === SyncModel::STATUS_ERROR_DB_PHYSICAL_DESTINO) {
                $dbName = Database::getDatabaseName('origen', $c['schema']);
                $databases[] = trim($dbName);
            }
        }
    } catch (Exception $exMap) {}

    // Fallback: Si no se pudo obtener del mapeo de clientes, usar descubrimiento dinámico
    if (empty($databases)) {
        if ($origenConnMain) {
            try {
                $stmtDisc = $origenConnMain->query("
                    SELECT SCHEMA_NAME AS db_name 
                    FROM INFORMATION_SCHEMA.SCHEMATA 
                    WHERE LOWER(SCHEMA_NAME) LIKE 'fugzcdpo_%'
                    ORDER BY SCHEMA_NAME ASC
                ");
                $rowsDisc = $stmtDisc->fetchAll(PDO::FETCH_COLUMN);
                $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', 'snuquality', 'snuqualityapp', 'snuqualityapp_wordpress'];
                foreach ($rowsDisc as $r) {
                    $db = trim($r);
                    if (!in_array(strtolower($db), $systemDbs, true) && preg_match('/^fugzcdpo_[a-zA-Z0-9_]+$/i', $db)) {
                        $databases[] = $db;
                    }
                }
            } catch (Exception $exDisc) {}
        }
        if (empty($databases)) {
            $databases = BackupService::getDatabasesToBackup();
        }
    }

    $databases = array_values(array_unique($databases));
    $totalDatabases = count($databases);

    echo "Total de bases de datos detectadas: $totalDatabases\n\n";

    if ($totalDatabases === 0) {
        echo "No se encontraron bases de datos para sincronizar.\n";
        return ['success' => true, 'total' => 0];
    }

    // 2. Registrar inicio de ejecución global en database_sync_runs
    $runId = SyncModel::createDatabaseSyncRun($triggerType, $totalDatabases);

    $processedCount = 0;
    $successfulCount = 0;
    $failedCount = 0;
    $skippedCount = 0;

    $heaviestSynced = ['name' => null, 'size' => 0];
    $slowestSynced = ['name' => null, 'duration' => 0];
    $fastestSynced = ['name' => null, 'duration' => PHP_INT_MAX];
    $syncDurations = [];

    foreach ($databases as $index => $dbName) {
        $num = $index + 1;
        $processedCount++;
        echo sprintf("[%d/%d] Evaluando base de datos: %s...\n", $num, $totalDatabases, $dbName);

        // Obtener conexión específica a la base en Origen y Destino si existen
        try {
            $origenPdo = Database::getClienteConnection($dbName, 'origen');
        } catch (Exception $eConn) {
            echo sprintf("  -> ERROR al conectar al origen para '%s': %s\n", $dbName, $eConn->getMessage());
            $failedCount++;
            
            $decision = [
                'should_sync' => false,
                'status' => 'failed',
                'change_detected' => true,
                'current_metadata' => [],
                'previous_signature' => null,
                'skip_reason' => 'Error de conexión origen'
            ];
            $jobId = SyncModel::createDatabaseSyncJobItem($runId, $dbName, $decision);
            SyncModel::updateDatabaseSyncJobItem($jobId, 'failed', $eConn->getMessage());
            continue;
        }

        // 3. Evaluar decisión de sincronización según firma metadata
        try {
            $decision = DatabaseMetadataService::evaluateSyncDecision($origenPdo, $destinoConnMain, $dbName);
        } catch (Exception $eMeta) {
            echo sprintf("  -> ERROR obteniendo metadatos de '%s': %s\n", $dbName, $eMeta->getMessage());
            $failedCount++;
            $jobId = SyncModel::createDatabaseSyncJobItem($runId, $dbName, [
                'should_sync' => false,
                'status' => 'failed',
                'change_detected' => true,
                'current_metadata' => [],
                'previous_signature' => null,
                'skip_reason' => 'Error en metadatos'
            ]);
            SyncModel::updateDatabaseSyncJobItem($jobId, 'failed', $eMeta->getMessage());
            continue;
        }

        // CASO OMITIDO: Sin cambios en la firma metadata
        if (!$decision['should_sync']) {
            $skippedCount++;
            echo sprintf("  -> RESULTADO: SKIPPED_UNCHANGED (Sin cambios desde la última versión sincronizada: %s)\n", substr($decision['previous_signature'] ?? '', 0, 12));
            SyncModel::createDatabaseSyncJobItem($runId, $dbName, $decision);
            continue;
        }

        // CASO SINCRONIZACIÓN REQUERIDA (SYNC REQUIRED)
        echo sprintf("  -> RESULTADO: SYNC REQUIRED (Firma anterior: %s | Nueva firma: %s)\n",
            substr($decision['previous_signature'] ?? 'NINGUNA', 0, 12),
            substr($decision['current_metadata']['metadata_signature'], 0, 12)
        );

        $jobId = SyncModel::createDatabaseSyncJobItem($runId, $dbName, $decision);
        $dbStartTime = microtime(true);

        try {
            // Asegurar que la base de datos de destino existe físicamente (mismo aprovisionamiento que en sincronización manual)
            Database::ensureClientDatabaseExists($dbName, 'destino');
            $destinoPdo = Database::getClienteConnection($dbName, 'destino');

            // Desactivar temporalmente FK Checks y SQL estricto en Destino
            $destinoPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $destinoPdo->exec("SET UNIQUE_CHECKS = 0;");
            $destinoPdo->exec("SET SQL_MODE = '';");
            try { $destinoPdo->exec("SET GLOBAL max_allowed_packet = 1073741824;"); } catch (Throwable $eG) {}

            // Garantizar la creación de la estructura DDL de la tabla 'estadisticasUso' en destino sin migrar sus datos
            DatabaseMetadataService::ensureExcludedTablesStructure($origenPdo, $destinoPdo);

            // 4. Sincronizar todas las tablas excluyendo 'estadisticasUso' y tablas de control del migrador
            $stmtTablas = $origenPdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tablas = [];
            $controlTables = ['sync_jobs', 'sync_progress', 'sync_logs', 'database_sync_runs', 'database_sync_jobs', 'database_sync_state'];
            
            while ($row = $stmtTablas->fetch(PDO::FETCH_NUM)) {
                $tName = $row[0];
                if (!DatabaseMetadataService::isExcludedTable($tName) && !in_array(strtolower($tName), $controlTables, true)) {
                    $tablas[] = $tName;
                }
            }

            $totTablas = count($tablas);
            $tablasExitosas = 0;
            $tablasFallidas = 0;
            $erroresTablas = [];

            // Sincronizar estructura y datos de cada tabla con tolerancia a fallos por tabla (igual a la sync manual)
            foreach ($tablas as $tabla) {
                try {
                    // A. Garantizar que la estructura existe en destino
                    try {
                        $destinoPdo->query("SELECT 1 FROM `$tabla` LIMIT 1");
                        $tablaExiste = true;
                    } catch (Exception $exPrep) {
                        $tablaExiste = false;
                    }

                    if (!$tablaExiste) {
                        $stmtCreate = $origenPdo->query("SHOW CREATE TABLE `$tabla`");
                        $createSql = $stmtCreate->fetch()['Create Table'];
                        
                        // Sanitizar colaciones incompatibles de MySQL 8.0 para MariaDB 10.x
                        $createSql = preg_replace('/utf8mb4_0900_ai_ci/i', 'utf8mb4_unicode_ci', $createSql);
                        $createSql = preg_replace('/utf8mb4_0900_bin/i', 'utf8mb4_bin', $createSql);
                        $createSql = preg_replace('/utf8mb4_0[89]\d\d_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', $createSql);

                        // Intentar crear la tabla
                        try {
                            $destinoPdo->exec($createSql);
                        } catch (Exception $exFkCreate) {
                            // Si falla por FK constraint (errno 150), limpiar restricciones FK y reintentar
                            $cleanSql = preg_replace('/CONSTRAINT `[^`]+` FOREIGN KEY `[^`]+` \([^)]+\) REFERENCES `[^`]+` \([^)]+\)( ON DELETE [^,\n]+)?( ON UPDATE [^,\n]+)?/i', '', $createSql);
                            $cleanSql = preg_replace('/CONSTRAINT `[^`]+` FOREIGN KEY \([^)]+\) REFERENCES `[^`]+` \([^)]+\)( ON DELETE [^,\n]+)?( ON UPDATE [^,\n]+)?/i', '', $cleanSql);
                            $cleanSql = preg_replace('/,\s*\)/', ')', $cleanSql);
                            $destinoPdo->exec($cleanSql);
                        }
                    } else {
                        // Sincronizar columnas para agregar faltantes como 'sistema_id'
                        syncTableColumnsWorker($origenPdo, $destinoPdo, $tabla);
                        try {
                            $destinoPdo->exec("TRUNCATE TABLE `$tabla`;");
                        } catch (Exception $exTrunc) {
                            $destinoPdo->exec("DELETE FROM `$tabla`;");
                        }
                    }

                    // B. Copiar registros por bloques
                    $stmtCount = $origenPdo->query("SELECT COUNT(*) FROM `$tabla` ");
                    $totalReg = (int)$stmtCount->fetchColumn();

                    if ($totalReg > 0) {
                        $limit = 1000;
                        $offset = 0;
                        while ($offset < $totalReg) {
                            $stmtFetch = $origenPdo->query("SELECT * FROM `$tabla` LIMIT $limit OFFSET $offset");
                            $rowsData = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

                            if (!empty($rowsData)) {
                                try {
                                    $destinoPdo->beginTransaction();
                                    $cols = array_map(fn($c) => "`$c`", array_keys($rowsData[0]));
                                    $sql = "INSERT INTO `$tabla` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")";
                                    $stmtIns = $destinoPdo->prepare($sql);
                                    foreach ($rowsData as $r) {
                                        if (strtolower($tabla) === 'squemas' && isset($r['squema'])) {
                                            $r['squema'] = strtolower($r['squema']);
                                        }
                                        $stmtIns->execute(array_values($r));
                                    }
                                    $destinoPdo->commit();
                                } catch (Exception $exBatch) {
                                    if ($destinoPdo->inTransaction()) {
                                        $destinoPdo->rollBack();
                                    }
                                    
                                    $msgB = strtolower($exBatch->getMessage());
                                    if (str_contains($msgB, 'unknown column') || str_contains($msgB, '1054')) {
                                        syncTableColumnsWorker($origenPdo, $destinoPdo, $tabla);
                                    }

                                    if (Database::isServerGoneException($exBatch)) {
                                        try {
                                            $destinoPdo = Database::getClienteConnection($dbName, 'destino');
                                            $destinoPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                                            try { $destinoPdo->exec("SET GLOBAL max_allowed_packet = 1073741824;"); } catch (Throwable $eG) {}
                                        } catch (Exception $exReconn) {}
                                    }

                                    // Reintento fila por fila para aislar registros con problemas o reconectar
                                    $cols = array_map(fn($c) => "`$c`", array_keys($rowsData[0]));
                                    $sql = "INSERT INTO `$tabla` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")";
                                    $stmtIns = $destinoPdo->prepare($sql);
                                    foreach ($rowsData as $r) {
                                        $r = sanitizarRegistroTextoWorker($r);
                                        if (strtolower($tabla) === 'squemas' && isset($r['squema'])) {
                                            $r['squema'] = strtolower($r['squema']);
                                        }
                                        try {
                                            $stmtIns->execute(array_values($r));
                                        } catch (Exception $exRow) {
                                            if (Database::isServerGoneException($exRow)) {
                                                try {
                                                    $destinoPdo = Database::getClienteConnection($dbName, 'destino');
                                                    $destinoPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                                                    try { $destinoPdo->exec("SET GLOBAL max_allowed_packet = 1073741824;"); } catch (Throwable $eG) {}
                                                    $stmtIns = $destinoPdo->prepare($sql);
                                                    $stmtIns->execute(array_values($r));
                                                } catch (Exception $exFinal) {}
                                            }
                                        }
                                    }
                                }
                            }
                            $offset += count($rowsData);
                            if (count($rowsData) === 0) break;
                        }
                    }

                    // Verificación especial para tabla 'usuarios'
                    if (strtolower($tabla) === 'usuarios' && $totalReg > 0) {
                        $stmtVerify = $destinoPdo->query("SELECT COUNT(*) FROM `usuarios`");
                        if ((int)$stmtVerify->fetchColumn() === 0) {
                            throw new Exception("La sincronización de la tabla 'usuarios' resultó en 0 registros en destino.");
                        }
                    }

                    $tablasExitosas++;

                } catch (Throwable $eTabla) {
                    $tablasFallidas++;
                    $erroresTablas[] = "$tabla: " . $eTabla->getMessage();
                    echo sprintf("    [WARN] Tabla '%s' saltada por error: %s\n", $tabla, $eTabla->getMessage());
                }
            }

            // Reactivar Foreign Key Checks
            try {
                $destinoPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                $destinoPdo->exec("SET UNIQUE_CHECKS = 1;");
            } catch (Exception $exRe) {}

            $dbDuration = (int)ceil(microtime(true) - $dbStartTime);
            $destSizeBytes = $decision['current_metadata']['total_size_bytes'];
            $pctLogrado = ($totTablas > 0) ? (int)round(($tablasExitosas / $totTablas) * 100) : 100;

            // Determinar si la base de datos se marca como completada (al igual que la manual)
            if ($tablasExitosas > 0 || $totTablas === 0) {
                $skipReason = ($tablasFallidas === 0)
                    ? "Sincronización 100% completada ($tablasExitosas/$totTablas tablas)"
                    : "Sincronizado al {$pctLogrado}% ($tablasExitosas/$totTablas tablas completadas. $tablasFallidas omitida(s))";

                // Actualizar estado a 'completed'
                SyncModel::updateDatabaseSyncJobItem($jobId, 'completed', null, $dbDuration, $destSizeBytes);
                
                // Registrar la firma exitosa en database_sync_state
                DatabaseMetadataService::recordSuccessfulSync(
                    $destinoConnMain,
                    $runId,
                    $dbName,
                    $decision['current_metadata']
                );

                // Guardar la razón explicativa con el porcentaje logrado
                $stmtReason = $destinoConnMain->prepare("UPDATE database_sync_jobs SET skip_reason = :reason WHERE id = :id");
                $stmtReason->execute(['reason' => $skipReason, 'id' => $jobId]);

                $successfulCount++;
                $syncDurations[] = $dbDuration;

                echo sprintf("  -> Sincronización COMPLETADA al %d%% en %d segundos. (%s)\n", $pctLogrado, $dbDuration, $skipReason);

                // Métricas de mayor peso, más lento y más rápido
                if ($destSizeBytes > $heaviestSynced['size']) {
                    $heaviestSynced = ['name' => $dbName, 'size' => $destSizeBytes];
                }
                if ($dbDuration > $slowestSynced['duration']) {
                    $slowestSynced = ['name' => $dbName, 'duration' => $dbDuration];
                }
                if ($dbDuration < $fastestSynced['duration']) {
                    $fastestSynced = ['name' => $dbName, 'duration' => $dbDuration];
                }
            } else {
                // Fallo total (0 tablas sincronizadas)
                $firstErr = !empty($erroresTablas) ? $erroresTablas[0] : 'No se pudo sincronizar ninguna tabla.';
                $failedCount++;
                echo sprintf("  -> ERROR en sincronización de '%s': %s\n", $dbName, $firstErr);
                SyncModel::updateDatabaseSyncJobItem($jobId, 'failed', $firstErr, $dbDuration);
            }

        } catch (Exception $eSync) {
            if (isset($destinoPdo) && $destinoPdo->inTransaction()) {
                try { $destinoPdo->rollBack(); } catch (Exception $exRb) {}
            }
            if (isset($destinoPdo)) {
                try { $destinoPdo->exec("SET FOREIGN_KEY_CHECKS = 1;"); } catch (Exception $exFk) {}
            }
            $dbDuration = (int)ceil(microtime(true) - $dbStartTime);
            $failedCount++;
            echo sprintf("  -> ERROR FATAL en '%s': %s\n", $dbName, $eSync->getMessage());

            // Marcar job como fallido (NO ACTUALIZAR database_sync_state)
            SyncModel::updateDatabaseSyncJobItem($jobId, 'failed', $eSync->getMessage(), $dbDuration);
        }
    }

    $totalDuration = (int)ceil(microtime(true) - $startTime);
    $runStatus = ($failedCount === 0) ? 'completed' : ($successfulCount > 0 ? 'completed_with_errors' : 'failed');

    // Finalizar registro global
    SyncModel::finishDatabaseSyncRun(
        $runId,
        $runStatus,
        $totalDatabases,
        $processedCount,
        $successfulCount,
        $failedCount,
        $skippedCount,
        $totalDuration
    );

    // Calcular tiempo ahorrado estimado (Promedio de tiempo por base sincronizada * bases omitidas)
    $avgDurationPerSync = !empty($syncDurations) ? array_sum($syncDurations) / count($syncDurations) : 0;
    $estimatedSavedSeconds = (int)round($avgDurationPerSync * $skippedCount);

    $savedHours = floor($estimatedSavedSeconds / 3600);
    $savedMins = floor(($estimatedSavedSeconds % 3600) / 60);
    $savedSecs = $estimatedSavedSeconds % 60;
    $savedText = sprintf("%02dh %02dm %02ds", $savedHours, $savedMins, $savedSecs);

    $totHours = floor($totalDuration / 3600);
    $totMins = floor(($totalDuration % 3600) / 60);
    $totSecs = $totalDuration % 60;
    $totText = sprintf("%02dh %02dm %02ds", $totHours, $totMins, $totSecs);

    echo "\n======================================================================\n";
    echo "                     ESTADÍSTICAS FINALES DE EJECUCIÓN                 \n";
    echo "======================================================================\n";
    echo sprintf(" TOTAL BASES DETECTADAS        : %d\n", $totalDatabases);
    echo sprintf(" TOTAL SIN CAMBIOS (SKIPPED)   : %d\n", $skippedCount);
    echo sprintf(" TOTAL SINCRONIZADAS           : %d\n", $successfulCount);
    echo sprintf(" TOTAL FALLIDAS                : %d\n", $failedCount);
    echo sprintf(" TIEMPO TOTAL DE EJECUCIÓN     : %s (%d s)\n", $totText, $totalDuration);
    echo sprintf(" TIEMPO AHORRADO ESTIMADO      : %s\n", $savedText);

    if ($heaviestSynced['name']) {
        echo sprintf(" BASE MÁS PESADA SINCRONIZADA   : %s (%s)\n", $heaviestSynced['name'], BackupService::formatBytes($heaviestSynced['size']));
    }
    if ($slowestSynced['name']) {
        echo sprintf(" BASE MÁS LENTA                : %s (%d s)\n", $slowestSynced['name'], $slowestSynced['duration']);
    }
    if ($fastestSynced['name'] && $fastestSynced['duration'] !== PHP_INT_MAX) {
        echo sprintf(" BASE MÁS RÁPIDA               : %s (%d s)\n", $fastestSynced['name'], $fastestSynced['duration']);
    }
    echo " TABLA EXCLUIDA                : estadisticasUso (Intacta en origen, omitida en firmas y sync)\n";
    echo "======================================================================\n";

    return [
        'success' => true,
        'run_id' => $runId,
        'total' => $totalDatabases,
        'skipped' => $skippedCount,
        'successful' => $successfulCount,
        'failed' => $failedCount,
        'duration_seconds' => $totalDuration
    ];
}

function sanitizarRegistroTextoWorker(array $row): array {
    foreach ($row as $col => $val) {
        if (is_string($val)) {
            $val = str_replace("\0", "", $val);
            if (!mb_check_encoding($val, 'UTF-8')) {
                $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
            }
            if (function_exists('iconv')) {
                $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $val);
                if ($clean !== false) {
                    $val = $clean;
                }
            }
            $row[$col] = $val;
        }
    }
    return $row;
}

function syncTableColumnsWorker(PDO $origenPdo, PDO $destinoPdo, string $tabla): void {
    try {
        $stmtColsOrig = $origenPdo->query("SHOW FULL COLUMNS FROM `$tabla`");
        $colsOrigen = $stmtColsOrig->fetchAll(PDO::FETCH_ASSOC);

        $stmtColsDest = $destinoPdo->query("SHOW FULL COLUMNS FROM `$tabla`");
        $colsDestino = $stmtColsDest->fetchAll(PDO::FETCH_ASSOC);

        $destColMap = [];
        foreach ($colsDestino as $c) {
            $destColMap[strtolower($c['Field'])] = true;
        }

        $lastCol = null;
        foreach ($colsOrigen as $col) {
            $field = $col['Field'];
            if (!isset($destColMap[strtolower($field)])) {
                $type = $col['Type'];
                $null = ($col['Null'] === 'NO') ? 'NOT NULL' : 'NULL';
                $default = ($col['Default'] !== null) ? "DEFAULT " . $destinoPdo->quote($col['Default']) : '';
                if ($col['Null'] === 'YES' && $col['Default'] === null) {
                    $default = 'DEFAULT NULL';
                }
                $extra = $col['Extra'] ?? '';
                $after = $lastCol ? "AFTER `$lastCol`" : "FIRST";

                $sql = "ALTER TABLE `$tabla` ADD COLUMN `$field` $type $null $default $extra $after";
                $destinoPdo->exec($sql);
            }
            $lastCol = $field;
        }
    } catch (Exception $e) {
        try {
            $stmtCreate = $origenPdo->query("SHOW CREATE TABLE `$tabla`");
            $row = $stmtCreate->fetch();
            $createSql = $row['Create Table'];
            $createSql = preg_replace('/utf8mb4_0900_ai_ci/i', 'utf8mb4_unicode_ci', $createSql);
            $createSql = preg_replace('/utf8mb4_0900_bin/i', 'utf8mb4_bin', $createSql);
            $createSql = preg_replace('/utf8mb4_0[89]\d\d_[a-z0-9_]+/i', 'utf8mb4_unicode_ci', $createSql);

            $destinoPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $destinoPdo->exec("DROP TABLE IF EXISTS `$tabla`;");
            $destinoPdo->exec($createSql);
        } catch (Exception $exRecreate) {}
    }
}

// Ejecución directa CLI
if (php_sapi_name() === 'cli') {
    runAutomaticSync('cron');
}
