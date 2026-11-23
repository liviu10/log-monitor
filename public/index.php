<?php

declare(strict_types=1);

/**
 * Single entry point in application (Front Controller)
 */
require_once dirname(__DIR__).'/config/bootstrap.php';

use App\Core\LogViaStream;
use App\Core\Router;
use App\Enums\LogLevel;

$router = new Router;

$registerRoutes = require_once dirname(__DIR__).'/routes/web.php';
if (is_callable($registerRoutes)) {
    $registerRoutes($router);
}

try {
    $rawMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $method = is_string($rawMethod) ? $rawMethod : 'GET';
    $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = is_string($rawUri) ? $rawUri : '/';

    $response = $router->dispatch($method, $uri);
    if (is_scalar($response)) {
        echo (string) $response;
    } elseif (is_object($response) && method_exists($response, '__toString')) {
        echo (string) $response;
    }
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code !== 404 && $code !== 403 && $code !== 429) {
        $code = 500;
    }
    http_response_code($code);

    if (! headers_sent()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai');
    }

    if ($code !== 404 && class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
        $logLevel = ($code === 500) ? LogLevel::ERROR->value : LogLevel::WARNING->value;
        LogViaStream::send($logLevel, 'Front Controller global dispatch error', [
            'location' => __FILE__,
            'line' => __LINE__,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'identifier' => 'Front_Controller_Global_Error',
        ]);
    }

    $genericMessage = match ($code) {
        403 => __('Access Denied'),
        404 => __('Page not found.'),
        429 => __('Too many requests'),
        default => __('Internal Server Error'),
    };

    try {
        echo view('errors.errors', [
            'code' => $code,
            'title' => __('Error').' '.$code,
            'message' => $genericMessage,
        ]);
    } catch (Throwable $err) {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            LogViaStream::send(LogLevel::CRITICAL->value, 'Error page template rendering failure', [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $err->getMessage(),
                'exception_file' => $err->getFile(),
                'exception_line' => $err->getLine(),
                'exception_trace' => $err->getTraceAsString(),
                'identifier' => 'Error_Template_Rendering_Failure',
            ]);
        }

        $errorTitle = __('Error').' '.$code;
        $rawAppName = config('app.name');
        $fallbackAppName = is_string($rawAppName) && $rawAppName !== '' ? htmlspecialchars($rawAppName, ENT_QUOTES, 'UTF-8') : 'LogMonitor';
        $fallbackBackText = __('Back to home page');
        echo <<<HTML
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai">
    <title>{$errorTitle} - {$fallbackAppName}</title>
    <link href="/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/app.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100" style="background: #090d16; color: #fff;">
    <div class="text-center">
        <h1 class="display-4 fw-bold text-danger">{$errorTitle}</h1>
        <p class="lead text-secondary">{$genericMessage}</p>
        <hr class="border-secondary my-4">
        <a href="/" class="btn btn-outline-light">{$fallbackBackText}</a>
    </div>
</body>
</html>
HTML;
    }
}
