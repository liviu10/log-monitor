<?php

declare(strict_types=1);

return [
    'connections' => [
        'mysql' => [
            'host' => $_ENV['DB_HOST'] ?? 'db',
            'database' => $_ENV['DB_DATABASE'] ?? 'log_monitor',
            'username' => $_ENV['DB_USERNAME'] ?? 'log_monitor_user',
            'password' => $_ENV['DB_PASSWORD'] ?? 'password',
            'port' => $_ENV['DB_PORT'] ?? '3306',
        ],
    ],
];
