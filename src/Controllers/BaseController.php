<?php

namespace App\Controllers;

/**
 * Clasa BaseController
 *
 * Serveste ca clasa de baza pentru toate controllerele din aplicatie.
 * Ofera metode utilitare pentru gestionarea raspunsurilor JSON, randarea vizualizarilor, redirectionari si verificarea autentificarii.
 * Include, de asemenea, setarea header-elor de securitate pentru toate cererile protejate.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class BaseController
{
    /**
     * Trimite un raspuns in format JSON catre client.
     *
     * @param array $data   Datele care vor fi codificate JSON.
     * @param int   $status Codul de stare HTTP (default 200).
     */
    protected function jsonResponse(array $data, int $status = 200): never
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    /**
     * Randeaza un fisier de vizualizare (view) si extrage datele furnizate.
     *
     * @param string $view Numele/Calea fisierului de vizualizare.
     * @param array  $data Datele care vor fi disponibile in vizualizare.
     */
    protected function render(string $view, array $data = []): void
    {
        extract($data);
        require_once __DIR__ . '/../../views/' . $view . '.php';
    }

    /**
     * Redirectioneaza utilizatorul catre un URL specificat si opreste executia.
     *
     * @param string $url URL-ul de destinatie.
     */
    protected function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Verifica daca utilizatorul este autentificat si aplica header-ele de securitate.
     *
     * @return array|null Datele utilizatorului daca este autentificat.
     */
    protected function checkAuth(): ?array
    {
        // Adaugarea header-elor de securitate pentru fiecare cerere autentificata
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        checkAuthUser();
        return getCurrentAuthUser();
    }
}
