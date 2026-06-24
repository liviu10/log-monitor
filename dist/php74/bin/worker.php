<?php

declare(strict_types=1);

// Setam limita timpului de executie la zero pentru a rula la nesfarsit ca daemon CLI
set_time_limit(0);

// Incarcam setarile de baza ale aplicatiei
require_once dirname(__DIR__) . '/bootstrap.php';

use App\Utilities\MySQLWrapper;
use App\Models\Log;
use App\Controllers\NotificationController;

/**
 * Curata recursiv vectorul de context pentru a masca datele confidentiale/sensibile.
 *
 * @param array $data Vectorul de date din context.
 * @return array Vectorul curatat de date sensibile.
 */
function sanitizeSensitivePayload(array $data): array
{
    $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'cvv', 'api_key', 'key'];

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = sanitizeSensitivePayload($value);
        } elseif (is_string($value)) {
            $lowerKey = strtolower((string)$key);
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
            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $data[$key] = '******';
            } elseif ($jsonValidate($value)) {
                // Daca valoarea este un string JSON valid, o decodam si o curatam recursiv
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $data[$key] = json_encode(sanitizeSensitivePayload($decoded), JSON_UNESCAPED_SLASHES);
                    }
                } catch (\Throwable $exception) {
                    // Ignoram erorile daca decodarea esueaza dintr-un motiv oarecare
                }
            }
        }
    }

    return $data;
}

/**
 * Mascheaza datele sensibile din mesajul de log.
 *
 * @param string $message Mesajul original.
 * @return string Mesajul curatat.
 */
function sanitizeLogMessage(string $message): string
{
    return (string)preg_replace('/(password|pass|pwd|token|api_key)\s*=\s*[^\s&]+/ims', '$1=******', $message);
}

// Bucla principala infinita a worker-ului CLI
$appsCache = [];

while (true) {
    try {
        $db = MySQLWrapper::getInstance();
        $pdo = $db->getConnection();

        // Initiem o tranzactie locala pentru a garanta stergerea atomica si blocarea randului selectat
        $pdo->beginTransaction();

        // Selectam primul job disponibil folosind FOR UPDATE SKIP LOCKED pentru a evita race conditions in mod concurent
        $stmt = $db->query('SELECT id, app_id, payload_raw FROM log_queue ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED');
        $job = $stmt->fetch();

        if ($job) {
            $jobId = (int)$job['id'];
            $appId = (int)$job['app_id'];
            $payloadRaw = (string)$job['payload_raw'];
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

            // Validam payload-ul utilizand noua functionalitate json_validate din PHP 8.4
            if ($jsonValidate($payloadRaw)) {
                $payload = json_decode($payloadRaw, true);

                if (is_array($payload)) {
                    $level = strtoupper(trim((string)($payload['level'] ?? 'INFO')));
                    $message = trim((string)($payload['message'] ?? ''));

                    // Sanitizam datele sensibile din mesaj si context
                    $message = sanitizeLogMessage($message);
                    $context = isset($payload['context']) && is_array($payload['context'])
                        ? sanitizeSensitivePayload($payload['context'])
                        : [];

                    // Inseram logul in tabela principala logs
                    $logModel = new Log();
                    $logModel->create($appId, $level, $message, $context);

                    // Trimitere de alerte automate daca sunt configurate si nivelul de severitate corespunde (folosim cache-ul cu TTL 10s)
                    $currentTime = time();
                    if (!isset($appsCache[$appId]) || ($currentTime - $appsCache[$appId]['cached_at']) > 10) {
                        $stmtApp = $db->query('SELECT * FROM apps WHERE id = ? LIMIT 1', [$appId]);
                        $appsCache[$appId] = [
                            'data' => $stmtApp->fetch() ?: null,
                            'cached_at' => $currentTime
                        ];
                    }

                    $app = $appsCache[$appId]['data'];
                    if ($app) {
                        $notificationController = new NotificationController();
                        $notificationController->sendAlert($app, [
                            'level' => $level,
                            'message' => $message,
                            'context' => $context
                        ]);
                    }
                }
            }

            // Stergem jobul din coada dupa procesarea completa cu succes
            $db->delete('log_queue', ['id' => $jobId]);
            $pdo->commit();

        } else {
            // Daca nu exista mesaje de procesat, eliberam tranzactia si punem procesul in asteptare
            $pdo->commit();
            usleep(500000); // 0.5 secunde pentru a evita utilizarea excesiva a procesorului (idle)
        }

    } catch (\Throwable $e) {
        // Asiguram rollback defensiv in caz de esec al tranzactiei active
        try {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $exception) {
            // Ignoram erorile la nivel de rollback
        }

        // Scriem eroarea critica intr-un fisier local de urgenta localizat in /storage/logs/
        try {
            $logDir = dirname(__DIR__) . '/storage/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }

            $logFile = $logDir . '/worker_emergency.log';
            $timestamp = date('Y-m-d H:i:s');
            $errorMessage = sprintf(
                "[%s] Eroare critica intampinata in CLI Worker: %s\nTrace:\n%s\n%s\n",
                $timestamp,
                $e->getMessage(),
                $e->getTraceAsString(),
                str_repeat('-', 80)
            );

            @file_put_contents($logFile, $errorMessage, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $exception) {
            // Fallback final catre error_log din PHP daca sistemul de fisiere este blocat
            error_log("Worker emergency logging failure: " . $e->getMessage());
        }

        // Introducem o intarziere defensiva de 5 secunde inainte de reincercare pentru a preveni buclele rapide
        sleep(5);
    }
}
