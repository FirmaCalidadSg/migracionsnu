<?php

declare(strict_types=1);

/**
 * Servicio de Inspección de Metadatos, Firma Hash y Detección de Cambios en MariaDB.
 * 
 * Calcula la firma (metadata_signature) de cada base de datos usando INFORMATION_SCHEMA
 * de forma ultra eficiente y sin realizar `CHECKSUM TABLE` ni escaneos masivos.
 * Excluye explícitamente la tabla 'estadisticasUso' de las mediciones y comparaciones.
 */
class DatabaseMetadataService {

    /** @var string[] Tablas excluidas del proceso de metadatos y sincronización */
    public const EXCLUDED_TABLES = ['estadisticasuso'];

    /**
     * Comprueba si el nombre de una tabla debe ser excluido.
     */
    public static function isExcludedTable(string $tableName): bool {
        return in_array(strtolower(trim($tableName)), self::EXCLUDED_TABLES, true);
    }

    /**
     * Obtiene los metadatos y calcula la firma SHA-256 de una base de datos en el servidor de origen.
     * Excluye expresamente la tabla 'estadisticasUso'.
     *
     * @param PDO $pdo Conexión al servidor de origen
     * @param string $databaseName Nombre de la base de datos
     * @return array Metadatos calculados (total_size_bytes, table_count, total_rows_estimated, metadata_signature)
     */
    public static function getDatabaseMetadata(PDO $pdo, string $databaseName): array {
        $databaseName = strtolower(trim($databaseName));
        $stmt = $pdo->prepare("
            SELECT 
                TABLE_NAME, 
                COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS table_size,
                COALESCE(TABLE_ROWS, 0) AS estimated_rows
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE LOWER(TABLE_SCHEMA) = LOWER(:dbname) 
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME ASC
        ");
        $stmt->execute(['dbname' => $databaseName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tablesMetadata = [];
        $totalSizeBytes = 0;
        $tableCount = 0;
        $totalRowsEstimated = 0;
        $signatureParts = [];

        foreach ($rows as $row) {
            $tableName = $row['TABLE_NAME'];
            if (self::isExcludedTable($tableName)) {
                continue;
            }

            $size = (int)$row['table_size'];
            $estimatedRows = (int)$row['estimated_rows'];

            $totalSizeBytes += $size;
            $tableCount++;
            $totalRowsEstimated += $estimatedRows;

            $signatureParts[] = sprintf("%s|%d|%d", strtolower($tableName), $size, $estimatedRows);
            $tablesMetadata[] = [
                'name' => $tableName,
                'size' => $size,
                'rows' => $estimatedRows
            ];
        }

        // Firma basada en la lista ordenada de tablas relevantes
        $signatureString = implode(';', $signatureParts);
        $metadataSignature = hash('sha256', $signatureString);

        return [
            'database_name' => $databaseName,
            'total_size_bytes' => $totalSizeBytes,
            'table_count' => $tableCount,
            'total_rows_estimated' => $totalRowsEstimated,
            'metadata_signature' => $metadataSignature,
            'signature_raw' => $signatureString,
            'tables' => $tablesMetadata
        ];
    }

    /**
     * Consulta el registro de la última sincronización exitosa en `database_sync_state`.
     */
    public static function getLastSuccessfulState(PDO $destinoPdo, string $databaseName): ?array {
        $databaseName = strtolower(trim($databaseName));
        $stmt = $destinoPdo->prepare("
            SELECT database_name, last_successful_run_id, last_successful_at,
                   last_source_size_bytes, last_table_count, last_estimated_rows,
                   last_metadata_signature, last_status, updated_at
            FROM database_sync_state
            WHERE LOWER(database_name) = LOWER(:dbname)
        ");
        $stmt->execute(['dbname' => $databaseName]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    /**
     * Evalúa si una base de datos requiere sincronización (SYNC REQUIRED) u omitirse (SKIPPED_UNCHANGED).
     */
    public static function evaluateSyncDecision(PDO $origenPdo, PDO $destinoPdo, string $databaseName): array {
        $currentMeta = self::getDatabaseMetadata($origenPdo, $databaseName);
        $lastState = self::getLastSuccessfulState($destinoPdo, $databaseName);

        $previousSignature = $lastState['last_metadata_signature'] ?? null;
        
        // CASO 1: No existe sincronización anterior
        if ($lastState === null || empty($previousSignature)) {
            return [
                'should_sync' => true,
                'status' => 'syncing',
                'change_detected' => true,
                'current_metadata' => $currentMeta,
                'previous_signature' => null,
                'skip_reason' => null
            ];
        }

        // CASO 2: Existe sincronización anterior y la metadata_signature es diferente
        if ($currentMeta['metadata_signature'] !== $previousSignature) {
            return [
                'should_sync' => true,
                'status' => 'syncing',
                'change_detected' => true,
                'current_metadata' => $currentMeta,
                'previous_signature' => $previousSignature,
                'skip_reason' => null
            ];
        }

        // CASO 3: La metadata_signature coincide con la última sincronización exitosa
        return [
            'should_sync' => false,
            'status' => 'skipped_unchanged',
            'change_detected' => false,
            'current_metadata' => $currentMeta,
            'previous_signature' => $previousSignature,
            'skip_reason' => 'Firma de metadatos idéntica a la última sincronización exitosa'
        ];
    }

    /**
     * Actualiza `database_sync_state` tras una sincronización exitosa.
     * IMPORTANTE: Debe llamarse ÚNICAMENTE después de confirmar el éxito de la sincronización.
     */
    public static function recordSuccessfulSync(
        PDO $destinoPdo,
        ?int $runId,
        string $databaseName,
        array $metadata
    ): void {
        $databaseName = strtolower(trim($databaseName));
        $stmt = $destinoPdo->prepare("
            INSERT INTO database_sync_state (
                database_name, last_successful_run_id, last_successful_at,
                last_source_size_bytes, last_table_count, last_estimated_rows,
                last_metadata_signature, last_status, updated_at
            ) VALUES (
                :dbname, :run_id, NOW(),
                :size, :tables, :rows,
                :signature, 'completed', NOW()
            )
            ON DUPLICATE KEY UPDATE
                last_successful_run_id = VALUES(last_successful_run_id),
                last_successful_at = VALUES(last_successful_at),
                last_source_size_bytes = VALUES(last_source_size_bytes),
                last_table_count = VALUES(last_table_count),
                last_estimated_rows = VALUES(last_estimated_rows),
                last_metadata_signature = VALUES(last_metadata_signature),
                last_status = VALUES(last_status),
                updated_at = VALUES(updated_at)
        ");

        $stmt->execute([
            'dbname' => $databaseName,
            'run_id' => $runId,
            'size' => $metadata['total_size_bytes'] ?? 0,
            'tables' => $metadata['table_count'] ?? 0,
            'rows' => $metadata['total_rows_estimated'] ?? 0,
            'signature' => $metadata['metadata_signature'] ?? ''
        ]);
    }
}
