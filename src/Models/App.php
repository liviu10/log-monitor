<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;

/**
 * App Class
 *
 * Manages application entities that can send logs to the system.
 * Provides methods to identify an application via API key, retrieve all applications, 
 * create new applications, and delete existing ones.
 *
 * @category Model
 * @package  App\Models
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class App
{
    /**
     * App class constructor.
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
        // If using Singleton, we can overwrite here or let promotion handle the default instance
        $this->db = MySQLWrapper::getInstance();
    }

    /**
     * Finds an application in the database using the unique API key.
     *
     * @param string $apiKey The API key to search for.
     * @return array|null Application data or null if not found.
     */
    public function findByApiKey(string $apiKey): ?array
    {
        $results = $this->db->read('apps', ['api_key' => $apiKey]);
        return $results ? $results[0] : null;
    }

    /**
     * Retrieves all registered applications.
     *
     * @return array Array containing all applications.
     */
    public function getAll(): array
    {
        return $this->db->read('apps', [], ['*']) ?: [];
    }

    /**
     * Registers a new application in the system.
     *
     * @param string $name   The name of the application.
     * @param string $apiKey The API key generated for the application.
     * @return int|bool The ID of the new record or false on failure.
     */
    public function create(string $name, string $apiKey): int|bool
    {
        return $this->db->create('apps', [
            'name' => $name,
            'api_key' => $apiKey,
        ]);
    }

    /**
     * Updates an application's data in the system.
     *
     * @param int   $id   The ID of the application to update.
     * @param array $data Array of data to update (e.g., ['name' => 'New Name']).
     * @return bool True on success, false otherwise.
     */
    public function update(int $id, array $data): bool
    {
        return $this->db->update('apps', $data, ['id' => $id]) !== false;
    }

    /**
     * Deletes an application from the system based on its ID.
     *
     * @param int $id The ID of the application to delete.
     * @return bool True on success, false otherwise.
     */
    public function delete(int $id): bool
    {
        return (bool)$this->db->delete('apps', ['id' => $id]);
    }
}
