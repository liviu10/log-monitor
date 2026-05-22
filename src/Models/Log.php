<?php

namespace App\Models;

use App\Utilities\MySQLWrapper;
use PDO;

/**
 * Clasa Log
 *
 * Gestioneaza operatiunile de baza de date pentru entitatea Log.
 * Ofera functionalitati pentru crearea de noi inregistrari, recuperarea paginata a logurilor cu filtre si numararea totalului de loguri.
 * Utilizeaza indexarea FULLTEXT pentru cautari performante in mesajele si contextul logurilor.
 *
 * @category Model
 * @package  App\Models
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class Log
{
    /**
     * Constructorul clasei Log.
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
     * Creeaza o noua inregistrare de log in baza de date.
     *
     * @param int    $appId   ID-ul aplicatiei care genereaza logul.
     * @param string $level   Nivelul de severitate al logului.
     * @param string $message Mesajul descriptiv al logului.
     * @param mixed  $context Date suplimentare (va fi stocat ca JSON).
     * @return int|bool ID-ul inregistrarii create sau false in caz de eroare.
     */
    public function create(int $appId, string $level, string $message, mixed $context = null): int|bool
    {
        return $this->db->create('logs', [
            'app_id' => $appId,
            'level' => $level,
            'message' => $message,
            'context' => $context ? json_encode($context) : null,
        ]);
    }

    /**
     * Recupereaza o lista paginata de loguri pe baza filtrelor aplicate.
     *
     * @param array $filters Tablou cu filtre.
     * @param int   $limit   Numarul maxim de inregistrari per pagina.
     * @param int   $offset  Punctul de start pentru paginare.
     * @return array Tablou de loguri gasite.
     */
    public function getPaginated(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT l.*, a.name as app_name 
                FROM logs l 
                JOIN apps a ON l.app_id = a.id 
                WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $sql .= " AND l.app_id = ?";
            $params[] = $filters['app_id'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND l.level = ?";
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)";
            $params[] = $filters['search'] . "*";
        }

        $sql .= " ORDER BY l.created_at DESC LIMIT $limit OFFSET $offset";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Numara totalul de loguri care corespund filtrelor selectate.
     *
     * @param array $filters Tablou cu filtre aplicate.
     * @return int Numarul total de loguri gasite.
     */
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) FROM logs l WHERE 1=1";
        $params = [];

        if (!empty($filters['app_id'])) {
            $sql .= " AND l.app_id = ?";
            $params[] = $filters['app_id'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND l.level = ?";
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND MATCH(l.message, l.context) AGAINST(? IN BOOLEAN MODE)";
            $params[] = $filters['search'] . "*";
        }

        $stmt = $this->db->query($sql, $params);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }

    /**
     * Numara totalul de loguri create inainte de o anumita data.
     *
     * @param string $date Data limita (cutoff date).
     * @return int Numarul total de loguri.
     */
    public function countBeforeDate(string $date): int
    {
        $sql = "SELECT COUNT(*) FROM logs WHERE created_at < ?";
        $stmt = $this->db->query($sql, [$date]);
        return $stmt ? (int)$stmt->fetchColumn() : 0;
    }

    /**
     * Recupereaza logurile create inainte de o anumita data, paginat.
     *
     * @param string $date   Data limita.
     * @param int    $limit  Numarul de loguri per chunk.
     * @param int    $offset Punctul de start.
     * @return array Tablou de loguri cu numele aplicatiei asociate.
     */
    public function getBeforeDate(string $date, int $limit, int $offset): array
    {
        $sql = "SELECT l.*, a.name as app_name 
                FROM logs l 
                JOIN apps a ON l.app_id = a.id 
                WHERE l.created_at < ? 
                ORDER BY l.created_at ASC 
                LIMIT {$limit} OFFSET {$offset}";
        
        $stmt = $this->db->query($sql, [$date]);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Sterge logurile create inainte de o anumita data.
     *
     * @param string $date Data limita.
     * @return int|false Numarul de inregistrari sterse sau false in caz de eroare.
     */
    public function deleteBeforeDate(string $date): int|false
    {
        $sql = "DELETE FROM logs WHERE created_at < ?";
        $stmt = $this->db->query($sql, [$date]);
        return $stmt ? $stmt->rowCount() : false;
    }

    /**
     * Optimizeaza tabela logs pentru a elibera spatiul de stocare.
     *
     * @return bool True in caz de succes, false altfel.
     */
    public function optimize(): bool
    {
        $stmt = $this->db->query("OPTIMIZE TABLE logs");
        return $stmt !== false;
    }
}
