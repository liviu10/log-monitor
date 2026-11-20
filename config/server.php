<?php

declare(strict_types=1);

$peerAddr = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

$peerIsLocalProxy = filter_var($peerAddr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
$cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
$clientIp = ($peerIsLocalProxy && is_string($cfIp) && filter_var($cfIp, FILTER_VALIDATE_IP) !== false) ? $cfIp : $peerAddr;

$rawAppEnv = $_ENV['APP_ENV'] ?? '';
$isProductionEnv = is_string($rawAppEnv) && in_array(strtolower($rawAppEnv), ['production', 'prod'], true);
$appUrlHost = parse_url(is_string($_ENV['APP_URL'] ?? null) ? $_ENV['APP_URL'] : '', PHP_URL_HOST);
$httpHost = ($isProductionEnv && is_string($appUrlHost) && $appUrlHost !== '')
    ? $appUrlHost
    : ($_SERVER['HTTP_HOST'] ?? 'log-monitor.insightdevhub.ro');

return [
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '/',
    'remote_addr' => $clientIp,
    'http_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'http_host' => $httpHost,
    'server_port' => $_SERVER['SERVER_PORT'] ?? '8080',
    'http_referer' => $_SERVER['HTTP_REFERER'] ?? null,
    'http_x_csrf_token' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
    'https' => $_SERVER['HTTPS'] ?? null,
    'http_x_forwarded_proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? (isset($_SERVER['HTTP_CF_VISITOR']) && is_string($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], 'https') ? 'https' : null),
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? '',
    'request_time_float' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null,
];
