<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$lang = $_GET['lang'] ?? 'ro';
if (in_array($lang, ['ro', 'en'], true)) {
    $_SESSION['app_lang'] = $lang;
}

// Prevenire Open Redirect (Validare URL referer basic pentru securitate)
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
$allowedHost = parse_url(APP_URL, PHP_URL_HOST);
$refererHost = parse_url($referer, PHP_URL_HOST);

// Daca referer-ul extern nu coincide cu aplicatia noastra, redirectionam la index.php securizat
if ($refererHost !== null && $refererHost !== $allowedHost) {
    $referer = 'index.php';
}

header('Location: ' . $referer);
exit;