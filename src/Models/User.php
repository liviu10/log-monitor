<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;

/**
 * Clasa User
 *
 * Gestioneaza utilizatorii administratori ai sistemului de loguri.
 * Ofera functionalitati pentru autentificare (gasire dupa username) si crearea de noi conturi administrative.
 * Toate parolele sunt stocate securizat folosind algoritmul BCRYPT.
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
     * Constructorul clasei User.
     * Utilizăm Constructor Property Promotion pentru injecția bazei de date.
     * 
     * @param MySQLWrapper $db Instanța wrapper-ului de bază de date.
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
     * Cauta un utilizator in baza de date pe baza numelui de utilizator.
     *
     * @param string $username Numele de utilizator cautat.
     * @return array|null Datele utilizatorului sau null daca nu exista.
     */
    public function findByUsername(string $username): ?array
    {
        $results = $this->db->read('users', ['username' => $username]);
        return $results ? $results[0] : null;
    }

    /**
     * Creeaza un nou utilizator administrator in sistem.
     *
     * @param string $username Numele de utilizator dorit.
     * @param string $password Parola in format text clar (va fi hash-uita).
     * @return int|bool ID-ul noii inregistrari sau false in caz de eroare.
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
