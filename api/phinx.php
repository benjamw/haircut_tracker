<?php

declare(strict_types=1);

// Phinx config. Connection comes from the same env vars as the app.
// db/init/*.sql is the baseline (fresh volumes); Phinx handles incremental
// changes to a populated database.
return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'      => 'docker',
        'docker' => [
            'adapter' => 'mysql',
            'host'    => getenv('DB_HOST') ?: 'db',
            'name'    => getenv('DB_NAME') ?: 'haircut_tracker',
            'user'    => getenv('DB_USER') ?: 'haircut',
            'pass'    => getenv('DB_PASSWORD') ?: 'haircut',
            'port'    => (int) (getenv('DB_PORT') ?: 3306),
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
