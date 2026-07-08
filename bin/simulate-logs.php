<?php

declare(strict_types=1);

/**
 * Script ultra-simplificat pentru simularea a 5000 de loguri.
 * Forteaza o aplicatie de test in DB indiferent de ce exista deja acolo
 * si trimite logurile instant.
 */

set_time_limit(0);
ini_set('memory_limit', '256M');

// Incarcam bootstrap-ul pentru acces la baza de date
require_once dirname(__DIR__) . '/bootstrap.php';

// Limitam executia doar in mediul de development
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
if (strtolower($appEnv) !== 'development') {
    fwrite(STDERR, "Error: This simulation script can only run in a 'development' environment. Current: '{$appEnv}'\n");
    exit(1);
}

use App\Utilities\MySQLWrapper;

$url = 'http://app/api/log.php';
$totalLogs = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 50000;
$testApiKey = 'c7c6b541234567890abcdef1234567890';

// Pasul 1: Fortam existenta aplicatiei de test in DB, no matter what
try {
    $db = MySQLWrapper::getInstance();
    $db->query("
        INSERT INTO apps (id, name, api_key, created_at) 
        VALUES (999, 'Forced Benchmark App', ?, NOW()) 
        ON DUPLICATE KEY UPDATE api_key = ?
    ", [$testApiKey, $testApiKey]);
    
    echo "Aplicatia de test a fost fortata cu succes in baza de date!\n";
} catch (\Throwable $e) {
    echo "EROARE CRITICA DB: " . $e->getMessage() . "\n";
    exit(1);
}

// Pasul 2: Initializare stiva cURL Multi pentru viteza
$mh = curl_multi_init();
$running = 0;
$sent = 0;
$success = 0;
$errors = 0;

echo "Se porneste ingestia rapida pentru {$totalLogs} loguri...\n";
$startTime = microtime(true);

// Structura fixa si curata pentru payload-ul de log
$payload = json_encode([
    'level' => 'INFO',
    'message' => '[Benchmark] Log de test generat automat pentru analiza de performanta',
    'context' => ['user_id' => rand(1, 1000), 'status' => 'active', 'gateway' => 'podman-rootless']
]);

// Helper pentru generarea rapida de handle-uri cURL
$createHandle = function() use ($url, $testApiKey, $payload) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: ' . $testApiKey,
        'Host: log-monitor.local'
    ]);
    return $ch;
};

// Umplem initial coada cu o concurenta echilibrata de 40 de conexiuni (sigura pentru VPS-uri limitate)
$concurrencyLimit = 40;
for ($i = 0; $i < $concurrencyLimit && $sent < $totalLogs; $i++) {
    $sent++;
    curl_multi_add_handle($mh, $createHandle());
}

// Executia paralela a cozii de tip rolling
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

        // Adaugam un nou request in coada daca mai avem loguri de trimis
        if ($sent < $totalLogs) {
            $sent++;
            curl_multi_add_handle($mh, $createHandle());
            // Un mic delay de 20 microsecunde pentru a proteja stiva TCP si CPU pe VPS-uri limitate
            usleep(20);
            while (($execrun = curl_multi_exec($mh, $running)) === CURLM_CALL_MULTI_PERFORM);
        }

        // Afisam progresul simplificat la fiecare 500 de loguri
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
echo "Simulare Finalizata! Rata Succes: " . number_format(($success / $totalLogs) * 100, 2) . "%\n";
echo "Timp Total: " . number_format($totalTime, 2) . " secunde\n";
echo "Viteza Medie: " . number_format($totalLogs / $totalTime, 2) . " req/s\n";
echo "-----------------------------------------\n";