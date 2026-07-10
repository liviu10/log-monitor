<?php

declare(strict_types=1);

// Set the execution time limit to zero to run indefinitely as a CLI daemon
set_time_limit(0);

// Load base application configuration
require_once dirname(__DIR__).'/bootstrap.php';

use App\Controllers\NotificationController;
use App\Enums\LogLevel;
use App\Models\Log;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;

/**
 * Recursively sanitizes the context array to mask confidential/sensitive data.
 *
 * @param  array  $data  The context data array.
 * @return array The sanitized array.
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
            } else {
                // If the value is a valid JSON string, decode and sanitize it recursively
                if (json_validate($value)) {
                    try {
                        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $data[$key] = json_encode(sanitizeSensitivePayload($decoded), JSON_UNESCAPED_SLASHES);
                        }
                    } catch (Throwable) {
                        // Ignore errors if decoding fails for any reason
                    }
                }
            }
        }
    }

    return $data;
}

/**
 * Masks sensitive data in the log message.
 *
 * @param  string  $message  The original message.
 * @return string The sanitized message.
 */
function sanitizeLogMessage(string $message): string
{
    return (string) preg_replace('/(password|pass|pwd|token|api_key)\s*=\s*[^\s&]+/ims', '$1=******', $message);
}

// Main infinite loop of the CLI worker
$appsCache = [];
$lastHeartbeat = 0;
$notificationController = new NotificationController;

while (true) {
    $currentTime = time();
    if (($currentTime - $lastHeartbeat) >= 10) {
        try {
            $heartbeatFile = dirname(__DIR__).'/storage/worker.heartbeat';
            file_put_contents($heartbeatFile, (string) $currentTime, LOCK_EX);
            $lastHeartbeat = $currentTime;
        } catch (Throwable) {
            // Defensive execution: prevent the worker from blocking if temporary I/O issues occur
        }
    }

    try {
        $db = MySQLWrapper::getInstance();
        $pdo = $db->getConnection();

        // Initiate a local transaction to guarantee atomic deletion and row locking
        $pdo->beginTransaction();

        // Select up to 2000 jobs using FOR UPDATE SKIP LOCKED
        $stmt = $db->query('SELECT id, app_id, payload_raw FROM log_queue ORDER BY id ASC LIMIT 2000 FOR UPDATE SKIP LOCKED');
        $jobs = $stmt->fetchAll();

        if ($jobs) {
            $idsToDelete = [];
            $insertRows = [];
            $insertValues = [];

            foreach ($jobs as $job) {
                $jobId = (int) $job['id'];
                $appId = (int) $job['app_id'];
                $payloadRaw = (string) $job['payload_raw'];

                $idsToDelete[] = $jobId;

                // Validate payload using the json_validate function from PHP 8.4
                if (json_validate($payloadRaw)) {
                    $payload = json_decode($payloadRaw, true);

                    if (is_array($payload)) {
                        $level = strtoupper(trim((string) ($payload['level'] ?? 'INFO')));
                        $message = trim((string) ($payload['message'] ?? ''));

                        // Sanitize sensitive data from the message and context
                        $message = sanitizeLogMessage($message);
                        $context = isset($payload['context']) && is_array($payload['context'])
                            ? sanitizeSensitivePayload($payload['context'])
                            : [];

                        $jsonContext = null;
                        if (! empty($context)) {
                            $jsonContext = json_encode($context, JSON_THROW_ON_ERROR);
                        }

                        // Prepare parameters for bulk insert
                        $insertRows[] = '(?, ?, ?, ?)';
                        $insertValues[] = $appId;
                        $insertValues[] = $level;
                        $insertValues[] = $message;
                        $insertValues[] = $jsonContext;

                        // Send automatic alerts if configured
                        $currentTime = time();
                        if (! isset($appsCache[$appId]) || ($currentTime - $appsCache[$appId]['cached_at']) > 10) {
                            $stmtApp = $db->query('SELECT * FROM apps WHERE id = ? LIMIT 1', [$appId]);
                            $appData = $stmtApp->fetch() ?: null;
                            $settings = [];
                            if ($appData) {
                                $stmtSettings = $db->query('SELECT `key`, `value` FROM app_settings WHERE app_id = ?', [$appId]);
                                foreach ($stmtSettings->fetchAll() as $row) {
                                    $settings[$row['key']] = $row['value'];
                                }
                            }
                            $appsCache[$appId] = [
                                'data' => $appData,
                                'settings' => $settings,
                                'cached_at' => $currentTime,
                            ];
                        }

                        $app = $appsCache[$appId]['data'];
                        if ($app) {
                            $notificationController->sendAlert($app, [
                                'level' => $level,
                                'message' => $message,
                                'context' => $context,
                            ], $appsCache[$appId]['settings']);
                        }
                    }
                }
            }

            // Inserare bulk in logs
            if (! empty($insertRows)) {
                $sqlInsert = 'INSERT INTO logs (app_id, level, message, context) VALUES '.implode(', ', $insertRows);
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute($insertValues);
            }

            // Batch delete from log_queue
            if (! empty($idsToDelete)) {
                $placeholders = implode(', ', array_fill(0, count($idsToDelete), '?'));
                $sqlDelete = "DELETE FROM log_queue WHERE id IN ({$placeholders})";
                $stmtDelete = $pdo->prepare($sqlDelete);
                $stmtDelete->execute($idsToDelete);
            }

            $pdo->commit();

        } else {
            // If there are no messages to process, commit the transaction and put the process to sleep
            $pdo->commit();
            usleep(500000); // 0.5 seconds to avoid excessive CPU usage (idle)
        }

    } catch (Throwable $e) {
        if (class_exists('App\\Utilities\\LogViaStream')) {
            LogViaStream::send(LogLevel::ERROR->value, 'CLI Worker execution failure', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'CLI_Worker_Failure',
            ]);
        }

        // Ensure defensive rollback in case of active transaction failure
        try {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // Ignore errors at the rollback level
        }

        // Write critical error to a local emergency file in /storage/logs/
        try {
            $logDir = dirname(__DIR__).'/storage/logs';
            if (! is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }

            $logFile = $logDir.'/worker_emergency.log';
            $timestamp = date('Y-m-d H:i:s');
            $errorMessage = sprintf(
                "[%s] Critical error encountered in CLI Worker: %s\nTrace:\n%s\n%s\n",
                $timestamp,
                $e->getMessage(),
                $e->getTraceAsString(),
                str_repeat('-', 80)
            );

            @file_put_contents($logFile, $errorMessage, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Final fallback to PHP error_log if the filesystem is blocked
            error_log('Worker emergency logging failure: '.$e->getMessage());
        }

        // Introduce a defensive delay of 5 seconds before retrying to prevent rapid loops
        sleep(5);
    }
}
