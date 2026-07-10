<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

$lang = $_GET['lang'] ?? 'ro';
if (in_array($lang, ['ro', 'en'], true)) {
    $_SESSION['app_lang'] = $lang;
}

// Prevent Open Redirect (Basic HTTP referer URL validation for security)
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
$allowedHost = parse_url(APP_URL, PHP_URL_HOST);
$refererHost = parse_url($referer, PHP_URL_HOST);

// If the external referer does not match our application host, redirect safely to index.php
if ($refererHost !== null && $refererHost !== $allowedHost) {
    $referer = 'index.php';
}

header('Location: '.$referer);
exit;
