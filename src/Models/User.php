<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;

/**
 * User class
 *
 * Manages the administrative users of the log system.
 * Provides functionalities for authentication (finding by username) and creating new administrative accounts.
 * All passwords are stored securely using the BCRYPT algorithm.
 *
 * @category Model
 * @package  App\Models
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class User
{
    /**
     * User class constructor.
     * Using Constructor Property Promotion for database injection.
     * 
     * @param MySQLWrapper $db Database wrapper instance.
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
     * @param string $username The username to search for.
     * @return array|null User data or null if not found.
     */
    public function findByUsername(string $username): ?array
    {
        $results = $this->db->read('users', ['username' => $username]);
        return $results ? $results[0] : null;
    }

    /**
     * Creates a new administrative user in the system.
     *
     * @param string $username The desired username.
     * @param string $password Password in plain text (will be hashed).
     * @return int|bool The ID of the new record or false on error.
     */
    public function create(string $username, string $password): int|bool
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        return $this->db->create('users', [
            'username' => $username,
            'password_hash' => $hash,
        ]);
    }
}
