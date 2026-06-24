<?php

declare(strict_types=1);

/**
 * Script pentru simularea trimiterii a 500.000 de loguri catre ingestor.
 * Modificat pentru a fi cat mai aproape de realitate:
 * - Simuleaza 4 aplicatii diferite cu profile si mesaje de log specifice.
 * - Simuleaza fluctuatii de trafic (burst-uri si perioade calme) prin variatia dinamica a concurentei si a jitter-ului.
 * - Mentine concurenta sub limita de siguranta a bazei de date (max_connections = 100) pentru a preveni erorile HTTP 500.
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

// Incarcam bootstrap-ul pentru a avea acces la baza de date si configurari
require_once dirname(__DIR__) . '/bootstrap.php';

use App\Utilities\MySQLWrapper;

$url = 'http://127.0.0.1/log.php';
$totalLogs = 500000;

// Asiguram existenta aplicatiilor de test in baza de date
try {
    $db = MySQLWrapper::getInstance();
    $existingApps = $db->read('apps');
    
    // Daca avem doar aplicatia implicita, adaugam inca 3 pentru simulare realista
    if (count($existingApps) <= 1) {
        $db->create('apps', [
            'name' => 'Frontend SPA App',
            'api_key' => 'f7c6b541234567890abcdef1234567890',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->create('apps', [
            'name' => 'Billing Gateway Service',
            'api_key' => 'a7c6b541234567890abcdef1234567890',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->create('apps', [
            'name' => 'Background Worker Scheduler',
            'api_key' => 'b7c6b541234567890abcdef1234567890',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        echo "Aplicatii aditionale create in baza de date pentru o simulare mai realista.\n";
    }
} catch (\Throwable $throwable) {
    echo "Eroare la initializarea aplicatiilor in DB: " . $throwable->getMessage() . "\n";
}

// Configuram aplicatiile cliente cu cheile lor API si profilele specifice
$apps = [
    [
        'name' => 'Main Website API',
        'key' => 'e7c6b541234567890abcdef1234567890',
        'levels' => ['INFO', 'DEBUG', 'WARNING', 'ERROR'],
        'messages' => [
            "User login successful for username=:username",
            "Database connection timeout on host=:host",
            "API request failed with status :status, response=:msg",
            "Settings updated by admin with key=:key value=:value",
        ],
        'contexts' => [
            ['username' => 'johndoe', 'ip' => '192.168.1.100'],
            ['host' => 'log-monitor-db-replica', 'port' => 3306, 'password' => 'secret_db_pass_123'],
            ['status' => 500, 'msg' => 'Internal Server Error', 'token' => 'jwt_token_abc123xyz'],
            ['key' => 'notification_channel', 'value' => 'email', 'auth_token' => 'auth_temp_xyz'],
        ]
    ],
    [
        'name' => 'Frontend SPA App',
        'key' => 'f7c6b541234567890abcdef1234567890',
        'levels' => ['INFO', 'DEBUG', 'WARNING'],
        'messages' => [
            "Component :component rendered in :time ms",
            "User clicked on button :button",
            "Failed to load static asset from url=:url",
            "Uncaught TypeError: Cannot read properties of undefined (reading ':field')",
        ],
        'contexts' => [
            ['component' => 'DashboardCharts', 'time' => 124],
            ['button' => 'submit_payment', 'page' => '/checkout'],
            ['url' => 'https://cdn.jsdelivr.net/npm/alpinejs@3.x/dist/cdn.min.js'],
            ['field' => 'email', 'file' => 'auth.js', 'line' => 42],
        ]
    ],
    [
        'name' => 'Billing Gateway Service',
        'key' => 'a7c6b541234567890abcdef1234567890',
        'levels' => ['INFO', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT'],
        'messages' => [
            "Payment processed successfully for transaction_id=:tx, amount=:amount",
            "Card validation failure: :reason",
            "Critical: Stripe API endpoint unreachable. Attempt :attempt",
            "Refund initiated for customer=:customer, order=:order",
        ],
        'contexts' => [
            ['tx' => 'tx_987654321', 'amount' => 99.99, 'card_number' => '4111-2222-3333-4444'],
            ['reason' => 'Expired Card', 'code' => 'err_expired', 'card_brand' => 'Visa'],
            ['attempt' => 3, 'timeout' => 5000, 'api_key' => 'sk_live_51N...'],
            ['customer' => 'cust_8f2h9s', 'order' => 10452],
        ]
    ],
    [
        'name' => 'Background Worker Scheduler',
        'key' => 'b7c6b541234567890abcdef1234567890',
        'levels' => ['INFO', 'DEBUG'],
        'messages' => [
            "Cron job :job started execution",
            "Job :job finished successfully in :duration seconds",
            "Cache cleared for tags :tags",
            "Pruning old records from table :table. Deleted :count rows",
        ],
        'contexts' => [
            ['job' => 'PruneExpiredTokensJob'],
            ['job' => 'GenerateDailyReportsJob', 'duration' => 12.8],
            ['tags' => 'settings,translations'],
            ['table' => 'log_queue', 'count' => 12405],
        ]
    ]
];

$mh = curl_multi_init();
$running = 0;
$sent = 0;
$success = 0;
$errors = 0;

echo "Se porneste simularea realista pentru {$totalLogs} loguri...\n";
echo "Vom simula fluctuatii de trafic (burst si calm) si multiple aplicatii.\n";
echo "Timpul estimat este de cateva minute. Monitorizati progresul mai jos.\n";
$startTime = microtime(true);
$peakRate = 0.0;

// Helper function pentru a crea un handle de cURL cu profile de aplicatie realiste
$createHandle = function(int $index) use ($url, $apps) {
    // Alegem o aplicatie la intamplare
    $app = $apps[array_rand($apps)];
    
    $level = $app['levels'][array_rand($app['levels'])];
    $msgTemplate = $app['messages'][array_rand($app['messages'])];
    $ctx = $app['contexts'][array_rand($app['contexts'])];

    $message = $msgTemplate;
    foreach ($ctx as $key => $val) {
        $message = str_replace(':' . $key, (string)$val, $message);
    }

    $payload = [
        'level' => $level,
        'message' => sprintf('[%s] Simulare Log #%d: ', $app['name'], $index) . $message,
        'context' => $ctx
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: e7c6b541234567890abcdef1234567890',
        'X-APP-KEY: ' . $app['key']
    ]);
    return $ch;
};

// Parametrii pentru fluctuatiile de trafic
// Alternam intre diferite nivele de concurenta si jitter pentru a simula incarcarea reala
$getTrafficParams = function(int $index): array {
    // Calculam faza pe baza indicelui curent (ciclu complet la fiecare 50.000 de cereri)
    $phase = ($index % 50000) / 50000;
    if ($phase < 0.3) {
        // ZONA CALMA: Concurenta mica, jitter mai mare (aplicatii in repaus)
        return [
            'concurrency' => 15,
            'jitter_us' => rand(1500, 3500) // 1.5ms - 3.5ms delay
        ];
    }
    
    if ($phase < 0.7) {
        // ZONA ACTIVA: Concurenta medie, jitter mic (incarcare normala de zi)
        return [
            'concurrency' => 30,
            'jitter_us' => rand(300, 800) // 0.3ms - 0.8ms delay
        ];
    }
    // BURST / SPIKE: Concurenta maxima sigura, jitter minim (ore de varf / flood control test)
    return [
        'concurrency' => 45,
        'jitter_us' => rand(50, 200) // 0.05ms - 0.2ms delay
    ];
};

// Initializam primul set de conexiuni
$initialParams = $getTrafficParams($sent);
$currentConcurrency = $initialParams['concurrency'];

for ($i = 0; $i < $currentConcurrency && $sent < $totalLogs; $i++) {
    $sent++;
    $ch = $createHandle($sent);
    curl_multi_add_handle($mh, $ch);
}

// Executia cozii de tip rolling cu control dinamic al concurentei si pacing
do {
    while (($execrun = curl_multi_exec($mh, $running)) === CURLM_CALL_MULTI_PERFORM);

    if ($execrun !== CURLM_OK) {
        break;
    }

    // Preluam parametrii curenti de trafic
    $traffic = $getTrafficParams($sent);
    $targetConcurrency = $traffic['concurrency'];
    $jitterUs = $traffic['jitter_us'];

    // Cand o cerere s-a finalizat, citim rezultatele
    while ($done = curl_multi_info_read($mh)) {
        $ch = $done['handle'];
        $info = curl_getinfo($ch);

        if ($info['http_code'] === 202) {
            $success++;
        } else {
            $errors++;
            $response = curl_multi_getcontent($ch);
            if ($errors <= 5) {
                echo "\nEroare Ingestie: HTTP {$info['http_code']} - Raspuns: {$response}\n";
            }
        }

        // Eliminam si inchidem handle-ul finalizat
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        // Adaugam o noua cerere daca avem loguri disponibile si suntem sub tinta de concurenta
        if ($sent < $totalLogs && $running < $targetConcurrency) {
            // Aplicam jitter-ul pentru a evita transmiterea absolut simultana
            if ($jitterUs > 0) {
                usleep($jitterUs);
            }

            $sent++;
            $ch = $createHandle($sent);
            curl_multi_add_handle($mh, $ch);
            while (($execrun = curl_multi_exec($mh, $running)) === CURLM_CALL_MULTI_PERFORM);
        }

        // Afisam progresul la fiecare 5.000 de cereri
        $completed = $success + $errors;
        if ($completed % 5000 === 0) {
            $elapsed = microtime(true) - $startTime;
            $rate = $completed / $elapsed;
            if ($rate > $peakRate) {
                $peakRate = $rate;
            }

            $memory = memory_get_usage(true) / 1024 / 1024;

            // Determinam tipul de trafic curent pentru afisare
            $phase = ($completed % 50000) / 50000;
            $trafficType = $phase < 0.3 ? "CALM" : ($phase < 0.7 ? "ACTIV" : "BURST");

            printf("Trimise: %d/%d (%.1f%%) [%s] | Conexiuni: %d | Succes (202): %d | Erori: %d | Viteza: %.1f req/s (Peak: %.1f) | Mem: %.1f MB\n", 
                $completed, $totalLogs, ($completed / $totalLogs) * 100, $trafficType, $running, $success, $errors, $rate, $peakRate, $memory);
        }
    }

    // Daca mai avem loc pana la target-ul de concurenta si mai avem loguri, le adaugam
    if ($sent < $totalLogs && $running < $targetConcurrency) {
        $sent++;
        $ch = $createHandle($sent);
        curl_multi_add_handle($mh, $ch);
        while (($execrun = curl_multi_exec($mh, $running)) === CURLM_CALL_MULTI_PERFORM);
    }

    if ($running) {
        curl_multi_select($mh, 0.02); // Timeout scurt de 20ms pentru a fi reactivi
    }
} while ($running || $sent < $totalLogs);

curl_multi_close($mh);

$totalTime = microtime(true) - $startTime;
echo "\n-----------------------------------------\n";
echo "Simulare Finalizata cu Succes!\n";
echo "Timp Total: " . number_format($totalTime, 2) . " secunde\n";
echo "Rata de Ingestie (202): " . number_format(($success / $totalLogs) * 100, 2) . "%\n";
echo "Total Erori Intampinate: " . $errors . "\n";
echo "Viteza Medie Globala: " . number_format($totalLogs / $totalTime, 2) . " cereri/secunda\n";
echo "Viteza de Varf (Peak): " . number_format($peakRate, 2) . " cereri/secunda\n";
echo "-----------------------------------------\n";
