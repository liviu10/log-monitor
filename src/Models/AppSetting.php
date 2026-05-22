<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;

/**
 * Clasa AppSetting
 *
 * Gestioneaza setarile specifice unei aplicatii din baza de date.
 * Ofera metode pentru citirea, crearea/actualizarea si stergerea setarilor.
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
     * Constructorul clasei AppSetting.
     * Utilizăm Constructor Property Promotion pentru a injecta dependența bazei de date.
     * 
     * @param MySQLWrapper $db Instanta wrapper-ului de baza de date.
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
     * @return array Tablou cu toate setarile aplicatiei.
     */
    public function getSettingsForApp(int $appId): array
    {
        return $this->db->read('app_settings', ['app_id' => $appId]) ?: [];
    }

    /**
     * Gaseste o setare specifica pe baza app_id si cheie.
     *
     * @param int    $appId ID-ul aplicatiei.
     * @param string $key   Cheia setarii.
     * @return array|null Datele setarii sau null daca nu este gasita.
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
     * Salveaza sau actualizeaza o setare pentru o aplicatie.
     *
     * @param int    $appId ID-ul aplicatiei.
     * @param string $key   Cheia setarii.
     * @param string $value Valoarea setarii.
     * @return bool True in caz de succes, false altfel.
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
     * Actualizeaza cheia si/sau valoarea unei setari.
     *
     * @param int    $appId  ID-ul aplicatiei.
     * @param string $oldKey Cheia veche a setarii.
     * @param array  $data   Tablou cu datele de actualizat ('key' si/sau 'value').
     * @return bool True in caz de succes, false altfel.
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
     * Sterge o setare a unei aplicatii.
     *
     * @param int    $appId ID-ul aplicatiei.
     * @param string $key   Cheia setarii.
     * @return bool True in caz de succes, false altfel.
     */
    public function deleteSetting(int $appId, string $key): bool
    {
        return (bool)$this->db->delete('app_settings', [
            'app_id' => $appId,
            'key' => $key,
        ]);
    }
}
