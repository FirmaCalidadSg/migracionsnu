<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelos/Database.php';
require_once __DIR__ . '/../modelos/SyncModel.php';

class SyncController {

    /**
     * Renderiza la vista principal del dashboard.
     */
    public function index(): void {
        // Cargar la vista.
        require __DIR__ . '/../vistas/dashboard.php';
    }

    /**
     * Devuelve el mapeo de clientes y sus validaciones en formato JSON.
     */
    public function getClientesMap(): void {
        header('Content-Type: application/json');
        try {
            $clientes = SyncModel::getClientesMap();
            echo json_encode(['success' => true, 'data' => $clientes]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Inicia un nuevo Job de sincronización para un cliente.
     */
    public function iniciarJob(): void {
        header('Content-Type: application/json');
        try {
            $clienteId = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
            if ($clienteId <= 0) {
                throw new Exception("ID de cliente inválido.");
            }

            // 1. Obtener el mapa de clientes para buscar el cliente
            $clientes = SyncModel::getClientesMap();
            $clienteActual = null;
            foreach ($clientes as $c) {
                if ($c['id'] === $clienteId) {
                    $clienteActual = $c;
                    break;
                }
            }

            if (!$clienteActual) {
                throw new Exception("El cliente solicitado no existe en origen.");
            }

            // Validar que el estado de mapeo sea OK o que falte la base de datos física de destino (ya que se creará automáticamente)
            if ($clienteActual['status'] !== SyncModel::STATUS_OK && $clienteActual['status'] !== SyncModel::STATUS_ERROR_DB_PHYSICAL_DESTINO) {
                throw new Exception("No se puede iniciar la sincronización: " . $clienteActual['detalle_error']);
            }

            $schema = $clienteActual['schema'];
            $nombreCliente = $clienteActual['nombre'];

            // 2. Conectarse a origen para obtener las tablas
            $dbOrigen = Database::getClienteConnection($schema, 'origen');
            $stmtTablas = $dbOrigen->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tablas = [];
            
            while ($row = $stmtTablas->fetch(PDO::FETCH_NUM)) {
                $tablaNombre = $row[0];
                
                // Contar registros en origen para dimensionar la barra de progreso
                $stmtCount = $dbOrigen->query("SELECT COUNT(*) FROM `$tablaNombre`");
                $totalReg = (int)$stmtCount->fetchColumn();
                
                $tablas[] = [
                    'nombre' => $tablaNombre,
                    'registros' => $totalReg
                ];
            }

            if (empty($tablas)) {
                throw new Exception("El esquema del cliente en origen no contiene tablas.");
            }

            // 3. Crear el Job en destino
            $jobId = SyncModel::createSyncJob($clienteId, $schema, count($tablas));

            // 4. Crear los progresos de tablas individuales en destino
            $dbDestino = Database::getDestinoConnection();
            $stmtProg = $dbDestino->prepare("
                INSERT INTO sync_progress (job_id, tabla_nombre, total_registros_origen, registros_migrados, estado)
                VALUES (:job_id, :tabla_nombre, :total_registros_origen, 0, 'pendiente')
            ");

            foreach ($tablas as $t) {
                $stmtProg->execute([
                    'job_id' => $jobId,
                    'tabla_nombre' => $t['nombre'],
                    'total_registros_origen' => $t['registros']
                ]);
            }

            // Registrar log inicial
            SyncModel::log(
                'info',
                $nombreCliente,
                $schema,
                "Sincronización iniciada para el cliente. Total tablas a migrar: " . count($tablas),
                null,
                $jobId
            );

            echo json_encode([
                'success' => true,
                'job_id' => $jobId,
                'cliente' => $nombreCliente,
                'schema' => $schema,
                'tablas' => $tablas
            ]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sincroniza un lote (bloque) de registros para una tabla específica.
     */
    public function sincronizarTabla(): void {
        header('Content-Type: application/json');
        try {
            $jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
            $tabla = isset($_GET['tabla']) ? trim($_GET['tabla']) : '';
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $limite = 500; // Tamaño del bloque por defecto

            if ($jobId <= 0 || empty($tabla)) {
                throw new Exception("Parámetros de sincronización incompletos.");
            }

            // 1. Obtener información del Job
            $dbDestinoPrincipal = Database::getDestinoConnection();
            $stmtJob = $dbDestinoPrincipal->prepare("
                SELECT j.*, c.nombre AS cliente_nombre 
                FROM sync_jobs j
                JOIN clientes c ON c.id = j.cliente_id
                WHERE j.id = :id
            ");
            $stmtJob->execute(['id' => $jobId]);
            $job = $stmtJob->fetch();

            if (!$job) {
                throw new Exception("El job de sincronización solicitado no existe.");
            }

            $schema = $job['schema_name'];
            $clienteNombre = $job['cliente_nombre'];

            // Conexiones PDO de esquemas de clientes
            $dbOrigenCliente = Database::getClienteConnection($schema, 'origen');
            $dbDestinoCliente = Database::getClienteConnection($schema, 'destino');

            // 2. Si offset es 0, inicializar la estructura de la tabla (Fase 2)
            if ($offset === 0) {
                // Actualizar progreso a 'en_progreso'
                $stmtUpProg = $dbDestinoPrincipal->prepare("
                    UPDATE sync_progress 
                    SET estado = 'en_progreso', fecha_inicio = NOW() 
                    WHERE job_id = :job_id AND tabla_nombre = :tabla
                ");
                $stmtUpProg->execute(['job_id' => $jobId, 'tabla' => $tabla]);

                // Preparar estructura en destino (crear si no existe, o vaciar si existe)
                $this->prepararTablaDestino($dbOrigenCliente, $dbDestinoCliente, $tabla);
                
                SyncModel::log(
                    'info',
                    $clienteNombre,
                    $schema,
                    "Estructura preparada para la tabla `$tabla` en destino.",
                    null,
                    $jobId
                );
            }

            // 3. Obtener registros de origen de acuerdo al offset (interpolar enteros sanitizados)
            $limiteSeguro = (int)$limite;
            $offsetSeguro = (int)$offset;
            $stmtFetch = $dbOrigenCliente->query("SELECT * FROM `$tabla` LIMIT $limiteSeguro OFFSET $offsetSeguro");
            $registros = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);
            $registrosLeidos = count($registros);

            if ($registrosLeidos > 0) {
                // 4. Inserción en lote (Fase 3) dentro de una transacción para máxima velocidad
                $dbDestinoCliente->beginTransaction();
                try {
                    $columnas = array_keys($registros[0]);
                    $columnasEscapadas = array_map(fn($col) => "`$col`", $columnas);
                    $campos = implode(', ', $columnasEscapadas);
                    $placeholders = implode(', ', array_fill(0, count($columnas), '?'));
                    
                    $sql = "INSERT INTO `$tabla` ($campos) VALUES ($placeholders)";
                    $stmtInsert = $dbDestinoCliente->prepare($sql);

                    foreach ($registros as $row) {
                        $stmtInsert->execute(array_values($row));
                    }
                    $dbDestinoCliente->commit();
                } catch (Exception $ex) {
                    $dbDestinoCliente->rollBack();
                    throw new Exception("Error al insertar lote en destino: " . $ex->getMessage());
                }
            }

            // 5. Calcular nuevo offset y progreso
            $nuevoOffset = $offset + $registrosLeidos;
            
            // Consultar total en origen para ver si terminamos
            $stmtCount = $dbDestinoPrincipal->prepare("
                SELECT total_registros_origen 
                FROM sync_progress 
                WHERE job_id = :job_id AND tabla_nombre = :tabla
            ");
            $stmtCount->execute(['job_id' => $jobId, 'tabla' => $tabla]);
            $totalOrigen = (int)$stmtCount->fetchColumn();

            $completado = ($registrosLeidos === 0 || $nuevoOffset >= $totalOrigen);

            if ($completado) {
                // Marcar tabla como completada
                $stmtComp = $dbDestinoPrincipal->prepare("
                    UPDATE sync_progress 
                    SET estado = 'completado', registros_migrados = :migrados, ultimo_offset = :offset, fecha_fin = NOW()
                    WHERE job_id = :job_id AND tabla_nombre = :tabla
                ");
                $stmtComp->execute([
                    'migrados' => $nuevoOffset,
                    'offset' => $nuevoOffset,
                    'job_id' => $jobId,
                    'tabla' => $tabla
                ]);

                // Actualizar contador de tablas completadas en el Job
                $dbDestinoPrincipal->exec("
                    UPDATE sync_jobs 
                    SET tablas_completadas = tablas_completadas + 1 
                    WHERE id = $jobId
                ");

                SyncModel::log(
                    'info',
                    $clienteNombre,
                    $schema,
                    "Tabla `$tabla` sincronizada completamente. Total registros: $nuevoOffset.",
                    null,
                    $jobId
                );
            } else {
                // Actualizar offset y registros migrados
                $stmtUpd = $dbDestinoPrincipal->prepare("
                    UPDATE sync_progress 
                    SET registros_migrados = :migrados, ultimo_offset = :offset
                    WHERE job_id = :job_id AND tabla_nombre = :tabla
                ");
                $stmtUpd->execute([
                    'migrados' => $nuevoOffset,
                    'offset' => $nuevoOffset,
                    'job_id' => $jobId,
                    'tabla' => $tabla
                ]);
            }

            echo json_encode([
                'success' => true,
                'completado' => $completado,
                'registros_migrados' => $nuevoOffset,
                'total_registros' => $totalOrigen
            ]);

        } catch (Exception $e) {
            // Intentar marcar la tabla como fallida en el progreso de control
            try {
                if (isset($dbDestinoPrincipal) && $jobId > 0 && !empty($tabla)) {
                    $stmtFail = $dbDestinoPrincipal->prepare("
                        UPDATE sync_progress 
                        SET estado = 'fallido', fecha_fin = NOW()
                        WHERE job_id = :job_id AND tabla_nombre = :tabla
                    ");
                    $stmtFail->execute([
                        'job_id' => $jobId,
                        'tabla' => $tabla
                    ]);
                    
                    SyncModel::log(
                        'error',
                        $clienteNombre ?? 'Desconocido',
                        $schema ?? '',
                        "Error al sincronizar tabla `$tabla`: " . $e->getMessage(),
                        $e->getTraceAsString(),
                        $jobId
                    );
                }
            } catch (Exception $exLog) {
                // Prevenir que un error de log altere la respuesta original
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Finaliza exitosamente el Job de sincronización.
     */
    public function completarJob(): void {
        header('Content-Type: application/json');
        try {
            $jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
            if ($jobId <= 0) {
                throw new Exception("ID de job inválido.");
            }

            // Obtener datos del job
            $dbDest = Database::getDestinoConnection();
            $stmt = $dbDest->prepare("
                SELECT j.*, c.nombre AS cliente_nombre 
                FROM sync_jobs j
                JOIN clientes c ON c.id = j.cliente_id
                WHERE j.id = :id
            ");
            $stmt->execute(['id' => $jobId]);
            $job = $stmt->fetch();

            if (!$job) {
                throw new Exception("El job solicitado no existe.");
            }

            SyncModel::updateSyncJobStatus($jobId, 'completado');

            SyncModel::log(
                'info',
                $job['cliente_nombre'],
                $job['schema_name'],
                "Proceso de sincronización finalizado exitosamente.",
                null,
                $jobId
            );

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Registra un fallo en el Job de sincronización.
     */
    public function fallarJob(): void {
        header('Content-Type: application/json');
        try {
            $jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
            $errorMsg = isset($_POST['error_mensaje']) ? trim($_POST['error_mensaje']) : 'Error desconocido.';

            if ($jobId <= 0) {
                throw new Exception("ID de job inválido.");
            }

            // Obtener datos del job
            $dbDest = Database::getDestinoConnection();
            $stmt = $dbDest->prepare("
                SELECT j.*, c.nombre AS cliente_nombre 
                FROM sync_jobs j
                JOIN clientes c ON c.id = j.cliente_id
                WHERE j.id = :id
            ");
            $stmt->execute(['id' => $jobId]);
            $job = $stmt->fetch();

            if (!$job) {
                throw new Exception("El job solicitado no existe.");
            }

            SyncModel::updateSyncJobStatus($jobId, 'fallido', $errorMsg);

            // Marcar tablas pendientes/en_progreso como fallidas
            $stmtProg = $dbDest->prepare("
                UPDATE sync_progress 
                SET estado = 'fallido' 
                WHERE job_id = :job_id AND estado IN ('pendiente', 'en_progreso')
            ");
            $stmtProg->execute(['job_id' => $jobId]);

            SyncModel::log(
                'error',
                $job['cliente_nombre'],
                $job['schema_name'],
                "Proceso de sincronización falló: $errorMsg",
                null,
                $jobId
            );

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Obtiene los logs más recientes en formato JSON.
     */
    public function getLogs(): void {
        header('Content-Type: application/json');
        try {
            $jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : null;
            $schema = isset($_GET['schema']) ? trim($_GET['schema']) : null;
            
            $logs = SyncModel::getLogs($jobId, $schema);
            echo json_encode(['success' => true, 'data' => $logs]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Valida y prepara la estructura de la tabla en destino (Fase 2).
     */
    private function prepararTablaDestino(PDO $dbOrigen, PDO $dbDestino, string $tabla): void {
        // Verificar si la tabla existe en destino de forma ultra compatible
        try {
            $dbDestino->query("SELECT 1 FROM `$tabla` LIMIT 1");
            $existe = true;
        } catch (Exception $e) {
            $existe = false;
        }

        if (!$existe) {
            // Obtener sentencia SQL de creación en origen
            $stmtCreate = $dbOrigen->query("SHOW CREATE TABLE `$tabla`");
            $row = $stmtCreate->fetch();
            $createSql = $row['Create Table'];
            
            // Ejecutar creación en destino
            $dbDestino->exec($createSql);
        } else {
            // Si la tabla ya existe, la vaciamos (clean truncate)
            $dbDestino->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $dbDestino->exec("TRUNCATE TABLE `$tabla`;");
            $dbDestino->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    }

    /**
     * Sincroniza el catálogo de clientes y esquemas desde la base de datos de origen a la de destino.
     */
    public function sincronizarCatalogo(): void {
        header('Content-Type: application/json');
        try {
            $dbOrigen = Database::getOrigenConnection();
            $dbDestino = Database::getDestinoConnection();

            // 1. Sincronizar la estructura de las tablas de catálogo si no existen en destino
            $this->prepararTablaDestino($dbOrigen, $dbDestino, 'clientes');
            $this->prepararTablaDestino($dbOrigen, $dbDestino, 'squemas');

            // 2. Replicar los registros de la tabla 'clientes'
            $this->replicarTablaCatalogo($dbOrigen, $dbDestino, 'clientes');

            // 3. Replicar los registros de la tabla 'squemas'
            $this->replicarTablaCatalogo($dbOrigen, $dbDestino, 'squemas');

            echo json_encode(['success' => true, 'mensaje' => 'Catálogo de clientes y esquemas sincronizado con éxito.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Copia los registros de una tabla de catálogo origen a destino.
     */
    private function replicarTablaCatalogo(PDO $dbOrigen, PDO $dbDestino, string $tabla): void {
        // Obtener registros de origen
        $stmtFetch = $dbOrigen->query("SELECT * FROM `$tabla`");
        $registros = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

        // Desactivar temporalmente llaves foráneas en destino para vaciar e insertar
        $dbDestino->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $dbDestino->exec("TRUNCATE TABLE `$tabla`;");

        if (!empty($registros)) {
            $columnas = array_keys($registros[0]);
            $columnasEscapadas = array_map(fn($col) => "`$col`", $columnas);
            $campos = implode(', ', $columnasEscapadas);
            $placeholders = implode(', ', array_fill(0, count($columnas), '?'));

            $sql = "INSERT INTO `$tabla` ($campos) VALUES ($placeholders)";
            $stmtInsert = $dbDestino->prepare($sql);

            foreach ($registros as $row) {
                // Si la tabla es 'squemas', forzamos el valor del campo 'squema' a minúsculas
                if ($tabla === 'squemas' && isset($row['squema'])) {
                    $row['squema'] = strtolower($row['squema']);
                }
                $stmtInsert->execute(array_values($row));
            }
        }
        $dbDestino->exec("SET FOREIGN_KEY_CHECKS = 1;");
    }

    /**
     * Procesa la autenticación del usuario utilizando la tabla usuarios en destino.
     */
    public function login(): void {
        header('Content-Type: application/json');
        try {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                throw new Exception("El usuario y la contraseña son requeridos.");
            }
            
            $dbDestino = Database::getDestinoConnection();
            
            $stm = $dbDestino->prepare("
                SELECT c.id as cliente_id, c.nombre as cliente, r.rol, r.id as rol_id, s.squema,  
                CONCAT(u.nombres,' ',u.apellidos) as FullName, u.id as user_id, u.username as username, 
                u.identificacion as cc, u.cargo_id as cargo_id, u.password as hashed_password
                FROM usuarios u
                LEFT JOIN clientes c ON u.cliente_id = c.id 
                LEFT JOIN rols r ON u.rol_id = r.id 
                LEFT JOIN squemas s ON u.squema_id = s.id 
                WHERE u.username = :username 
                AND u.estado = '1'
            ");

            $stm->execute(['username' => $username]);
            $user = $stm->fetch(PDO::FETCH_OBJ);

            if ($user && password_verify($password, $user->hashed_password)) {
                // Verificar si el usuario tiene privilegios de administrador
                $rol = strtolower($user->rol ?? '');
                $esAdmin = (strpos($rol, 'admin') !== false) || ($user->rol_id == 1);
                
                if (!$esAdmin) {
                    throw new Exception("Acceso denegado. Este módulo es exclusivo para administradores de TI.");
                }
                
                $_SESSION['migrador_user'] = [
                    'id' => $user->user_id,
                    'nombre' => $user->FullName,
                    'rol' => $user->rol,
                    'username' => $user->username
                ];
                
                echo json_encode(['success' => true, 'mensaje' => 'Autenticación exitosa.']);
            } else {
                throw new Exception("Credenciales incorrectas o usuario inactivo.");
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Cierra la sesión activa.
     */
    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        session_destroy();
        header("Location: index.php");
        exit;
    }
}
