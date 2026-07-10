<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;
use InvalidArgumentException;
use RuntimeException;

/**
 * AppSetting Class
 *
 * Manages settings specific to an application in the database.
 * Implements strict validation and defensive Fail Fast architecture.
 *
 * @category Model
 *
 * @version  1.3
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AppSetting
{
    /** @var array<int, array{data: array<int, array<string, mixed>>, cached_at: int}> Static in-memory settings cache, useful for the daemon worker */
    protected static array $settingsCache = [];

    /**
     * Constructor for the AppSetting class.
     * Dependency injection via Constructor Property Promotion.
     *
     * * @param MySQLWrapper $db Database wrapper instance.
     */
    public function __construct(
        protected MySQLWrapper $db = new MySQLWrapper(
            host: 'db',
            db: 'log_monitor',
            user: 'user',
            pass: 'password'
        )
    ) {
        $this->db = MySQLWrapper::getInstance();
    }

    /**
     * Retrieves all settings for an application.
     *
     * @param  int  $appId  Application ID.
     * @return array<int, array<string, mixed>> List of found settings.
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
            $data = $this->db->read('app_settings', ['app_id' => $appId]) ?: [];
            /** @var array<int, array<string, mixed>> $data */
            self::$settingsCache[$appId] = [
                'data' => $data,
                'cached_at' => $currentTime,
            ];

            return $data;
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT FROM app_settings WHERE app_id = ?',
                'sql_parameters' => ['app_id' => $appId],
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return [];
        }
    }

    /**
     * Finds a specific setting based on app_id and key.
     *
     * @param  int  $appId  Application ID.
     * @param  string  $key  Setting key.
     * @return array<string, mixed>|null Setting data or null if it does not exist.
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
            if (is_array($first)) {
                /** @var array<string, mixed> $first */
                return $first;
            }
            return null;
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT FROM app_settings WHERE app_id = ? AND key = ?',
                'sql_parameters' => ['app_id' => $appId, 'key' => $trimmedKey],
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);

            return null;
        }
    }

    /**
     * Saves or updates a setting for an application.
     *
     * @param  int  $appId  Application ID.
     * @param  string  $key  Setting key.
     * @param  string  $value  Setting value.
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If saving fails.
     */
    public function saveSetting(int $appId, string $key, string $value): void
    {
        $trimmedKey = trim($key);
        if ($appId <= 0 || $trimmedKey === '') {
            throw new InvalidArgumentException(__('Invalid application ID or setting key'));
        }

        $existing = $this->getSetting($appId, $trimmedKey);
        $currentTime = date('Y-m-d H:i:s');

        if ($existing) {
            $sql = 'UPDATE app_settings SET value = ?, updated_at = ? WHERE app_id = ? AND key = ?';
            $params = [
                'value' => $value,
                'updated_at' => $currentTime,
                'app_id' => $appId,
                'key' => $trimmedKey,
            ];

            try {
                $this->db->update('app_settings', [
                    'value' => $value,
                    'updated_at' => $currentTime,
                ], [
                    'app_id' => $appId,
                    'key' => $trimmedKey,
                ]);
            } catch (\Throwable $e) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => $sql,
                    'sql_parameters' => $params,
                    'identifier' => 'MySQLWrapper_Query_Failure',
                ]);
                throw new RuntimeException(__('Database error while updating setting'), 0, $e);
            }
        } else {
            $sql = 'INSERT INTO app_settings (app_id, key, value, created_at)';
            $params = [
                'app_id' => $appId,
                'key' => $trimmedKey,
                'value' => $value,
                'created_at' => $currentTime,
            ];

            try {
                $result = $this->db->create('app_settings', $params);
                if ($result === 0 || $result === '') {
                    throw new RuntimeException(__('Failed to create new setting'));
                }
            } catch (\Throwable $e) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => $sql,
                    'sql_parameters' => $params,
                    'identifier' => 'MySQLWrapper_Query_Failure',
                ]);
                throw new RuntimeException(__('Database error while creating setting'), 0, $e);
            }
        }
        unset(self::$settingsCache[$appId]);
    }

    /**
     * Updates the key and/or value of an existing setting.
     *
     * @param  int  $appId  Application ID.
     * @param  string  $oldKey  The old setting key.
     * @param  array<string, mixed>  $data  Data to update ('key' and/or 'value').
     *
     * @throws InvalidArgumentException If the provided keys are invalid.
     * @throws RuntimeException If the new key is a duplicate or the operation fails.
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

        if ($trimmedOldKey !== $newKey) {
            if ($this->getSetting($appId, $newKey) !== null) {
                throw new RuntimeException(__('The new setting key already exists'));
            }
        }

        $sql = 'UPDATE app_settings SET key = ?, value = ?, updated_at = ? WHERE app_id = ? AND key = ?';
        $params = [
            'key' => $newKey,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
            'app_id' => $appId,
            'key_old' => $trimmedOldKey,
        ];

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
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);
            throw new RuntimeException(__('Database error during settings update modification'), 0, $e);
        }
    }

    /**
     * Deletes a setting of an application.
     *
     * @param  int  $appId  Application ID.
     * @param  string  $key  Setting key.
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If deletion fails.
     */
    public function deleteSetting(int $appId, string $key): void
    {
        $trimmedKey = trim($key);
        if ($appId <= 0 || $trimmedKey === '') {
            throw new InvalidArgumentException(__('Invalid criteria for setting deletion'));
        }

        $sql = 'DELETE FROM app_settings WHERE app_id = ? AND key = ?';
        $params = ['app_id' => $appId, 'key' => $trimmedKey];

        try {
            $result = $this->db->delete('app_settings', [
                'app_id' => $appId,
                'key' => $trimmedKey,
            ]);
            if (! $result) {
                throw new RuntimeException(__('Setting not found or deletion failed'));
            }
            unset(self::$settingsCache[$appId]);
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure',
            ]);
            throw new RuntimeException(__('Database error during setting removal'), 0, $e);
        }
    }
}
