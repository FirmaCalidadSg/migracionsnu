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

        if ($key === 'destino' && $schema !== null) {
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
            return new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $errorCode = (int)($e->errorInfo[1] ?? $e->getCode());
            $isUnknownOrAccess = ($errorCode === 1049 || $errorCode === 1044);
            
            if ($isUnknownOrAccess && $key === 'destino' && $schema !== null) {
                try {
                    self::ensureClientDatabaseExists($schema, 'destino');
                    return new PDO($dsn, $user, $pass, $options);
                } catch (Exception $ex) {
                    throw new Exception("Error al conectar a la base de datos '$key' ($dbName@$host): " . $ex->getMessage(), (int)$e->getCode());
                }
            }
            throw new Exception("Error al conectar a la base de datos '$key' ($dbName@$host): " . $e->getMessage(), (int)$e->getCode());
        }
    }
}
