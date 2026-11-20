<?php

declare(strict_types=1);

/**
 * Change Language File
 *
 * Allows users to change the application language securely.
 *
 * @category Frontend
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */

require_once __DIR__.'/bootstrap.php';

$supportedLocales = array_keys(supportedLocales());
$rawLocale = config('app.locale', 'en');
$default = is_string($rawLocale) && $rawLocale !== '' ? $rawLocale : 'en';

$lang = $_GET['lang'] ?? $default;
if (is_string($lang) && in_array($lang, $supportedLocales, true)) {
    $_SESSION['app_lang'] = $lang;
}

// Prevent Open Redirect (Basic referrer URL validation for security)
$rawAppUrl = defined('APP_URL') ? APP_URL : '';
$appUrl = is_scalar($rawAppUrl) ? (string) $rawAppUrl : '';
$rawReferer = $_SERVER['HTTP_REFERER'] ?? '/';
$referer = is_scalar($rawReferer) ? (string) $rawReferer : '/';
$allowedHost = parse_url($appUrl, PHP_URL_HOST);
$refererHost = parse_url($referer, PHP_URL_HOST);

// If the external referrer does not match our application, redirect to home page securely
if ($refererHost !== null && $refererHost !== $allowedHost) {
    $referer = '/';
}

header('Location: '.$referer);
exit;
