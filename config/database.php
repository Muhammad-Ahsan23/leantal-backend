<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'pgsql_us'),

    'connections' => [

        'pgsql_us' => [
            'driver' => 'pgsql',
            'host' => env('DB_US_HOST', '127.0.0.1'),
            'port' => env('DB_US_PORT', '5432'),
            'database' => env('DB_US_DATABASE', 'leantal'),
            'username' => env('DB_US_USERNAME', 'postgres'),
            'password' => env('DB_US_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'pgsql_eu' => [
            'driver' => 'pgsql',
            'host' => env('DB_EU_HOST', '127.0.0.1'),
            'port' => env('DB_EU_PORT', '5432'),
            'database' => env('DB_EU_DATABASE', 'leantal'),
            'username' => env('DB_EU_USERNAME', 'postgres'),
            'password' => env('DB_EU_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'pgsql_uk' => [
            'driver' => 'pgsql',
            'host' => env('DB_UK_HOST', '127.0.0.1'),
            'port' => env('DB_UK_PORT', '5432'),
            'database' => env('DB_UK_DATABASE', 'leantal'),
            'username' => env('DB_UK_USERNAME', 'postgres'),
            'password' => env('DB_UK_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'routing_db' => [
            'driver' => 'pgsql',
            'host' => env('DB_ROUTING_HOST', '127.0.0.1'),
            'port' => env('DB_ROUTING_PORT', '5432'),
            'database' => env('DB_ROUTING_DATABASE', 'leantal'),
            'username' => env('DB_ROUTING_USERNAME', 'postgres'),
            'password' => env('DB_ROUTING_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

        'admin_db' => [
            'driver' => 'pgsql',
            'host' => env('DB_ADMIN_HOST', '127.0.0.1'),
            'port' => env('DB_ADMIN_PORT', '5432'),
            'database' => env('DB_ADMIN_DATABASE', 'leantal'),
            'username' => env('DB_ADMIN_USERNAME', 'postgres'),
            'password' => env('DB_ADMIN_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ],

    ],

    'migrations' => 'migrations',

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],

];
