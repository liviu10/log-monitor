<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LogLevel;
use App\Utilities\LogViaStream;
use App\Utilities\MySQL;
use InvalidArgumentException;
use RuntimeException;

/**
 * User class
 *
 * Manages administrative users of the logging system.
 * Offers secure functionalities for authentication and registration.
 * All passwords are saved as hashes using exclusively the Argon2id standard.
 *
 * @category Model
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class User
{
    protected MySQL $db;

    /**
     * Constructor for the User class.
     *
     * @param  MySQL|null  $db  Injected database wrapper.
     */
    public function __construct(
        ?MySQL $db = null
    ) {
        $this->db = $db ?? MySQL::getInstance();
    }

    /**
     * Finds a user in the database based on the username.
     *
     * @param  string  $username  The username to look for.
     * @return array<string, mixed>|null The user's data or null if they do not exist.
     *
     * @throws InvalidArgumentException If the provided username is empty.
     */
    public function findByUsername(string $username): ?array
    {
        $trimmedUsername = trim($username);
        if ($trimmedUsername === '') {
            throw new InvalidArgumentException(__('Username cannot be empty'));
        }

        try {
            $results = $this->db->read('users', ['username' => $trimmedUsername]);
            $first = $results[0] ?? null;
            if (is_array($first)) {
                /** @var array<string, mixed> $first */
                return $first;
            }

            return null;
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'SELECT FROM users WHERE username = ?',
                    'sql_parameters' => ['username' => $trimmedUsername],
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            return null;
        }
    }

    /**
     * Registers a new administrator user in the system.
     * Upgraded to exclusively use PASSWORD_ARGON2ID hashing per OWASP guidelines.
     *
     * @param  string  $username  Desired username.
     * @param  string  $password  Clear-text password (will be secured instantly).
     * @return int ID of the newly registered user.
     *
     * @throws InvalidArgumentException If the provided input is invalid.
     * @throws RuntimeException If database write or hashing fails.
     */
    public function create(string $username, string $password): int
    {
        $trimmedUsername = trim($username);
        if ($trimmedUsername === '' || $password === '') {
            throw new InvalidArgumentException(__('Username and password cannot be empty'));
        }

        // Use PASSWORD_ARGON2ID - The top standard in modern password security (PHP 8+)
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $sql = 'INSERT INTO users (username, password_hash)';
        $params = [
            'username' => $trimmedUsername,
            'password_hash' => $hash,
        ];

        try {
            $result = $this->db->create('users', $params);
            if ($result === 0 || $result === '') {
                throw new RuntimeException(__('Failed to save administrative user record'));
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
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error during administrative user registration'), 0, $e);
        }
    }

    /**
     * Retrieves all administrator users.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        try {
            return $this->db->read('users') ?: [];
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'SELECT ALL FROM users',
                    'sql_parameters' => [],
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }
            throw new RuntimeException(__('Database error retrieving users list'), 0, $e);
        }
    }

    /**
     * Deletes a user by ID.
     *
     * @param  int  $id  User ID to delete.
     * @return int Number of affected rows.
     */
    public function delete(int $id): int
    {
        $params = ['id' => $id];
        try {
            return (int) $this->db->delete('users', $params);
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'DELETE FROM users WHERE id = ?',
                    'sql_parameters' => $params,
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }
            throw new RuntimeException(__('Database error deleting user'), 0, $e);
        }
    }

    /**
     * Finds a user by primary key ID.
     *
     * @param  int  $id  User ID.
     * @return array<string, mixed>|null User data or null.
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $results = $this->db->read('users', ['id' => $id]);
            $first = $results[0] ?? null;

            return is_array($first) ? $first : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Updates an administrator's password.
     * Hashes the new password with PASSWORD_ARGON2ID.
     *
     * @param  int  $id  User ID.
     * @param  string  $newPassword  Plaintext password.
     * @return bool True on success.
     *
     * @throws InvalidArgumentException If inputs are invalid.
     * @throws RuntimeException If update fails.
     */
    public function updatePassword(int $id, string $newPassword): bool
    {
        if ($id <= 0 || trim($newPassword) === '') {
            throw new InvalidArgumentException(__('User ID and new password cannot be empty.'));
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);

        try {
            $affected = $this->db->update('users', ['password_hash' => $hash], ['id' => $id]);

            return $affected > 0;
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'UPDATE users SET password_hash = ? WHERE id = ?',
                    'sql_parameters' => ['id' => $id],
                    'identifier' => 'MySQL_Query_Failure',
                ]);
            }

            throw new RuntimeException(__('Database error updating user password'), 0, $e);
        }
    }
}
