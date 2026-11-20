<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__.'/../');
$dotenv->safeLoad();

$currentDbBranch = 'unknown';
$gitHeadFile = __DIR__.'/../.git/HEAD';
if (is_file($gitHeadFile)) {
    $headContent = file_get_contents($gitHeadFile);
    if (is_string($headContent)) {
        $parts = explode('/', trim($headContent));
        $end = end($parts);
        $currentDbBranch = ($end !== '') ? $end : 'unknown';
    }
}
$envDb = $_ENV['DB_DATABASE'] ?? 'log_monitor';
$baseDbName = is_scalar($envDb) ? (string) $envDb : 'log_monitor';
$baseDbName = str_replace('_dev', '', $baseDbName);

$rawAppEnv = $_ENV['APP_ENV'] ?? null;
$isProduction = is_string($rawAppEnv) && in_array(strtolower($rawAppEnv), ['production', 'prod'], true);

if ($isProduction || $currentDbBranch === 'master') {
    $_ENV['DB_DATABASE'] = $baseDbName;
} else {
    $_ENV['DB_DATABASE'] = $baseDbName.'_dev';
}

$dbHost = $_ENV['DB_HOST'] ?? 'db';
if ($dbHost === 'db' && gethostbyname('db') === 'db') {
    $dbHost = '127.0.0.1';
}

return [
    'paths' => [
        'migrations' => [
            '%%PHINX_CONFIG_DIR%%/../db/migrations',
        ],
        'seeds' => [
            '%%PHINX_CONFIG_DIR%%/../db/seeds',
        ],
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'name' => $_ENV['DB_DATABASE'],
            'user' => $_ENV['DB_USERNAME'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
        'development' => [
            'adapter' => 'mysql',
            'host' => $dbHost,
            'name' => $_ENV['DB_DATABASE'],
            'user' => $_ENV['DB_USERNAME'] ?? 'user',
            'pass' => $_ENV['DB_PASSWORD'] ?? 'password',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ],
    ],
    'version_order' => 'creation',
];
