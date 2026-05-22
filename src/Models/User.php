<?php

declare(strict_types=1);

namespace App\Models;

use RuntimeException;
use InvalidArgumentException;
use PDOException;
use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaCurl;

/**
 * User class
 *
 * Gestioneaza utilizatorii administratori ai sistemului de logare.
 * Ofera functionalitati sigure pentru autentificare si inregistrare.
 * Toate parolele sunt stocate hashuit utilizand exclusiv standardul Argon2id.
 *
 * @category Model
 * @package  App\Models
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class User
{
    /**
     * Constructorul clasei User.
     * Utilizeaza Constructor Property Promotion pentru injectarea dependintei bazei de date.
     * * @param MySQLWrapper $db Wrapper-ul bazei de date injected.
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
     * Gaseste un utilizator in baza de date pe baza numelui de utilizator (username).
     *
     * @param string $username Numele de utilizator cautat.
     * @return array|null Datele utilizatorului sau null daca nu exista.
     * @throws InvalidArgumentException Daca username-ul furnizat este gol.
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
        } catch (PDOException $e) {
            LogViaCurl::send('ERROR', 'Query execution failure event', [
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
     * Inregistreaza un nou utilizator administrator in sistem.
     * Modernizat pentru a utiliza exclusiv hashing PASSWORD_ARGON2ID conform directivelor OWASP.
     *
     * @param string $username Numele de utilizator dorit.
     * @param string $password Parola in text clar (va fi securizata instant).
     * @return int ID-ul utilizatorului nou inregistrat.
     * @throws InvalidArgumentException Daca inputul furnizat este necorespunzator.
     * @throws RuntimeException Daca scrierea in baza de date esueaza sau hash-ul esueaza.
     */
    public function create(string $username, string $password): int
    {
        $trimmedUsername = trim($username);
        if ($trimmedUsername === '' || $password === '') {
            throw new InvalidArgumentException(__('Username and password cannot be empty'));
        }

        // Utilizare PASSWORD_ARGON2ID - Standardul de top in securitatea parolelor moderne (PHP 8+)
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
        } catch (PDOException $e) {
            LogViaCurl::send('ERROR', 'Query execution failure event', [
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