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
     * Obtiene la conexión PDO al esquema (base de datos) de un cliente específico
     */
    public static function getClienteConnection(string $schema, string $conexionKey): PDO {
        return self::connect($conexionKey, $schema);
    }

    /**
     * Crea una conexión PDO basada en la clave de configuración y opcionalmente un esquema de cliente
     */
    private static function connect(string $key, ?string $schema = null): PDO {
        // Cargar archivo de configuración
        $configPath = dirname(__DIR__) . '/config/config.php';
        if (!file_exists($configPath)) {
            throw new Exception("El archivo de configuración no existe en: $configPath");
        }

        $config = require $configPath;
        if (!isset($config[$key])) {
            throw new Exception("La configuración para la conexión '$key' no está definida.");
        }

        $dbConfig = $config[$key];
        
        // Si no se especifica esquema de cliente, usa el de la base principal
        $dbName = $dbConfig['prefix'] . ($schema ?? $dbConfig['name']);
        
        $isDev = $config['is_dev'] ?? false;
        
        // En local agregamos un sufijo para separar origen y destino físicamente en el mismo servidor MySQL
        if ($isDev && $schema !== null) {
            $dbName .= '_' . $key;
        }

        // Si es la conexión de destino, forzamos el nombre de la DB a minúsculas para compatibilidad con el VPS Linux
        if ($key === 'destino' && $schema !== null) {
            $dbName = strtolower($dbName);
        }
        
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
            // Si el error es "Unknown database" (1049) y es la conexión de destino, intentamos crearla automáticamente
            $isUnknownDb = ($e->getCode() === 1049) || (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1049);
            if ($isUnknownDb && $key === 'destino' && $schema !== null) {
                try {
                    // Conectar al servidor MySQL sin especificar la base de datos
                    $dsnPrincipal = "mysql:host=$host;charset=$charset";
                    $pdoPrincipal = new PDO($dsnPrincipal, $user, $pass, $options);
                    
                    // Crear la base de datos
                    $pdoPrincipal->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                    
                    // Reintentar la conexión original
                    return new PDO($dsn, $user, $pass, $options);
                } catch (PDOException $ex) {
                    throw new Exception("Error al intentar crear automáticamente la base de datos de destino '$dbName@$host': " . $ex->getMessage() . " (Error original: " . $e->getMessage() . ")", (int)$e->getCode());
                }
            }
            throw new Exception("Error al conectar a la base de datos '$key' ($dbName@$host): " . $e->getMessage(), (int)$e->getCode());
        }
    }
}
