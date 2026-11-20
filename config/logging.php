<?php

declare(strict_types=1);

return [
    'api_key' => $_ENV['LOG_API_KEY'] ?? null,
    'server_url' => $_ENV['LOG_SERVER_URL'] ?? null,
];
