<?php

declare(strict_types=1);

namespace App\Models;

use RuntimeException;
use InvalidArgumentException;
use PDOException;
use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * AppSetting Class
 *
 * Gestioneaza setarile specifice unei aplicatii in baza de date.
 * Implementeaza validari stricte si arhitectura defensiva Fail Fast.
 *
 * @category Model
 * @package  App\Models
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class AppSetting
{
    /** @var array Cache static in memorie pentru setari, foarte util pentru daemon worker */
    protected static array $settingsCache = [];
    /**
     * Constructorul clasei AppSetting.
     * Injectare dependinta prin Constructor Property Promotion.
     * * @param MySQLWrapper $db Instanta wrapper-ului bazei de date.
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
     * Recupereaza toate setarile pentru o aplicatie.
     *
     * @param int $appId ID-ul aplicatiei.
     * @return array Lista setarilor gasite.
     */
    public function getSettingsForApp(int $appId): array
    {
        if ($appId <= 0) {
            return [];
        }

        $currentTime = time();
        if (isset(self::$settingsCache[$appId]) && ($currentTime - self::$settingsCache[$appId]['cached_at']) < 10) {
            return self::$settingsCache[$appId]['data'];
        }

        try {
            $data = $this->db->read('app_settings', ['app_id' => $appId]) ?: [];
            self::$settingsCache[$appId] = [
                'data' => $data,
                'cached_at' => $currentTime
            ];
            return $data;
        } catch (PDOException $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT FROM app_settings WHERE app_id = ?',
                'sql_parameters' => ['app_id' => $appId],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            return [];
        }
    }

    /**
     * Gaseste o setare specifica pe baza app_id si key.
     *
     * @param int    $appId ID-ul aplicatiei.
     * @param string $key   Cheia setarii.
     * @return array|null Datele setarii sau null daca nu exista.
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
            return $results ? $results[0] : null;
        } catch (PDOException $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT FROM app_settings WHERE app_id = ? AND key = ?',
                'sql_parameters' => ['app_id' => $appId, 'key' => $trimmedKey],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            return null;
        }
    }

    /**
     * Salveaza sau actualizeaza o setare pentru o aplicatie.
     *
     * @param int    $appId ID-ul aplicatiei.
     * @param string $key   Cheia setarii.
     * @param string $value Valoarea setarii.
     * @return void
     * @throws InvalidArgumentException Daca parametrii sunt invalizi.
     * @throws RuntimeException Daca salvarea esueaza.
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
                $result = $this->db->update('app_settings', [
                    'value' => $value,
                    'updated_at' => $currentTime,
                ], [
                    'app_id' => $appId,
                    'key' => $trimmedKey,
                ]);

                if ($result === false) {
                    throw new RuntimeException(__('Failed to update existing setting'));
                }
            } catch (PDOException $e) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => $sql,
                    'sql_parameters' => $params,
                    'identifier' => 'MySQLWrapper_Query_Failure'
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
                if ($result === false) {
                    throw new RuntimeException(__('Failed to create new setting'));
                }
            } catch (PDOException $e) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => $sql,
                    'sql_parameters' => $params,
                    'identifier' => 'MySQLWrapper_Query_Failure'
                ]);
                throw new RuntimeException(__('Database error while creating setting'), 0, $e);
            }
        }
        unset(self::$settingsCache[$appId]);
    }

    /**
     * Actualizeaza cheia si/sau valoarea unei setari existente.
     *
     * @param int    $appId  ID-ul aplicatiei.
     * @param string $oldKey Vechea cheie a setarii.
     * @param array  $data   Datele de actualizat ('key' si/sau 'value').
     * @return void
     * @throws InvalidArgumentException Daca cheile introduse sunt invalide.
     * @throws RuntimeException Daca cheia noua este duplicata sau operatia esueaza.
     */
    public function updateSetting(int $appId, string $oldKey, array $data): void
    {
        $trimmedOldKey = trim($oldKey);
        if ($appId <= 0 || $trimmedOldKey === '') {
            throw new InvalidArgumentException(__('Invalid master keys for setting update'));
        }

        $newKey = trim($data['key'] ?? $trimmedOldKey);
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
            $result = $this->db->update('app_settings', [
                'key' => $newKey,
                'value' => $value,
                'updated_at' => date('Y-m-d H:i:s'),
            ], [
                'app_id' => $appId,
                'key' => $trimmedOldKey,
            ]);

            if ($result === false) {
                throw new RuntimeException(__('Setting update operation failed'));
            }
            unset(self::$settingsCache[$appId]);
        } catch (PDOException $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            throw new RuntimeException(__('Database error during settings update modification'), 0, $e);
        }
    }

    /**
     * Sterge o setare a unei aplicatii.
     *
     * @param int    $appId ID-ul aplicatiei.
     * @param string $key   Cheia setarii.
     * @return void
     * @throws InvalidArgumentException Daca parametrii sunt invalizi.
     * @throws RuntimeException Daca stergerea esueaza.
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
            if (!$result) {
                throw new RuntimeException(__('Setting not found or deletion failed'));
            }
            unset(self::$settingsCache[$appId]);
        } catch (PDOException $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => $sql,
                'sql_parameters' => $params,
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            throw new RuntimeException(__('Database error during setting removal'), 0, $e);
        }
    }
}