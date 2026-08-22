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
        $dbName = $dbConfig['prefix'] . ($schema ?? $dbConfig['name']);
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
     * Obtiene la conexión PDO al esquema (base de datos) de un cliente específico
     */
    public static function getClienteConnection(string $schema, string $conexionKey): PDO {
        return self::connect($conexionKey, $schema);
    }

    /**
     * Valida si la base de datos de un cliente existe en el servidor destino.
     * Si no existe, intenta crearla usando las credenciales administrativas (si están configuradas)
     * y otorga los privilegios necesarios al usuario de operación normal.
     */
    public static function ensureClientDatabaseExists(string $schema, string $key = 'destino'): bool {
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
        $adminUser = $dbConfig['admin_user'] ?? $user;
        $adminPass = $dbConfig['admin_password'] ?? $pass;
        $charset = $dbConfig['charset'] ?? 'utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // 1. Probar primero conexión directa a la base de datos del cliente con el usuario de trabajo
        $dsn = "mysql:host=$host;dbname=$dbName;charset=$charset";
        try {
            new PDO($dsn, $user, $pass, $options);
            return true; // La base de datos existe y el usuario de trabajo tiene acceso
        } catch (PDOException $e) {
            // Continuar con el proceso de verificación y creación administrativa
        }

        // 2. Conectar al servidor MySQL usando credenciales administrativas
        $pdoServer = null;
        $activeAdminUser = $adminUser;
        $adminConnectError = null;

        // Intentar primero con el usuario administrador si está definido
        if (!empty($adminUser)) {
            try {
                $dsnServer = "mysql:host=$host;charset=$charset";
                $pdoServer = new PDO($dsnServer, $adminUser, $adminPass, $options);
                $activeAdminUser = $adminUser;
            } catch (PDOException $exServer) {
                // Probar con la base principal
                $mainDb = $dbConfig['prefix'] . $dbConfig['name'];
                try {
                    $dsnMain = "mysql:host=$host;dbname=$mainDb;charset=$charset";
                    $pdoServer = new PDO($dsnMain, $adminUser, $adminPass, $options);
                    $activeAdminUser = $adminUser;
                } catch (PDOException $exMain) {
                    $adminConnectError = "El usuario admin '$adminUser' no pudo conectar a MySQL (" . $exMain->getMessage() . ")";
                }
            }
        }

        // Si falló el admin y el usuario normal es diferente, intentar con el usuario normal
        if (!$pdoServer && $user !== $adminUser) {
            try {
                $dsnServer = "mysql:host=$host;charset=$charset";
                $pdoServer = new PDO($dsnServer, $user, $pass, $options);
                $activeAdminUser = $user;
            } catch (PDOException $exServer) {
                $mainDb = $dbConfig['prefix'] . $dbConfig['name'];
                try {
                    $dsnMain = "mysql:host=$host;dbname=$mainDb;charset=$charset";
                    $pdoServer = new PDO($dsnMain, $user, $pass, $options);
                    $activeAdminUser = $user;
                } catch (PDOException $exMain) {
                    // No pudo conectar con ninguno
                }
            }
        }

        if (!$pdoServer) {
            $msg = "No se pudo conectar al servidor MySQL de $key ($host) para verificar/crear la base de datos '$dbName'.";
            if ($adminConnectError) {
                $msg .= " Nota: $adminConnectError.";
            }
            throw new Exception($msg);
        }

        // 3. Comprobar si la base de datos existe en el servidor
        $dbExiste = false;
        try {
            $stmt = $pdoServer->prepare("
                SELECT SCHEMA_NAME 
                FROM INFORMATION_SCHEMA.SCHEMATA 
                WHERE LOWER(SCHEMA_NAME) = LOWER(:dbname)
            ");
            $stmt->execute(['dbname' => $dbName]);
            $dbExiste = (bool)$stmt->fetchColumn();
        } catch (Exception $exInfo) {
            try {
                $stmt = $pdoServer->query("SHOW DATABASES LIKE '$dbName'");
                $dbExiste = (bool)$stmt->fetchColumn();
            } catch (Exception $exShow) {
                $dbExiste = false;
            }
        }

        // 4. Si la base de datos NO existe, intentar crearla
        if (!$dbExiste) {
            try {
                $pdoServer->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            } catch (PDOException $exCreate) {
                $extraNote = $adminConnectError ? " (Aviso: $adminConnectError)" : "";
                throw new Exception(
                    "La base de datos '$dbName' no existe en el destino ($host) y no se pudo crear automáticamente con el usuario '$activeAdminUser'.$extraNote " .
                    "Detalle MySQL: " . $exCreate->getMessage() . ". " .
                    "Solución: Cree la base de datos '$dbName' manualmente desde Virtualmin / phpMyAdmin o configure un usuario administrativo de MySQL válido en config.php."
                );
            }
        }

        // 5. Si la base de datos existe o fue creada, asegurar permisos para el usuario normal ($user)
        if ($activeAdminUser !== $user) {
            try {
                $pdoServer->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$user'@'localhost';");
            } catch (Exception $ignore) {}
            try {
                $pdoServer->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$user'@'127.0.0.1';");
            } catch (Exception $ignore) {}
            try {
                $pdoServer->exec("FLUSH PRIVILEGES;");
            } catch (Exception $ignore) {}
        }

        // 6. Probar finalmente la conexión con el usuario de trabajo normal ($user)
        try {
            new PDO($dsn, $user, $pass, $options);
            return true;
        } catch (PDOException $exConn) {
            throw new Exception(
                "La base de datos '$dbName' existe o fue creada en el destino ($host), pero el usuario de trabajo '$user' no tiene permisos para acceder a ella. " .
                "Detalle MySQL: " . $exConn->getMessage() . ". " .
                "Solución: Asigne al usuario '$user' todos los privilegios sobre la base de datos '$dbName' en Virtualmin / phpMyAdmin."
            );
        }
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
