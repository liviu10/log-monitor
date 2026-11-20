<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * AppSetting Model
 *
 * Manages per-application settings (notification channels, levels, recipients).
 */
class AppSetting
{
    /** @var array<int, array{data: array<int, array<string, mixed>>, cached_at: int}> */
    protected static array $settingsCache = [];

    protected MySQLWrapper $db;

    public function __construct(?MySQLWrapper $db = null)
    {
        $this->db = $db ?? MySQLWrapper::getInstance();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsForApp(int $appId): array
    {
        if ($appId <= 0) {
            return [];
        }

        $currentTime = time();
        if (isset(self::$settingsCache[$appId])) {
            $cached = self::$settingsCache[$appId];
            if (($currentTime - $cached['cached_at']) < 10) {
                return $cached['data'];
            }
        }

        try {
            $data = $this->db->read('app_settings', ['app_id' => $appId]);
            self::$settingsCache[$appId] = [
                'data' => $data,
                'cached_at' => $currentTime,
            ];

            return $data;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'SELECT FROM app_settings WHERE app_id = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSetting(int $appId, string $key): ?array
    {
        $trimmedKey = trim($key);
        if ($appId <= 0 || $trimmedKey === '') {
            return null;
        }

        try {
            $results = $this->db->read('app_settings', [
                'app_id' => $appId,
                'key' => $trimmedKey,
            ]);

            $first = $results[0] ?? null;

            return is_array($first) ? $first : null;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'SELECT FROM app_settings WHERE app_id = ? AND key = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return null;
        }
    }

    public function saveSetting(int $appId, string $key, string $value): void
    {
        $trimmedKey = trim($key);
        if ($appId <= 0 || $trimmedKey === '') {
            throw new InvalidArgumentException(__('Invalid application ID or setting key'));
        }

        $existing = $this->getSetting($appId, $trimmedKey);
        $currentTime = date('Y-m-d H:i:s');

        if ($existing !== null) {
            try {
                $this->db->update('app_settings', [
                    'value' => $value,
                    'updated_at' => $currentTime,
                ], [
                    'app_id' => $appId,
                    'key' => $trimmedKey,
                ]);
            } catch (Throwable $e) {
                if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'sql_statement' => 'UPDATE app_settings SET value = ?, updated_at = ? WHERE app_id = ? AND key = ?',
                        'identifier' => 'MySQL_Query_Failure',
                    ]);
                }

                throw new RuntimeException(__('Database error while updating setting'), 0, $e);
            }
        } else {
            try {
                $result = $this->db->create('app_settings', [
                    'app_id' => $appId,
                    'key' => $trimmedKey,
                    'value' => $value,
                    'created_at' => $currentTime,
                ]);
                if ($result === 0 || $result === '') {
                    throw new RuntimeException(__('Failed to create new setting'));
                }
            } catch (Throwable $e) {
                if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'sql_statement' => 'INSERT INTO app_settings (app_id, key, value, created_at)',
                        'identifier' => 'MySQL_Query_Failure',
                    ]);
                }

                throw new RuntimeException(__('Database error while creating setting'), 0, $e);
            }
        }
        unset(self::$settingsCache[$appId]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSetting(int $appId, string $oldKey, array $data): void
    {
        $trimmedOldKey = trim($oldKey);
        if ($appId <= 0 || $trimmedOldKey === '') {
            throw new InvalidArgumentException(__('Invalid master keys for setting update'));
        }

        $rawNewKey = $data['key'] ?? $trimmedOldKey;
        $newKey = trim(is_string($rawNewKey) ? $rawNewKey : '');
        $value = $data['value'] ?? '';

        if ($trimmedOldKey !== $newKey && $this->getSetting($appId, $newKey) !== null) {
            throw new RuntimeException(__('The new setting key already exists'));
        }

        try {
            $this->db->update('app_settings', [
                'key' => $newKey,
                'value' => is_string($value) ? $value : '',
                'updated_at' => date('Y-m-d H:i:s'),
            ], [
                'app_id' => $appId,
                'key' => $trimmedOldKey,
            ]);
            unset(self::$settingsCache[$appId]);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'UPDATE app_settings SET key = ?, value = ?, updated_at = ? WHERE app_id = ? AND key = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during settings update modification'), 0, $e);
        }
    }

    public function deleteSetting(int $appId, string $key): void
    {
        $trimmedKey = trim($key);
        if ($appId <= 0 || $trimmedKey === '') {
            throw new InvalidArgumentException(__('Invalid criteria for setting deletion'));
        }

        try {
            $result = $this->db->delete('app_settings', [
                'app_id' => $appId,
                'key' => $trimmedKey,
            ]);
            if ($result === 0) {
                throw new RuntimeException(__('Setting not found or deletion failed'));
            }
            unset(self::$settingsCache[$appId]);
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'sql_statement' => 'DELETE FROM app_settings WHERE app_id = ? AND key = ?',
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during setting removal'), 0, $e);
        }
    }

    public static function resetCache(): void
    {
        self::$settingsCache = [];
    }
}
