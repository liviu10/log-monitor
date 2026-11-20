<?php

declare(strict_types=1);

$smtpPort = $_ENV['SMTP_PORT'] ?? 587;
$port = is_scalar($smtpPort) ? (int) $smtpPort : 587;

return [
    'host' => $_ENV['SMTP_HOST'] ?? '127.0.0.1',
    'port' => $port,
    'username' => $_ENV['SMTP_USERNAME'] ?? '',
    'password' => $_ENV['SMTP_PASSWORD'] ?? '',
    'from' => $_ENV['SMTP_FROM'] ?? 'noreply@localhost.com',
    'subject' => $_ENV['SMTP_SUBJECT'] ?? 'Automatic Notification',
    'cc' => $_ENV['SMTP_CC'] ?? '',
    'bcc' => $_ENV['SMTP_BCC'] ?? '',
];
