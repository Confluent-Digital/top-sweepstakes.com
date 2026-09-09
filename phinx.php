<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once __DIR__ . '/vendor/autoload.php';

if (!isset($_ENV['DB_HOST'])) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$dbConfig = [
    'adapter' => $_ENV['DB_ADAPTER'] ?? 'mysql',
    'host' => $_ENV['DB_HOST'] ?? 'topsweepstakes_mariadb',
    'name' => $_ENV['DB_NAME'] ?? 'bd_top_sweepstakes',
    'user' => $_ENV['DB_USERNAME'] ?? 'topsweepstakes',
    'pass' => $_ENV['DB_PASSWORD'] ?? '',
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
        'seeds' => __DIR__ . '/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'dev',
        'dev' => $dbConfig,
        'prod' => $dbConfig,
    ],
    'version_order' => 'creation',
];
