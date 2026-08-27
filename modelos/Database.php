<?php

class Database {
    private static ?PDO $origenConn = null;
    private static ?PDO $destinoConn = null;

    /**
     * Obtiene la conexión PDO al servidor de origen (solo lectura)
     */
    public static function getOrigenConnection(): PDO {
        if (self::$origenConn === null) {
            self::$origenConn = self::connect('origen');
        }
        return self::$origenConn;
    }

    /**
     * Obtiene la conexión PDO al servidor de destino (lectura/escritura)
     */
    public static function getDestinoConnection(): PDO {
        if (self::$destinoConn === null) {
            self::$destinoConn = self::connect('destino');
        }
        return self::$destinoConn;
    }

    /**
     * Obtiene el nombre calculado de la base de datos para una clave y esquema dado.
     */
    public static function getDatabaseName(string $key, ?string $schema = null): string {
        $configPath = dirname(__DIR__) . '/config/config.php';
        if (!file_exists($configPath)) {
            throw new Exception("El archivo de configuración no existe en: $configPath");
        }

        $config = require $configPath;
        if (!isset($config[$key])) {
            throw new Exception("La configuración para la conexión '$key' no está definida.");
        }

        $dbConfig = $config[$key];
        $prefix = $dbConfig['prefix'] ?? '';

        if ($schema !== null) {
            if (!empty($prefix) && str_starts_with(strtolower($schema), strtolower($prefix))) {
                $dbName = $schema;
            } else {
                $dbName = $prefix . $schema;
            }
        } else {
            $dbName = $prefix . $dbConfig['name'];
        }

        $isDev = $config['is_dev'] ?? false;

        if ($isDev && $schema !== null) {
            $dbName .= '_' . $key;
        }

        if ($key === 'destino') {
            $dbName = strtolower($dbName);
        }

        return $dbName;
    }

    /**
     * Obtiene la conexión PDO al esquema (base de datos) de un cliente específico.
     * Si la conexión al origen falla con el nombre original, reintenta con minúsculas o mayúsculas.
     */
    public static function getClienteConnection(string $schema, string $conexionKey): PDO {
        try {
            return self::connect($conexionKey, $schema);
        } catch (Exception $e) {
            if ($conexionKey === 'origen') {
                // 1. Reintentar en minúsculas si el original tenía mayúsculas
                $schemaLower = strtolower($schema);
                if ($schemaLower !== $schema) {
                    try {
                        return self::connect($conexionKey, $schemaLower);
                    } catch (Exception $eLower) {}
                }

                // 2. Reintentar en mayúsculas
                $schemaUpper = strtoupper($schema);
                if ($schemaUpper !== $schema) {
                    try {
                        return self::connect($conexionKey, $schemaUpper);
                    } catch (Exception $eUpper) {}
                }
            }
            throw $e;
        }
    }

    /**
     * Valida si la base de datos de un cliente existe en el servidor destino.
     * Si no existe, la crea de forma idempotente, asegura permisos para el usuario
     * y la asocia al Virtual Server de Virtualmin.
     */
    public static function ensureClientDatabaseExists(string $schema, string $key = 'destino'): bool {
        require_once __DIR__ . '/DatabaseProvisioningService.php';

        // Solo el destino gestiona aprovisionamiento y Virtualmin
        if ($key !== 'destino') {
            $dbName = self::getDatabaseName($key, $schema);
            return DatabaseProvisioningService::databaseExists($dbName);
        }

        $res = DatabaseProvisioningService::provisionDatabase($schema);

        // Si falló completamente la creación física o de base de datos
        if (!$res['success'] && !$res['database_created'] && !DatabaseProvisioningService::databaseExists($res['database'])) {
            throw new Exception($res['message']);
        }

        return true;
    }

    /**
     * Comprueba si una excepción se debe a pérdida de conexión o límite de tamaño de paquete.
     */
    public static function isServerGoneException(Throwable $e): bool {
        $msg = strtolower($e->getMessage());
        $code = (int)$e->getCode();
        return (
            str_contains($msg, 'server has gone away') ||
            str_contains($msg, 'communication link failure') ||
            str_contains($msg, 'lost connection') ||
            str_contains($msg, 'packet bigger than') ||
            $code === 2006 ||
            $code === 2013 ||
            $code === 1153
        );
    }

    /**
     * Crea una conexión PDO basada en la clave de configuración y opcionalmente un esquema de cliente
     */
    private static function connect(string $key, ?string $schema = null): PDO {
        $configPath = dirname(__DIR__) . '/config/config.php';
        if (!file_exists($configPath)) {
            throw new Exception("El archivo de configuración no existe en: $configPath");
        }

        $config = require $configPath;
        if (!isset($config[$key])) {
            throw new Exception("La configuración para la conexión '$key' no está definida.");
        }

        $dbConfig = $config[$key];
        $dbName = self::getDatabaseName($key, $schema);
        $host = $dbConfig['host'];
        $user = $dbConfig['user'];
        $pass = $dbConfig['password'];
        $charset = $dbConfig['charset'] ?? 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$dbName;charset=$charset";
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            
            // Ampliar max_allowed_packet y timeouts en la sesión para evitar MySQL server has gone away (2006/1153)
            try {
                $pdo->exec("SET SESSION max_allowed_packet = 1073741824;");
                $pdo->exec("SET SESSION net_read_timeout = 3600;");
                $pdo->exec("SET SESSION net_write_timeout = 3600;");
                $pdo->exec("SET SESSION wait_timeout = 28800;");
            } catch (Exception $exOpt) {}

            return $pdo;
        } catch (PDOException $e) {
            $errorCode = (int)($e->errorInfo[1] ?? $e->getCode());
            $isUnknownOrAccess = ($errorCode === 1049 || $errorCode === 1044);
            
            if ($isUnknownOrAccess && $key === 'destino' && $schema !== null) {
                try {
                    self::ensureClientDatabaseExists($schema, 'destino');
                    $pdo = new PDO($dsn, $user, $pass, $options);
                    try {
                        $pdo->exec("SET SESSION max_allowed_packet = 1073741824;");
                        $pdo->exec("SET SESSION net_read_timeout = 3600;");
                    } catch (Exception $exOpt) {}
                    return $pdo;
                } catch (Exception $ex) {
                    throw new Exception("Error al conectar a la base de datos '$key' ($dbName@$host): " . $ex->getMessage(), (int)$e->getCode());
                }
            }
            throw new Exception("Error al conectar a la base de datos '$key' ($dbName@$host): " . $e->getMessage(), (int)$e->getCode());
        }
    }
}
