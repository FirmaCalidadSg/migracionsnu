<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

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
}
