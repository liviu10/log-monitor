<?php

declare(strict_types=1);

// Router pentru serverul integrat PHP (php -S), folosit de HttpClientTest.

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

if ($path === '/echo') {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with((string) $key, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
    }

    header('Content-Type: application/json');
    header('X-Multi: a', false);
    header('X-Multi: b', false);
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'query' => $_GET,
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ]);

    return true;
}

if (preg_match('#^/status/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    header('Content-Type: application/json');
    echo '{"error":"date personale in raspuns: 1800101123456"}';

    return true;
}

if ($path === '/redirect') {
    header('Location: /echo?redirected=1', true, 302);

    return true;
}

if ($path === '/redirect-file') {
    header('Location: file:///etc/passwd', true, 302);

    return true;
}

if ($path === '/slow') {
    sleep(3);
    echo 'tarziu';

    return true;
}

if ($path === '/invalid-json') {
    header('Content-Type: application/json');
    echo '{invalid';

    return true;
}

http_response_code(404);
echo 'not found';

return true;
