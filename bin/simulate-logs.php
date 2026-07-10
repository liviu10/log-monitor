<?php

declare(strict_types=1);

/**
 * Ultra-simplified script to simulate log ingestion.
 * Forces a test application in the DB regardless of what already exists
 * and sends logs immediately.
 */
set_time_limit(0);
ini_set('memory_limit', '256M');

// Load bootstrap for database access
require_once dirname(__DIR__).'/bootstrap.php';

// Limit execution to the development environment only
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
if (strtolower($appEnv) !== 'development') {
    fwrite(STDERR, "Error: This simulation script can only run in a 'development' environment. Current: '{$appEnv}'\n");
    exit(1);
}

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;

$url = 'http://app/api/log.php';
$totalLogs = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 50000;
$testApiKey = 'c7c6b541234567890abcdef1234567890';

// Step 1: Force existence of the test application in DB, no matter what
try {
    $db = MySQLWrapper::getInstance();
    $db->query("
        INSERT INTO apps (id, name, api_key, created_at) 
        VALUES (999, 'Forced Benchmark App', ?, NOW()) 
        ON DUPLICATE KEY UPDATE api_key = ?
    ", [$testApiKey, $testApiKey]);

    echo "Aplicatia de test a fost fortata cu succes in baza de date!\n";
} catch (Throwable $e) {
    if (class_exists('App\\Utilities\\LogViaStream')) {
        LogViaStream::send(LogLevel::ERROR->value, 'CLI Benchmark App creation failure', [
            'location' => __METHOD__,
            'line' => __LINE__,
            'exception_message' => $e->getMessage(),
            'exception_file' => $e->getFile(),
            'exception_line' => $e->getLine(),
            'exception_trace' => $e->getTraceAsString(),
            'sql_statement' => 'INSERT INTO apps ON DUPLICATE KEY UPDATE',
            'sql_parameters' => [$testApiKey, $testApiKey],
            'identifier' => 'MySQLWrapper_Query_Failure',
        ]);
    }

    echo 'EROARE CRITICA DB: '.$e->getMessage()."\n";
    exit(1);
}

// Step 2: Initialize cURL Multi stack for speed
$mh = curl_multi_init();
$running = 0;
$sent = 0;
$success = 0;
$errors = 0;

echo "Se porneste ingestia rapida pentru {$totalLogs} loguri...\n";
$startTime = microtime(true);

// Clean and fixed structure for the log payload
$payload = json_encode([
    'level' => 'INFO',
    'message' => '[Benchmark] Log de test generat automat pentru analiza de performanta',
    'context' => ['user_id' => rand(1, 1000), 'status' => 'active', 'gateway' => 'podman-rootless'],
]);

// Helper for rapid generation of cURL handles
$createHandle = function () use ($url, $testApiKey, $payload) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: '.$testApiKey,
        'Host: log-monitor.local',
    ]);

    return $ch;
};

// Initially fill the queue with a balanced concurrency of 40 connections (safe for limited VPS hosts)
$concurrencyLimit = 40;
for ($i = 0; $i < $concurrencyLimit && $sent < $totalLogs; $i++) {
    $sent++;
    curl_multi_add_handle($mh, $createHandle());
}

// Parallel execution of the rolling queue
do {
    while (($execrun = curl_multi_exec($mh, $running)) === CURLM_CALL_MULTI_PERFORM);
    if ($execrun !== CURLM_OK) {
        break;
    }

    while ($done = curl_multi_info_read($mh)) {
        $ch = $done['handle'];
        $info = curl_getinfo($ch);

        if ($info['http_code'] === 202) {
            $success++;
        } else {
            $errors++;
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        // Add a new request to the queue if there are more logs to send
        if ($sent < $totalLogs) {
            $sent++;
            curl_multi_add_handle($mh, $createHandle());
            // A small delay of 20 microseconds to protect the TCP stack and CPU on limited VPS hosts
            usleep(20);
            while (($execrun = curl_multi_exec($mh, $running)) === CURLM_CALL_MULTI_PERFORM);
        }

        // Display simplified progress every 500 logs
        $completed = $success + $errors;
        if ($completed % 500 === 0) {
            printf("Progres: %d/%d (%.0f%%) | Succes (202): %d | Erori: %d\n",
                $completed, $totalLogs, ($completed / $totalLogs) * 100, $success, $errors);
        }
    }

    if ($running) {
        curl_multi_select($mh, 0.05);
    }
} while ($running || $sent < $totalLogs);

curl_multi_close($mh);
$totalTime = microtime(true) - $startTime;

echo "\n-----------------------------------------\n";
echo 'Simulare Finalizata! Rata Succes: '.number_format(($success / $totalLogs) * 100, 2)."%\n";
echo 'Timp Total: '.number_format($totalTime, 2)." secunde\n";
echo 'Viteza Medie: '.number_format($totalLogs / $totalTime, 2)." req/s\n";
echo "-----------------------------------------\n";
