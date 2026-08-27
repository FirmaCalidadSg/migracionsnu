<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DatabaseMetadataService.php';

class SyncModel {
    
    // Constantes de Estado de Validación (Fase 1)
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR_CLIENT_MISSING = 'error_cliente_no_existe';
    public const STATUS_ERROR_SCHEMA_UNREGISTERED = 'error_schema_no_registrado';
    public const STATUS_ERROR_DB_PHYSICAL_ORIGEN = 'error_db_fisica_origen';
    public const STATUS_ERROR_DB_PHYSICAL_DESTINO = 'error_db_fisica_destino';

    /**
     * Obtiene el listado completo de clientes mapeados entre origen y destino
     * con su respectivo estado de validación y datos del último job de sincronización.
     */
    public static function getClientesMap(): array {
        $origen = Database::getOrigenConnection();
        $destino = Database::getDestinoConnection();

        // 1. Obtener clientes y sus esquemas registrados en ORIGEN
        $stmt = $origen->query("
            SELECT c.id, c.nombre, c.dir, s.squema 
            FROM clientes c
            LEFT JOIN squemas s ON s.cliente_id = c.id
            ORDER BY c.nombre ASC
        ");
        $clientesOrigen = $stmt->fetchAll();

        $mapResult = [];

        foreach ($clientesOrigen as $cliente) {
            $clienteId = (int)$cliente['id'];
            $nombre = $cliente['nombre'];
            $dir = $cliente['dir'];
            $schema = $cliente['squema'];
            
            $status = self::STATUS_OK;
            $detalleError = '';
            
            // A. Validar que el cliente exista en destino
            $stmtDest = $destino->prepare("SELECT id FROM clientes WHERE id = :id");
            $stmtDest->execute(['id' => $clienteId]);
            $clienteDestino = $stmtDest->fetch();
            
            if (!$clienteDestino) {
                $status = self::STATUS_ERROR_CLIENT_MISSING;
                $detalleError = "El cliente no está registrado en el servidor de destino.";
            } 
            // B. Validar que el esquema esté registrado en destino
            else if (empty($schema)) {
                $status = self::STATUS_ERROR_SCHEMA_UNREGISTERED;
                $detalleError = "El cliente no tiene un esquema configurado en origen.";
            } else {
                $stmtSchemaDest = $destino->prepare("SELECT id FROM squemas WHERE cliente_id = :cliente_id AND LOWER(squema) = LOWER(:schema)");
                $stmtSchemaDest->execute([
                    'cliente_id' => (string)$clienteId,
                    'schema' => $schema
                ]);
                $schemaDestino = $stmtSchemaDest->fetch();
                
                if (!$schemaDestino) {
                    $status = self::STATUS_ERROR_SCHEMA_UNREGISTERED;
                    $detalleError = "El esquema '$schema' no está registrado en el servidor de destino.";
                } else {
                    // C. Validar existencia física del esquema en origen
                    try {
                        Database::getClienteConnection($schema, 'origen');
                    } catch (Exception $e) {
                        $status = self::STATUS_ERROR_DB_PHYSICAL_ORIGEN;
                        $detalleError = "No se pudo conectar a la base de datos física de origen para el esquema '$schema'.";
                    }

                    // D. Validar existencia física del esquema en destino
                    if ($status === self::STATUS_OK) {
                        try {
                            Database::getClienteConnection($schema, 'destino');
                        } catch (Exception $e) {
                            $status = self::STATUS_ERROR_DB_PHYSICAL_DESTINO;
                            $detalleError = "No se pudo conectar a la base de datos física de destino para el esquema '$schema'.";
                        }
                    }
                }
            }

            // E. Obtener información del último Job de sincronización en destino
            $stmtJob = $destino->prepare("
                SELECT id, estado, total_tablas, tablas_completadas, error_mensaje, fecha_inicio, fecha_fin
                FROM sync_jobs 
                WHERE cliente_id = :cliente_id 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmtJob->execute(['cliente_id' => $clienteId]);
            $ultimoJob = $stmtJob->fetch();

            $mapResult[] = [
                'id' => $clienteId,
                'nombre' => $nombre,
                'dir' => $dir,
                'schema' => $schema,
                'status' => $status,
                'detalle_error' => $detalleError,
                'ultimo_job' => $ultimoJob ? [
                    'id' => (int)$ultimoJob['id'],
                    'estado' => $ultimoJob['estado'],
                    'total_tablas' => (int)$ultimoJob['total_tablas'],
                    'tablas_completadas' => (int)$ultimoJob['tablas_completadas'],
                    'error_mensaje' => $ultimoJob['error_mensaje'],
                    'fecha_inicio' => $ultimoJob['fecha_inicio'],
                    'fecha_fin' => $ultimoJob['fecha_fin']
                ] : null
            ];
        }

        return $mapResult;
    }

    /**
     * Crea un nuevo registro de Job de Sincronización en destino.
     */
    public static function createSyncJob(int $clienteId, string $schemaName, int $totalTablas = 0): int {
        $destino = Database::getDestinoConnection();
        $stmt = $destino->prepare("
            INSERT INTO sync_jobs (cliente_id, schema_name, estado, fecha_inicio, total_tablas)
            VALUES (:cliente_id, :schema_name, 'pendiente', NOW(), :total_tablas)
        ");
        $stmt->execute([
            'cliente_id' => $clienteId,
            'schema_name' => $schemaName,
            'total_tablas' => $totalTablas
        ]);
        return (int)$destino->lastInsertId();
    }

    /**
     * Actualiza el estado de un Job de Sincronización.
     */
    public static function updateSyncJobStatus(int $jobId, string $status, ?string $errorMessage = null, ?int $tablasCompletadas = null): void {
        $destino = Database::getDestinoConnection();
        
        $sql = "UPDATE sync_jobs SET estado = :estado";
        $params = ['estado' => $status, 'id' => $jobId];

        if ($status === 'completado' || $status === 'fallido') {
            $sql .= ", fecha_fin = NOW()";
        }
        if ($errorMessage !== null) {
            $sql .= ", error_mensaje = :error_mensaje";
            $params['error_mensaje'] = $errorMessage;
        }
        if ($tablasCompletadas !== null) {
            $sql .= ", tablas_completadas = :tablas_completadas";
            $params['tablas_completadas'] = $tablasCompletadas;
        }
        
        $sql .= " WHERE id = :id";
        
        $stmt = $destino->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Registra un log en la tabla `sync_logs`.
     */
    public static function log(string $nivel, string $cliente, string $schema, string $mensaje, ?string $detalles = null, ?int $jobId = null): void {
        $destino = Database::getDestinoConnection();
        $stmt = $destino->prepare("
            INSERT INTO sync_logs (job_id, nivel, cliente, schema_name, mensaje, detalles_tecnicos)
            VALUES (:job_id, :nivel, :cliente, :schema, :mensaje, :detalles)
        ");
        $stmt->execute([
            'job_id' => $jobId,
            'nivel' => $nivel,
            'cliente' => $cliente,
            'schema' => $schema,
            'mensaje' => $mensaje,
            'detalles' => $detalles
        ]);
    }

    /**
     * Obtiene los logs más recientes de un cliente o un job específico.
     */
    public static function getLogs(?int $jobId = null, ?string $schemaName = null, int $limit = 100): array {
        $destino = Database::getDestinoConnection();
        
        $sql = "SELECT id, job_id, nivel, cliente, schema_name, tabla_nombre, mensaje, detalles_tecnicos, fecha_registro 
                FROM sync_logs";
        $params = [];
        $where = [];

        if ($jobId !== null) {
            $where[] = "job_id = :job_id";
            $params['job_id'] = $jobId;
        }
        if ($schemaName !== null) {
            $where[] = "schema_name = :schema_name";
            $params['schema_name'] = $schemaName;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " ORDER BY id DESC LIMIT :limit";
        
        $stmt = $destino->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Asegura la existencia idempotente de las tablas de control en el servidor de destino.
     */
    public static function ensureControlTablesExist(): void {
        try {
            $destino = Database::getDestinoConnection();
            $destino->exec("
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
                    INDEX (`database_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

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
            ");
        } catch (Exception $e) {}
    }

    /**
     * Crea un registro de ejecución global en `database_sync_runs`.
     */
    public static function createDatabaseSyncRun(string $triggerType = 'scheduled', int $totalDatabases = 0): int {
        self::ensureControlTablesExist();
        $destino = Database::getDestinoConnection();
        $stmt = $destino->prepare("
            INSERT INTO database_sync_runs (started_at, status, total_databases, trigger_type, pid)
            VALUES (NOW(), 'running', :total, :trigger_type, :pid)
        ");
        $stmt->execute([
            'total' => $totalDatabases,
            'trigger_type' => $triggerType,
            'pid' => getmypid()
        ]);
        return (int)$destino->lastInsertId();
    }

    /**
     * Finaliza un registro de ejecución global en `database_sync_runs`.
     */
    public static function finishDatabaseSyncRun(
        int $runId,
        string $status,
        int $total,
        int $processed,
        int $successful,
        int $failed,
        int $skipped,
        int $durationSeconds
    ): void {
        $destino = Database::getDestinoConnection();
        $stmt = $destino->prepare("
            UPDATE database_sync_runs 
            SET finished_at = NOW(),
                status = :status,
                total_databases = :total,
                processed_databases = :processed,
                successful_databases = :successful,
                failed_databases = :failed,
                skipped_databases = :skipped,
                total_duration_seconds = :duration
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'successful' => $successful,
            'failed' => $failed,
            'skipped' => $skipped,
            'duration' => $durationSeconds,
            'id' => $runId
        ]);
    }

    /**
     * Registra un job individual por base de datos en `database_sync_jobs`.
     */
    public static function createDatabaseSyncJobItem(int $runId, string $databaseName, array $decisionData): int {
        $databaseName = strtolower(trim($databaseName));
        $destino = Database::getDestinoConnection();
        $meta = $decisionData['current_metadata'] ?? [];

        $isSyncing = ($decisionData['status'] === 'syncing');

        $stmt = $destino->prepare("
            INSERT INTO database_sync_jobs (
                run_id, database_name, status, started_at, finished_at,
                source_size_bytes, table_count, estimated_rows,
                metadata_signature, previous_metadata_signature,
                change_detected, skip_reason
            ) VALUES (
                :run_id, :database_name, :status, :started_at, :finished_at,
                :source_size, :table_count, :estimated_rows,
                :metadata_signature, :previous_signature,
                :change_detected, :skip_reason
            )
        ");

        $stmt->execute([
            'run_id' => $runId,
            'database_name' => $databaseName,
            'status' => $decisionData['status'],
            'started_at' => $isSyncing ? date('Y-m-d H:i:s') : null,
            'finished_at' => !$isSyncing ? date('Y-m-d H:i:s') : null,
            'source_size' => $meta['total_size_bytes'] ?? 0,
            'table_count' => $meta['table_count'] ?? 0,
            'estimated_rows' => $meta['total_rows_estimated'] ?? 0,
            'metadata_signature' => $meta['metadata_signature'] ?? null,
            'previous_signature' => $decisionData['previous_signature'] ?? null,
            'change_detected' => $decisionData['change_detected'] ? 1 : 0,
            'skip_reason' => $decisionData['skip_reason'] ?? null
        ]);

        return (int)$destino->lastInsertId();
    }

    /**
     * Actualiza el resultado final de un job individual en `database_sync_jobs`.
     */
    public static function updateDatabaseSyncJobItem(
        int $jobId,
        string $status,
        ?string $errorMessage = null,
        ?int $durationSeconds = null,
        ?int $destSizeBytes = null
    ): void {
        $destino = Database::getDestinoConnection();
        $stmt = $destino->prepare("
            UPDATE database_sync_jobs
            SET status = :status,
                finished_at = NOW(),
                duration_seconds = :duration,
                destination_size_bytes = :dest_size,
                error_message = :error_message
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $status,
            'duration' => $durationSeconds ?? 0,
            'dest_size' => $destSizeBytes ?? 0,
            'error_message' => $errorMessage,
            'id' => $jobId
        ]);
    }

    /**
     * Obtiene el listado de ejecuciones de sincronización globales y el detalle de la última ejecución.
     */
    public static function getSyncRunsSummary(int $limit = 20): array {
        self::ensureControlTablesExist();
        $destino = Database::getDestinoConnection();

        // 1. Obtener lista de corridas globales
        $stmtRuns = $destino->prepare("
            SELECT id, started_at, finished_at, status, total_databases,
                   processed_databases, successful_databases, failed_databases,
                   skipped_databases, total_duration_seconds, trigger_type, pid, created_at
            FROM database_sync_runs
            ORDER BY id DESC
            LIMIT :limit
        ");
        $stmtRuns->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtRuns->execute();
        $runs = $stmtRuns->fetchAll(PDO::FETCH_ASSOC);

        // 2. Obtener estado actual por cada base de datos registrada
        $stmtState = $destino->query("
            SELECT database_name, last_successful_run_id, last_successful_at,
                   last_source_size_bytes, last_table_count, last_estimated_rows,
                   last_metadata_signature, last_status, updated_at
            FROM database_sync_state
            ORDER BY database_name ASC
        ");
        $states = $stmtState->fetchAll(PDO::FETCH_ASSOC);

        // 3. Obtener detalle de jobs de la última corrida si existe y adjuntar información de la última sync manual
        $latestRunId = !empty($runs) ? (int)$runs[0]['id'] : null;
        $jobs = [];
        if ($latestRunId !== null) {
            $stmtJobs = $destino->prepare("
                SELECT id, run_id, database_name, status, started_at, finished_at,
                       duration_seconds, source_size_bytes, destination_size_bytes,
                       table_count, estimated_rows, metadata_signature,
                       previous_metadata_signature, change_detected, skip_reason, error_message
                FROM database_sync_jobs
                WHERE run_id = :run_id
                ORDER BY id ASC
            ");
            $stmtJobs->execute(['run_id' => $latestRunId]);
            $rawJobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

            // Preparar consulta para buscar la última sincronización manual desde sync_jobs por cada esquema
            $stmtManual = $destino->prepare("
                SELECT id, cliente_id, schema_name, estado, total_tablas, tablas_completadas, error_mensaje, fecha_inicio, fecha_fin
                FROM sync_jobs
                WHERE LOWER(schema_name) = LOWER(:schema) 
                   OR LOWER(schema_name) = LOWER(:dbname)
                ORDER BY id DESC
                LIMIT 1
            ");

            foreach ($rawJobs as $j) {
                $dbName = $j['database_name'];
                $cleanSchema = str_replace('fugzcdpo_', '', strtolower($dbName));
                
                $stmtManual->execute([
                    'schema' => $cleanSchema,
                    'dbname' => strtolower($dbName)
                ]);
                $manualJob = $stmtManual->fetch(PDO::FETCH_ASSOC);

                if ($manualJob) {
                    $totTab = (int)($manualJob['total_tablas'] ?? 0);
                    $compTab = (int)($manualJob['tablas_completadas'] ?? 0);
                    $pct = ($totTab > 0) ? (int)round(($compTab / $totTab) * 100) : 0;
                    if ($manualJob['estado'] === 'completado' && $pct < 100) {
                        $pct = 100;
                    }

                    $j['ultimo_sync_manual'] = [
                        'id' => (int)$manualJob['id'],
                        'estado' => $manualJob['estado'],
                        'total_tablas' => $totTab,
                        'tablas_completadas' => $compTab,
                        'porcentaje' => $pct,
                        'fecha' => $manualJob['fecha_fin'] ?? $manualJob['fecha_inicio'],
                        'error' => $manualJob['error_mensaje']
                    ];
                } else {
                    $j['ultimo_sync_manual'] = null;
                }

                $jobs[] = $j;
            }
        }

        return [
            'runs' => $runs,
            'latest_run' => !empty($runs) ? $runs[0] : null,
            'jobs' => $jobs,
            'states' => $states
        ];
    }

    /**
     * Re-evalúa los metadatos de una base de datos específica basándose en la última sincronización manual
     * y actualiza database_sync_jobs, database_sync_state y recalcula los contadores globales en database_sync_runs.
     */
    public static function revalidarJobMetadata(?int $jobId, ?string $databaseName): array {
        $destino = Database::getDestinoConnection();

        // 1. Obtener la fila del job desde database_sync_jobs
        if ($jobId !== null && $jobId > 0) {
            $stmt = $destino->prepare("SELECT * FROM database_sync_jobs WHERE id = :id");
            $stmt->execute(['id' => $jobId]);
            $job = $stmt->fetch();
        } else {
            $stmt = $destino->prepare("SELECT * FROM database_sync_jobs WHERE database_name = :db ORDER BY id DESC LIMIT 1");
            $stmt->execute(['db' => $databaseName]);
            $job = $stmt->fetch();
        }

        if (!$job) {
            throw new Exception("No se encontró el registro de sincronización en database_sync_jobs.");
        }

        $jobId = (int)$job['id'];
        $runId = (int)$job['run_id'];
        $dbName = $job['database_name'];
        $cleanSchema = str_replace('fugzcdpo_', '', strtolower($dbName));

        // 2. Buscar la última sincronización manual en sync_jobs para esta base
        $stmtManual = $destino->prepare("
            SELECT id, cliente_id, schema_name, estado, total_tablas, tablas_completadas, error_mensaje, fecha_inicio, fecha_fin
            FROM sync_jobs
            WHERE LOWER(schema_name) = LOWER(:schema) 
               OR LOWER(schema_name) = LOWER(:dbname)
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtManual->execute(['schema' => $cleanSchema, 'dbname' => strtolower($dbName)]);
        $manualJob = $stmtManual->fetch(PDO::FETCH_ASSOC);

        $pctManual = 0;
        $fechaManual = '';
        if ($manualJob) {
            $totTab = (int)($manualJob['total_tablas'] ?? 0);
            $compTab = (int)($manualJob['tablas_completadas'] ?? 0);
            $pctManual = ($totTab > 0) ? (int)round(($compTab / $totTab) * 100) : 0;
            if ($manualJob['estado'] === 'completado' && $pctManual < 100) {
                $pctManual = 100;
            }
            $fechaManual = $manualJob['fecha_fin'] ?? $manualJob['fecha_inicio'] ?? '';
        }

        // 3. Conectar a Origen para evaluar metadatos actuales
        $origenPdo = Database::getClienteConnection($dbName, 'origen');
        $currentMetadata = DatabaseMetadataService::getDatabaseMetadata($origenPdo, $dbName);

        // 4. Registrar sincronización exitosa en database_sync_state
        DatabaseMetadataService::recordSuccessfulSync($destino, $runId, $dbName, $currentMetadata);

        // 5. Construir razón de salto / detalle explicativo
        $skipReason = "Validado manualmente";
        if ($manualJob) {
            $skipReason = "Validado por sync manual ({$pctManual}% el {$fechaManual})";
        }

        // 6. Actualizar el registro en database_sync_jobs a 'completed'
        $stmtUpd = $destino->prepare("
            UPDATE database_sync_jobs 
            SET status = 'completed',
                metadata_signature = :sig,
                change_detected = 0,
                skip_reason = :reason,
                error_message = NULL,
                finished_at = NOW(),
                source_size_bytes = :size,
                table_count = :tables,
                estimated_rows = :rows
            WHERE id = :id
        ");
        $stmtUpd->execute([
            'sig' => $currentMetadata['metadata_signature'],
            'reason' => $skipReason,
            'size' => $currentMetadata['total_size_bytes'],
            'tables' => $currentMetadata['table_count'],
            'rows' => $currentMetadata['total_rows_estimated'],
            'id' => $jobId
        ]);

        // 7. Recalcular resumen en database_sync_runs para la ejecución (run_id)
        self::recalcularSyncRunSummary($destino, $runId);

        return [
            'job_id' => $jobId,
            'run_id' => $runId,
            'database' => $dbName,
            'new_status' => 'completed',
            'signature' => $currentMetadata['metadata_signature'],
            'manual_sync_info' => $manualJob ? [
                'porcentaje' => $pctManual,
                'fecha' => $fechaManual,
                'estado' => $manualJob['estado']
            ] : null
        ];
    }

    /**
     * Recalcula y actualiza los contadores globales en database_sync_runs para una ejecución específica.
     */
    public static function recalcularSyncRunSummary(PDO $destino, int $runId): void {
        $stmtJobs = $destino->prepare("
            SELECT status, COUNT(*) as cnt 
            FROM database_sync_jobs 
            WHERE run_id = :run_id 
            GROUP BY status
        ");
        $stmtJobs->execute(['run_id' => $runId]);
        $rows = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);

        $completed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $r) {
            $cnt = (int)$r['cnt'];
            $st = strtolower($r['status']);
            if ($st === 'completed') {
                $completed += $cnt;
            } else if ($st === 'skipped_unchanged') {
                $skipped += $cnt;
            } else if ($st === 'failed') {
                $failed += $cnt;
            }
        }

        $newRunStatus = ($failed === 0) ? 'completed' : 'completed_with_errors';

        $stmtUpdRun = $destino->prepare("
            UPDATE database_sync_runs 
            SET successful_databases = :comp,
                skipped_databases = :skip,
                failed_databases = :fail,
                status = :status,
                updated_at = NOW()
            WHERE id = :run_id
        ");
        $stmtUpdRun->execute([
            'comp' => $completed,
            'skip' => $skipped,
            'fail' => $failed,
            'status' => $newRunStatus,
            'run_id' => $runId
        ]);
    }

    /**
     * Lee las últimas líneas del archivo de log del cron job (/var/log/sync_worker.log).
     */
    public static function getCronLogContent(int $maxLines = 200): string {
        $logPath = '/var/log/sync_worker.log';
        if (!file_exists($logPath)) {
            $logPath = dirname(__DIR__) . '/storage/logs/sync_worker.log';
        }
        if (!file_exists($logPath)) {
            return "No se ha encontrado el archivo de log en $logPath.";
        }

        $lines = @file($logPath);
        if (!$lines) {
            return "El archivo de log está vacío o no se puede leer.";
        }

        $slice = array_slice($lines, -$maxLines);
        return implode('', $slice);
    }
}
