<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Clasa BaseController
 *
 * Serveste ca clasa de baza pentru toate controllerele din aplicatie.
 * Ofera metode utilitare pentru gestionarea raspunsurilor JSON, randarea vizualizarilor,
 * redirectionari si verificarea autentificarii, inclusiv aplicarea headerelor de securitate.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class BaseController
{
    /**
     * Trimite un raspuns in format JSON catre client si opreste executia.
     *
     * @param array $data   Datele care vor fi codificate in format JSON.
     * @param int   $status Codul de stare HTTP (implicit 200).
     */
    protected function jsonResponse(array $data, int $status = 200): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Randeaza un fisier de vizualizare si extrage datele furnizate.
     *
     * @param string $view Numele sau calea fisierului de vizualizare.
     * @param array  $data Datele care vor fi disponibile in vizualizare.
     */
    protected function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
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
     * Verifica daca utilizatorul este autentificat si aplica headerele de securitate OWASP.
     *
     * @return array|null Datele utilizatorului daca este autentificat.
     */
    protected function checkAuth(): ?array
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        checkAuthUser();
        return getCurrentAuthUser();
    }
}