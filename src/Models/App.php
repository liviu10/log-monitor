<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQLWrapper;
use InvalidArgumentException;
use RuntimeException;

/**
 * App Class
 *
 * Manages application entities that can send logs to the system.
 * Offers methods for application identification via API key, retrieval,
 * creation, updates, and secure deletion.
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
class App
{
    /**
     * Constructor for the App class.
     * Uses Constructor Property Promotion for database dependency injection.
     *
     * * @param MySQLWrapper $db Database wrapper instance.
     */
    public function __construct(
        protected ?MySQLWrapper $db = null
    ) {
        $this->db = $db ?? MySQLWrapper::getInstance();
    }

    /**
     * Finds an application in the database using the unique API key.
     *
     * @param  string  $apiKey  API key for lookup.
     * @return array<string, mixed> The found application.
     *
     * @throws InvalidArgumentException If the key is empty.
     * @throws RuntimeException If the application is not found.
     */
    public function findByApiKey(string $apiKey): array
    {
        $trimmedKey = trim($apiKey);
        if ($trimmedKey === '') {
            throw new InvalidArgumentException(__('API key cannot be empty'));
        }

        try {
            $results = $this->db->read('apps', ['api_key' => $trimmedKey]);
            $first = $results[0] ?? null;
            if (! is_array($first)) {
                throw new RuntimeException(__('Application not found for the provided API key'));
            }

            /** @var array<string, mixed> $first */
            return $first;
        } catch (\Throwable $e) {
            if (class_exists('App\\Utilities\\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'SELECT FROM apps WHERE api_key = ?',
                    'sql_parameters' => [$trimmedKey],
                    'identifier' => 'MySQLWrapper_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during application lookup'), 0, $e);
        }
    }

    /**
     * Retrieves all registered applications.
     *
     * @return array<int, array<string, mixed>> List of applications.
     */
    public function getAll(): array
    {
        try {
            $results = $this->db->read('apps', [], ['*']) ?: [];

            /** @var array<int, array<string, mixed>> $results */
            return $results;
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'SELECT ALL FROM apps',
                    'sql_parameters' => [],
                    'identifier' => 'MySQLWrapper_Query_Failure',
                ]);
            }

            return [];
        }
    }

    /**
     * Registers a new application in the system.
     *
     * @param  string  $name  Application name.
     * @param  string  $apiKey  Generated API key.
     * @return int ID of the new record.
     *
     * @throws InvalidArgumentException If the provided data is invalid.
     * @throws RuntimeException If saving fails.
     */
    public function create(string $name, string $apiKey): int
    {
        $trimmedName = trim($name);
        $trimmedKey = trim($apiKey);

        if ($trimmedName === '' || $trimmedKey === '') {
            throw new InvalidArgumentException(__('Application name and API key cannot be empty'));
        }

        $sql = 'INSERT INTO apps (name, api_key)';
        $params = ['name' => $trimmedName, 'api_key' => $trimmedKey];

        try {
            $result = $this->db->create('apps', $params);
            if ($result === 0 || $result === '') {
                throw new RuntimeException(__('Failed to create application record'));
            }

            return (int) $result;
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
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
            }

            throw new RuntimeException(__('Database error during application creation'), 0, $e);
        }
    }

    /**
     * Updates an application's data.
     *
     * @param  int  $id  Application ID.
     * @param  array<string, mixed>  $data  Data for update.
     *
     * @throws InvalidArgumentException If the data is empty.
     * @throws RuntimeException If update fails.
     */
    public function update(int $id, array $data): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(__('Invalid application ID'));
        }
        if (empty($data)) {
            throw new InvalidArgumentException(__('Update data cannot be empty'));
        }

        $sql = 'UPDATE apps SET ... WHERE id = ?';
        try {
            $this->db->update('apps', $data, ['id' => $id]);
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => $sql,
                    'sql_parameters' => array_merge($data, ['id' => $id]),
                    'identifier' => 'MySQLWrapper_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during application update'), 0, $e);
        }
    }

    /**
     * Deletes an application from the system based on its ID.
     *
     * @param  int  $id  Application ID.
     *
     * @throws InvalidArgumentException If the ID is invalid.
     * @throws RuntimeException If deletion fails.
     */
    public function delete(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(__('Invalid application ID for deletion'));
        }

        $sql = 'DELETE FROM apps WHERE id = ?';
        $params = ['id' => $id];

        try {
            $result = $this->db->delete('apps', $params);
            if (! $result) {
                throw new RuntimeException(__('Application deletion failed or record does not exist'));
            }
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
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
            }

            throw new RuntimeException(__('Database error during application deletion'), 0, $e);
        }
    }
}
