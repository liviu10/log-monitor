<?php

declare(strict_types=1);

set_time_limit(0);

require_once dirname(__DIR__, 2).'/config/bootstrap.php';

use App\Controllers\Common\NotificationController;
use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;

/**
 * Recursively sanitizes the context array to mask confidential/sensitive data.
 *
 * @param  array<array-key, mixed>  $data
 * @return array<array-key, mixed>
 */
function sanitizeSensitivePayload(array $data): array
{
    $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'cvv', 'api_key', 'key'];

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = sanitizeSensitivePayload($value);
        } elseif (is_string($value)) {
            $lowerKey = strtolower((string) $key);
            if (in_array($lowerKey, $sensitiveKeys, true)) {
                $data[$key] = '******';
            } elseif (json_validate($value)) {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $data[$key] = json_encode(sanitizeSensitivePayload($decoded), JSON_THROW_ON_ERROR);
                    }
                } catch (Throwable) {
                    // Ignore errors if decoding fails
                }
            }
        }
    }

    return $data;
}

function sanitizeLogMessage(string $message): string
{
    return (string) preg_replace('/(password|pass|pwd|token|api_key)\s*=\s*[^\s&]+/ims', '$1=******', $message);
}

$appsCache = [];
$lastHeartbeat = 0;
$notificationController = new NotificationController;

// @phpstan-ignore while.alwaysTrue
while (true) {
    $currentTime = time();
    if (($currentTime - $lastHeartbeat) >= 10) {
        try {
            $heartbeatFile = dirname(__DIR__, 2).'/storage/worker.heartbeat';
            file_put_contents($heartbeatFile, (string) $currentTime, LOCK_EX);
            $lastHeartbeat = $currentTime;
        } catch (Throwable) {
            // Defensive execution
        }
    }

    try {
        $db = MySQLWrapper::getInstance();
        $pdo = $db->getConnection();

        $pdo->beginTransaction();

        $stmt = $db->query('SELECT id, app_id, payload_raw FROM log_queue ORDER BY id ASC LIMIT 2000 FOR UPDATE SKIP LOCKED');
        $jobs = $stmt->fetchAll();

        if ($jobs !== []) {
            $idsToDelete = [];
            $insertRows = [];
            $insertValues = [];

            foreach ($jobs as $job) {
                if (! is_array($job) || ! isset($job['id'], $job['app_id'], $job['payload_raw'])) {
                    continue;
                }

                $jobIdVal = $job['id'];
                $appIdVal = $job['app_id'];
                $payloadRawVal = $job['payload_raw'];

                if ((! is_int($jobIdVal) && ! is_string($jobIdVal)) ||
                    (! is_int($appIdVal) && ! is_string($appIdVal)) ||
                    ! is_string($payloadRawVal)) {
                    continue;
                }

                $jobId = (int) $jobIdVal;
                $appId = (int) $appIdVal;
                $idsToDelete[] = $jobId;

                if (json_validate($payloadRawVal)) {
                    try {
                        $payload = json_decode($payloadRawVal, true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        $payload = null;
                    }

                    if (is_array($payload)) {
                        $levelVal = $payload['level'] ?? 'INFO';
                        $messageVal = $payload['message'] ?? '';

                        $level = strtoupper(trim(is_string($levelVal) ? $levelVal : 'INFO'));
                        $message = sanitizeLogMessage(trim(is_string($messageVal) ? $messageVal : ''));
                        $context = isset($payload['context']) && is_array($payload['context'])
                            ? sanitizeSensitivePayload($payload['context'])
                            : [];

                        $jsonContext = $context !== [] ? json_encode($context, JSON_THROW_ON_ERROR) : null;

                        $insertRows[] = '(?, ?, ?, ?)';
                        $insertValues[] = $appId;
                        $insertValues[] = $level;
                        $insertValues[] = $message;
                        $insertValues[] = $jsonContext;

                        $now = time();
                        if (! isset($appsCache[$appId]) || ($now - $appsCache[$appId]['cached_at']) > 10) {
                            $stmtApp = $db->query('SELECT * FROM apps WHERE id = ? LIMIT 1', [$appId]);
                            $fetchedApp = $stmtApp->fetch();
                            $appData = is_array($fetchedApp) ? $fetchedApp : null;
                            $settings = [];
                            if (is_array($appData)) {
                                $stmtSettings = $db->query('SELECT `key`, `value` FROM app_settings WHERE app_id = ?', [$appId]);
                                foreach ($stmtSettings->fetchAll() as $row) {
                                    if (is_array($row) && isset($row['key'], $row['value']) && is_string($row['key'])) {
                                        $settings[$row['key']] = $row['value'];
                                    }
                                }
                            }
                            $appsCache[$appId] = [
                                'data' => $appData,
                                'settings' => $settings,
                                'cached_at' => $now,
                            ];
                        }

                        $app = $appsCache[$appId]['data'];
                        if (is_array($app)) {
                            $notificationController->sendAlert($app, [
                                'level' => $level,
                                'message' => $message,
                                'context' => $context,
                            ], $appsCache[$appId]['settings']);
                        }
                    }
                }
            }

            if ($insertRows !== []) {
                $sqlInsert = 'INSERT INTO logs (app_id, level, message, context) VALUES '.implode(', ', $insertRows);
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute($insertValues);
            }

            if ($idsToDelete !== []) {
                $placeholders = implode(', ', array_fill(0, count($idsToDelete), '?'));
                $sqlDelete = "DELETE FROM log_queue WHERE id IN ({$placeholders})";
                $stmtDelete = $pdo->prepare($sqlDelete);
                $stmtDelete->execute($idsToDelete);
            }

            $pdo->commit();
        } else {
            $pdo->commit();
            usleep(500000);
        }
    } catch (Throwable $e) {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            LogViaStream::send(LogLevel::ERROR->value, 'CLI Log Worker execution failure', [
                'location' => __FILE__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'CLI_Log_Worker_Failure',
            ]);
        }

        try {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // Ignore rollback failure
        }

        try {
            $logDir = dirname(__DIR__, 2).'/storage/logs';
            if (! is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }

            $logFile = $logDir.'/worker_emergency.log';
            $errorMessage = sprintf(
                "[%s] Critical error encountered in CLI Log Worker: %s\nTrace:\n%s\n%s\n",
                date('Y-m-d H:i:s'),
                $e->getMessage(),
                $e->getTraceAsString(),
                str_repeat('-', 80)
            );
            @file_put_contents($logFile, $errorMessage, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            error_log('Log Worker emergency logging failure: '.$e->getMessage());
        }

        sleep(5);
    }
}
