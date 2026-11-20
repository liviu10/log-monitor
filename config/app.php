<?php

declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'LogMonitor',
    'env' => $_ENV['APP_ENV'] ?? 'development',
    'key' => $_ENV['APP_KEY'] ?? 'logmonitor-secret-key-32-chars!!',
    'url' => $_ENV['APP_URL'] ?? 'https://log-monitor.insightdevhub.ro',
    'nonce' => defined('APP_NONCE') ? APP_NONCE : ($_ENV['APP_NONCE'] ?? ''),
    'locale' => $_ENV['APP_LOCALE'] ?? 'en',
    'fallback_locale' => $_ENV['APP_FALLBACK_LOCALE'] ?? 'en',
    'supported_locales' => [
        'en' => 'English',
        'ro' => 'Romana',
    ],
    'dashboard_stats_cache_ttl' => 15,
    'branding' => [
        'logo' => $_ENV['APP_LOGO'] ?? '/assets/images/logo.svg?v=1.0',
        'favicon' => $_ENV['APP_FAVICON'] ?? '/favicon.svg',
        'description' => $_ENV['APP_DESCRIPTION'] ?? 'Centralized asynchronous logging, monitoring, and alerting platform for PHP applications.',
    ],
    'queue' => [
        'max_attempts' => isset($_ENV['QUEUE_MAX_ATTEMPTS']) && is_numeric($_ENV['QUEUE_MAX_ATTEMPTS']) ? (int) $_ENV['QUEUE_MAX_ATTEMPTS'] : 5,
        'backoff_delays' => isset($_ENV['QUEUE_BACKOFF_DELAYS']) && is_string($_ENV['QUEUE_BACKOFF_DELAYS']) && trim($_ENV['QUEUE_BACKOFF_DELAYS']) !== ''
            ? array_map('intval', explode(',', $_ENV['QUEUE_BACKOFF_DELAYS']))
            : [1, 5, 20, 60],
        'purge_days' => isset($_ENV['QUEUE_PURGE_DAYS']) && is_numeric($_ENV['QUEUE_PURGE_DAYS']) ? (int) $_ENV['QUEUE_PURGE_DAYS'] : 15,
    ],
    'logs' => [
        'retention_days' => isset($_ENV['LOG_RETENTION_DAYS']) && is_numeric($_ENV['LOG_RETENTION_DAYS']) ? (int) $_ENV['LOG_RETENTION_DAYS'] : 30,
    ],
];
