<?php
return [
    'is_dev' => false, // false para servidores online

    // Compatibilidad heredada (No la toques, es para la app local de destino)
    'database' => [
        'prefix' => 'fugzcdpo_',
        'name' => 'snu',
        'user' => 'snu',
        'password' => 'ntcAmZBoSB0rEgEfxn3gbEoA7',
        'host' => 'localhost',
        'charset' => 'utf8mb4'
    ],

    // Módulo de Sincronización (Le dice a la migración cómo conectar a ambos)
    'origen' => [
        'prefix' => 'fugzcdpo_',
        'name' => 'snu', // <-- Nombre real de la base de datos en origen (producción)
        'user' => 'fugzcdpo_snu', // Tu usuario de origen
        'password' => '?dXe0dmFlMcG', // Tu contraseña de origen
        'host' => 'snuquality.com', // <-- Cambiado de localhost a snuquality.com para salir a buscarla a internet
        'charset' => 'utf8mb4'
    ],
    'destino' => [
        'prefix' => 'fugzcdpo_',
        'name' => 'snu', // <-- Nombre real de la base de datos en destino (producción)
        'user' => 'fugzcdpo_snu', // Tu usuario de destino para operaciones normales
        'password' => 'ntcAmZBoSB0rEgEfxn3gbEoA7', // Tu contraseña de destino
        'admin_user' => 'root', // Usuario con acceso total para crear bases de datos y asignar permisos
        'admin_password' => 'cbyxbI7P8Cxoy1kp', // Contraseña de root en MySQL
        'host' => '127.0.0.1', // O localhost, ya que el destino es local al script
        'charset' => 'utf8mb4',
        'virtualmin' => [
            'enabled' => true,
            'domain' => 'snuquality.tech',
            'command' => 'sudo /usr/sbin/virtualmin'
        ]
    ]
];
