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
 * App Class
 *
 * Gestioneaza entitatile de tip aplicatie care pot trimite loguri in sistem.
 * Ofera metode pentru identificarea aplicatiei via API key, recuperare,
 * creare, actualizare si stergere securizata.
 *
 * @category Model
 * @package  App\Models
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class App
{
    /**
     * Constructorul clasei App.
     * Utilizeaza Constructor Property Promotion pentru injectarea dependintei bazei de date.
     * * @param MySQLWrapper $db Instanta wrapper-ului de baza de date.
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
     * Gaseste o aplicatie in baza de date folosind cheia unica API.
     *
     * @param string $apiKey Cheia API pentru cautare.
     * @return array Aplicatia gasita.
     * @throws InvalidArgumentException Daca cheia este goala.
     * @throws RuntimeException Daca aplicatia nu este gasita.
     */
    public function findByApiKey(string $apiKey): array
    {
        $trimmedKey = trim($apiKey);
        if ($trimmedKey === '') {
            throw new InvalidArgumentException(__('API key cannot be empty'));
        }

        try {
            $results = $this->db->read('apps', ['api_key' => $trimmedKey]);
            if (empty($results)) {
                throw new RuntimeException(__('Application not found for the provided API key'));
            }
            return $results[0];
        } catch (PDOException $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT FROM apps WHERE api_key = ?',
                'sql_parameters' => [$trimmedKey],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            throw new RuntimeException(__('Database error during application lookup'), 0, $e);
        }
    }

    /**
     * Recupereaza toate aplicatiile inregistrate.
     *
     * @return array Lista de aplicatii.
     */
    public function getAll(): array
    {
        try {
            return $this->db->read('apps', [], ['*']) ?: [];
        } catch (PDOException $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT ALL FROM apps',
                'sql_parameters' => [],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            return [];
        }
    }

    /**
     * Inregistreaza o aplicatie noua in sistem.
     *
     * @param string $name   Numele aplicatiei.
     * @param string $apiKey Cheia API generata.
     * @return int ID-ul noii inregistrari.
     * @throws InvalidArgumentException Daca datele furnizate sunt invalide.
     * @throws RuntimeException Daca salvarea a esuat.
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
            if ($result === false) {
                throw new RuntimeException(__('Failed to create application record'));
            }
            return (int)$result;
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
            throw new RuntimeException(__('Database error during application creation'), 0, $e);
        }
    }

    /**
     * Actualizeaza datele unei aplicatii.
     *
     * @param int   $id   ID-ul aplicatiei.
     * @param array $data Datele pentru actualizare.
     * @return void
     * @throws InvalidArgumentException Daca datele sunt goale.
     * @throws RuntimeException Daca actualizarea a esuat.
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
            $result = $this->db->update('apps', $data, ['id' => $id]);
            if ($result === false) {
                throw new RuntimeException(__('Failed to update application'));
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
                'sql_parameters' => array_merge($data, ['id' => $id]),
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            throw new RuntimeException(__('Database error during application update'), 0, $e);
        }
    }

    /**
     * Sterge o aplicatie din sistem pe baza ID-ului.
     *
     * @param int $id ID-ul aplicatiei.
     * @return void
     * @throws InvalidArgumentException Daca ID-ul este invalid.
     * @throws RuntimeException Daca stergerea a esuat.
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
            if (!$result) {
                throw new RuntimeException(__('Application deletion failed or record does not exist'));
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
            throw new RuntimeException(__('Database error during application deletion'), 0, $e);
        }
    }
}