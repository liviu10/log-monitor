<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\AppSettingController;

$controller = new AppSettingController();

$inputData = $_POST;
$rawInput = file_get_contents('php://input');

if (!in_array($rawInput, ['', '0', false], true)) {
    $jsonValidate = function (string $json, int $depth = 512, int $flags = 0) {
        if (function_exists('json_validate')) {
            return json_validate($json, $depth, $flags);
        }
        $maxDepth = 0x7fffffff;
        if (0 !== $flags && \defined('JSON_INVALID_UTF8_IGNORE') && \JSON_INVALID_UTF8_IGNORE !== $flags) {
            throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
        }
        if ($depth <= 0) {
            throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
        }
        if ($depth > $maxDepth) {
            throw new \ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', $maxDepth));
        }
        json_decode($json, true, $depth, $flags);
        return \JSON_ERROR_NONE === json_last_error();
    };
    if ($jsonValidate($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if (is_array($jsonData)) {
            $inputData = array_merge($inputData, $jsonData);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => __('Invalid JSON payload provided.')]);
        exit;
    }
}

try {
    match ($_SERVER['REQUEST_METHOD'] ?? '') {
        'POST' => match ($_GET['action'] ?? null) {
            'delete' => $controller->delete($inputData),
            'update' => $controller->update($inputData),
            null     => $controller->store($inputData),
            default  => throw new InvalidArgumentException(__('Invalid POST action for settings.')),
        },
        'GET' => $controller->index($_GET),
        default => throw new RuntimeException(__('Unsupported HTTP method for settings.')),
    };
} catch (Throwable $throwable) {
    http_response_code(400);
    echo json_encode(['error' => $throwable->getMessage()]);
    exit;
}