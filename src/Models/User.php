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
 * User class
 *
 * Manages administrative users of the logging system.
 * Offers secure functionalities for authentication and registration.
 * All passwords are saved as hashes using exclusively the Argon2id standard.
 *
 * @category Model
 * @package  App\Models
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class User
{
    /**
     * Constructor for the User class.
     * Uses Constructor Property Promotion for database dependency injection.
     * * @param MySQLWrapper $db Injected database wrapper.
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
     * Finds a user in the database based on the username.
     *
     * @param string $username The username to look for.
     * @return array|null The user's data or null if they do not exist.
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
            return $results ? $results[0] : null;
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'sql_statement' => 'SELECT FROM users WHERE username = ?',
                'sql_parameters' => ['username' => $trimmedUsername],
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            return null;
        }
    }

    /**
     * Registers a new administrator user in the system.
     * Upgraded to exclusively use PASSWORD_ARGON2ID hashing per OWASP guidelines.
     *
     * @param string $username Desired username.
     * @param string $password Clear-text password (will be secured instantly).
     * @return int ID of the newly registered user.
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
        if ($hash === false) {
            throw new RuntimeException(__('Secure password hashing hashing failed internally'));
        }

        $sql = 'INSERT INTO users (username, password_hash)';
        $params = [
            'username' => $trimmedUsername,
            'password_hash' => $hash,
        ];

        try {
            $result = $this->db->create('users', $params);
            if ($result === false) {
                throw new RuntimeException(__('Failed to save administrative user record'));
            }
            return (int)$result;
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
                'identifier' => 'MySQLWrapper_Query_Failure'
            ]);
            throw new RuntimeException(__('Database error during administrative user registration'), 0, $e);
        }
    }
}