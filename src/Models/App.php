<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;

/**
 * Clasa App
 *
 * Gestioneaza entitatile de tip aplicatie care pot trimite loguri catre sistem.
 * Ofera metode pentru identificarea unei aplicatii prin cheia API, recuperarea tuturor aplicatiilor, 
 * crearea de noi aplicatii si stergerea celor existente.
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
     * Constructorul clasei App.
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
        // Dacă folosim Singleton, putem suprascrie aici sau lăsa promoția să gestioneze instanța implicită
        $this->db = MySQLWrapper::getInstance();
    }

    /**
     * Gaseste o aplicatie in baza de date folosind cheia API unica.
     *
     * @param string $apiKey Cheia API de cautat.
     * @return array|null Datele aplicatiei sau null daca nu este gasita.
     */
    public function findByApiKey(string $apiKey): ?array
    {
        $results = $this->db->read('apps', ['api_key' => $apiKey]);
        return $results ? $results[0] : null;
    }

    /**
     * Recupereaza toate aplicatiile inregistrate in sistem.
     *
     * @return array Tablou cu toate aplicatiile.
     */
    public function getAll(): array
    {
        return $this->db->read('apps', [], ['*']) ?: [];
    }

    /**
     * Inregistreaza o noua aplicatie in sistem.
     *
     * @param string $name   Numele aplicatiei.
     * @param string $apiKey Cheia API generata pentru aplicatie.
     * @return int|bool ID-ul noii inregistrari sau false in caz de eroare.
     */
    public function create(string $name, string $apiKey): int|bool
    {
        return $this->db->create('apps', [
            'name' => $name,
            'api_key' => $apiKey,
        ]);
    }

    /**
     * Sterge o aplicatie din sistem pe baza ID-ului.
     *
     * @param int $id ID-ul aplicatiei de sters.
     * @return bool True in caz de succes, false altfel.
     */
    public function delete(int $id): bool
    {
        return (bool)$this->db->delete('apps', ['id' => $id]);
    }
}
