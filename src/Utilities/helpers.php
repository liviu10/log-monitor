<?php
/**
 * Fisier Utilitar: helpers.php
 *
 * Contine functii globale si setari de baza pentru aplicatie, incluzand gestionarea sesiunilor, 
 * functii de debug, constructia URL-urilor, managementul autentificarii si mesaje flash de tip toast.
 *
 * @category Utility
 * @package  App\Utilities
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

use Symfony\Component\VarDumper\VarDumper;

/**
 * Porneste sesiunea daca nu este deja pornita.
 * Seteaza durata de viata a sesiunii si parametrii cookie-urilor de sesiune la 24 de ore.
 */
if (session_id() === '') {
    ini_set('session.gc_maxlifetime', '86400'); // Seteaza durata de viata a sesiunii la 24 de ore
    session_set_cookie_params(86400); // Seteaza durata de viata a cookie-ului de sesiune la 24 de ore
    session_start(); // Porneste sesiunea
}

/** @const string Rolul de administrator implicit. */
define('ROLE_ADMIN', 'admin');

/**
 * Afiseaza variabilele date si opreste executia scriptului (Debug & Die).
 * Utilizeaza VarDumper de la Symfony pentru o mai buna vizualizare a variabilelor.
 * 
 * @param mixed ...$args Una sau mai multe variabile de afisat.
 */
if (!function_exists('dd')) {
    function dd(mixed ...$args): never
    {
        foreach ($args as $x) {
            VarDumper::dump($x);
        }
        exit(1);
    }
}

/**
 * Construieste URL-ul de baza al aplicatiei in mod dinamic.
 * Detecteaza automat protocolul, serverul si subfolderul in care este instalat proiectul.
 * 
 * @return string URL-ul de baza al aplicatiei.
 */
if (!function_exists('constructUrl')) {
    function constructUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim($scriptDir, '/\\');
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https" : "http";
        
        return "{$protocol}://{$host}{$basePath}";
    }
}

/**
 * Verifica daca utilizatorul este autentificat in sistem.
 * Gestioneaza si procesul de deconectare daca parametrul 'action=logout' este prezent.
 * 
 * @return bool Returneaza true daca utilizatorul are o sesiune valida, altfel redirect catre login.
 */
if (!function_exists('checkAuthUser')) {
    function checkAuthUser(): bool
    {
        // Gestionare deconectare (Logout)
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            unset($_SESSION['auth.user']);
            session_destroy();
            header('Location: login.php');
            exit;
        }

        $currentPage = basename($_SERVER['PHP_SELF'] ?? '');

        if ($currentPage === 'login.php') {
            return true;
        }

        if (isset($_SESSION['auth.user']) && is_array($_SESSION['auth.user'])) {
            return true;
        }

        if (!isset($_SESSION['auth.user']) || empty($_SESSION['auth.user'])) {
            setFlash(
                'warning', 
                'Atentionare autentificare', 
                'Trebuie sa te autentifici pentru a accesa aceasta pagina.'
            );

            header('Location: login.php');
            exit;
        }
        
        return true;
    }
}

/**
 * Returneaza datele utilizatorului autentificat din sesiune.
 * 
 * @return array|null Tablou cu datele utilizatorului sau null daca nu este logat.
 */
if (!function_exists('getCurrentAuthUser')) {
    function getCurrentAuthUser(): ?array
    {
        $user = $_SESSION['auth.user'] ?? null;
        return is_array($user) ? $user : null;
    }
}

/**
 * Formateaza o data din formatul SQL (YYYY-MM-DD) in formatul european (d.m.Y).
 */
if (!function_exists('formatDate')) {
    function formatDate(string $dateString): string
    {
        return date('d.m.Y', strtotime($dateString) ?: time());
    }
}

/**
 * Verifica daca utilizatorul curent are o anumita permisiune (Mockup).
 */
if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return true; 
    }
}

/**
 * Genereaza un token CSRF unic pentru sesiunea curenta.
 */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

/**
 * Verifica daca un token CSRF primit corespunde cu cel din sesiune.
 */
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool
    {
        if (!$token || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], $token);
    }
}

/**
 * Seteaza un mesaj flash (notificare temporara) in sesiune pentru a fi afisat ca toast.
 */
if (!function_exists('setFlash')) {
    function setFlash(string $type, string $title, string $message, int $toastDelay = 10000, string $redirectUrl = ''): void
    {
        $_SESSION['app_flash'] = [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'toastDelay' => $toastDelay,
            'redirectUrl' => $redirectUrl,
        ];
    }
}

/**
 * Recupereaza mesajul flash din sesiune si il sterge imediat (consum unitar).
 */
if (!function_exists('getFlash')) {
    function getFlash(): ?array
    {
        if (isset($_SESSION['app_flash'])) {
            $flash = $_SESSION['app_flash'];
            unset($_SESSION['app_flash']);

            return is_array($flash) ? $flash : null;
        }

        return null;
    }
}
