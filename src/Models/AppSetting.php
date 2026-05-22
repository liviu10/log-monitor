<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;

/**
 * AppSetting Class
 *
 * Manages settings specific to an application in the database.
 * Provides methods for reading, creating/updating, and deleting settings.
 *
 * @category Model
 * @package  App\Models
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class AppSetting
{
    /**
     * AppSetting class constructor.
     * Using Constructor Property Promotion to inject the database dependency.
     * 
     * @param MySQLWrapper $db The database wrapper instance.
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
     * @param int $appId The application ID.
     * @return array Array with all application settings.
     */
    public function getSettingsForApp(int $appId): array
    {
        return $this->db->read('app_settings', ['app_id' => $appId]) ?: [];
    }

    /**
     * Finds a specific setting based on app_id and key.
     *
     * @param int    $appId The application ID.
     * @param string $key   The setting key.
     * @return array|null Setting data or null if not found.
     */
    public function getSetting(int $appId, string $key): ?array
    {
        $results = $this->db->read('app_settings', [
            'app_id' => $appId,
            'key' => $key,
        ]);
        return $results ? $results[0] : null;
    }

    /**
     * Saves or updates a setting for an application.
     *
     * @param int    $appId The application ID.
     * @param string $key   The setting key.
     * @param string $value The setting value.
     * @return bool True on success, false otherwise.
     */
    public function saveSetting(int $appId, string $key, string $value): bool
    {
        $existing = $this->getSetting($appId, $key);
        if ($existing) {
            return $this->db->update('app_settings', [
                'value' => $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], [
                'app_id' => $appId,
                'key' => $key,
            ]) !== false;
        } else {
            return $this->db->create('app_settings', [
                'app_id' => $appId,
                'key' => $key,
                'value' => $value,
                'created_at' => date('Y-m-d H:i:s'),
            ]) !== false;
        }
    }

    /**
     * Updates the key and/or value of a setting.
     *
     * @param int    $appId  The application ID.
     * @param string $oldKey The old setting key.
     * @param array  $data   Array with data to update ('key' and/or 'value').
     * @return bool True on success, false otherwise.
     */
    public function updateSetting(int $appId, string $oldKey, array $data): bool
    {
        $newKey = $data['key'] ?? $oldKey;
        $value = $data['value'] ?? '';

        if ($oldKey !== $newKey) {
            $existing = $this->getSetting($appId, $newKey);
            if ($existing) {
                return false;
            }
        }

        return $this->db->update('app_settings', [
            'key' => $newKey,
            'value' => $value,
            'updated_at' => date('Y-m-d H:i:s'),
        ], [
            'app_id' => $appId,
            'key' => $oldKey,
        ]) !== false;
    }

    /**
     * Deletes a setting of an application.
     *
     * @param int    $appId The application ID.
     * @param string $key   The setting key.
     * @return bool True on success, false otherwise.
     */
    public function deleteSetting(int $appId, string $key): bool
    {
        return (bool)$this->db->delete('app_settings', [
            'app_id' => $appId,
            'key' => $key,
        ]);
    }
}
