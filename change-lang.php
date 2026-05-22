<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$lang = $_GET['lang'] ?? 'ro';
if (in_array($lang, ['ro', 'en'])) {
    $_SESSION['app_lang'] = $lang;
}

$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $referer);
exit;
