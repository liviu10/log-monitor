<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\Common\NotificationController;
use Throwable;

/**
 * Processes buffered payloads from the `log_queue` table into `logs` and dispatches alert rules.
 */
final class LogQueueProcessor
{
    /**
     * Recursively sanitizes the context array to mask confidential/sensitive data.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function sanitizeSensitivePayload(array $data): array
    {
        $sensitiveKeys = ['password', 'pass', 'pwd', 'token', 'secret', 'auth', 'card', 'ccv', 'cvv', 'api_key', 'key'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sanitizeSensitivePayload($value);
            } elseif (is_string($value)) {
                $lowerKey = strtolower((string) $key);
                if (in_array($lowerKey, $sensitiveKeys, true)) {
                    $data[$key] = '******';
                } elseif (json_validate($value)) {
                    try {
                        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decoded)) {
                            $data[$key] = json_encode(self::sanitizeSensitivePayload($decoded), JSON_THROW_ON_ERROR);
                        }
                    } catch (Throwable) {
                        // Ignore errors if decoding fails
                    }
                }
            }
        }

        return $data;
    }

    public static function sanitizeLogMessage(string $message): string
    {
        return (string) preg_replace('/(password|pass|pwd|token|api_key)\s*=\s*[^\s&]+/ims', '$1=******', $message);
    }

    /**
     * Processes one batch (up to 2000 rows) of queued logs inside a database transaction.
     *
     * @param  array<int, array{data: array<string, mixed>|null, settings: array<string, mixed>, cached_at: int}>  $appsCache
     */
    public static function processBatch(array &$appsCache = [], ?NotificationController $notificationController = null): int
    {
        $db = MySQLWrapper::getInstance();
        $pdo = $db->getConnection();
        $notifier = $notificationController ?? new NotificationController;

        try {
            $pdo->beginTransaction();

            $stmt = $db->query('SELECT id, app_id, payload_raw FROM log_queue ORDER BY id ASC LIMIT 2000 FOR UPDATE SKIP LOCKED');
            $jobs = $stmt->fetchAll();

            if ($jobs === []) {
                $pdo->commit();

                return 0;
            }

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

                if (! json_validate($payloadRawVal)) {
                    continue;
                }

                try {
                    $payload = json_decode($payloadRawVal, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    $payload = null;
                }

                if (! is_array($payload)) {
                    continue;
                }

                $levelVal = $payload['level'] ?? 'INFO';
                $messageVal = $payload['message'] ?? '';

                $level = strtoupper(trim(is_string($levelVal) ? $levelVal : 'INFO'));
                $message = self::sanitizeLogMessage(trim(is_string($messageVal) ? $messageVal : ''));
                $context = isset($payload['context']) && is_array($payload['context'])
                    ? self::sanitizeSensitivePayload($payload['context'])
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
                    /** @var array<string, mixed>|null $appData */
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
                    $notifier->sendAlert($app, [
                        'level' => $level,
                        'message' => $message,
                        'context' => $context,
                    ], $appsCache[$appId]['settings']);
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

            return count($idsToDelete);
        } catch (Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Ignore rollback failure
            }

            throw $e;
        }
    }
}

