<?php

declare(strict_types=1);

return [
    'host' => $_ENV['PROXY_HOST'] ?? null,
    'port' => $_ENV['PROXY_PORT'] ?? null,
];
