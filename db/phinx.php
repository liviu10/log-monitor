<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$dbHost = $_ENV['DB_HOST'] ?? 'db';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbName = $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'log_monitor';
$dbUser = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'user';
$dbPass = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? 'password';

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'port' => $dbPort,
            'charset' => 'utf8mb4',
        ],
        'development' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass,
            'port' => $dbPort,
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
