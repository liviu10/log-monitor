<?php

declare(strict_types=1);

/**
 * Fisier cu Functii Ajutatoare Globale
 *
 * Contine functii utilitare globale si configurari de baza pentru sesiune si securitate.
 * Toate cheile de traducere folosesc limba engleza pentru consistenta.
 *
 * @category Utilitare
 * @package  App\Utilities
 * @version  2.5
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

use Symfony\Component\VarDumper\VarDumper;

/**
 * Injecteaza headerele de securitate la nivel de runtime PHP.
 * Asigura protectia pe serverul clasic (unde nu exista acces la configuratii)
 * si ofera al doilea strat de protectie (Defense in Depth) in containere.
 *
 * @return void
 */
if (!function_exists('injectRuntimeSecurityHeaders')) {
    function injectRuntimeSecurityHeaders(): void
    {
        // Previne atacurile de tip Clickjacking prin blocarea incadrarii in iframe
        header('X-Frame-Options: DENY', true);

        // Previne atacurile de tip MIME sniffing
        header('X-Content-Type-Options: nosniff', true);

        // Seteaza politica de transmitere a headerului Referer
        header('Referrer-Policy: strict-origin-when-cross-origin', true);

        // Politica stricta pentru executia de scripturi Vanilla JS si resurse
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; style-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self';", true);

        // Activare HSTS daca request-ul este securizat
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                   (($_SERVER['SERVER_PORT'] ?? '') === '443') ||
                   (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload', true);
        }
    }
}

// Validare si pornire securizata a sesiunii
if (session_id() === '') {
    ini_set('session.gc_maxlifetime', '86400');
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
    
    // Apelam injectarea la nivel de runtime pentru a acoperi ambele scenarii
    injectRuntimeSecurityHeaders();
}

/** @const string ROLE_ADMIN Identificatorul pentru rolul de administrator. */
define('ROLE_ADMIN', 'admin');

/**
 * Afiseaza variabilele primite si opreste executia scriptului (Dump and Die).
 * Utilizeaza componenta VarDumper din Symfony pentru o vizualizare clara.
 *
 * @param mixed ...$args Una sau mai multe variabile care vor fi inspectate.
 * @return never Opreste definitiv executia programului.
 */
if (!function_exists('dd')) {
    /**
     * @return never
     * @param mixed ...$args
     */
    function dd(...$args): void
    {
        foreach ($args as $x) {
            VarDumper::dump($x);
        }

        exit(1);
    }
}

/**
 * Construieste URL-ul de baza al aplicatiei in mod dinamic.
 * Detecteaza automat protocolul securizat, serverul si subdirectorul de instalare.
 * Previne atacurile de tip Host Header Injection prin validare stricta.
 *
 * @return string URL-ul complet de baza al aplicatiei.
 */
if (!function_exists('constructUrl')) {
    function constructUrl(): string
    {
        $rawHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Validare stricta a host-ului pentru prevenirea Injection-ului
        $host = filter_var($rawHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if ($host === false) {
            $host = 'localhost';
        }
        
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim($scriptDir, '/\\');
        
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                   (($_SERVER['SERVER_PORT'] ?? '') === 443) ||
                   (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                   
        $protocol = $isHttps ? "https" : "http";
        
        return sprintf('%s://%s%s', $protocol, $host, $basePath);
    }
}

/**
 * Verifica starea de autentificare si gestioneaza procesul de delogare.
 * Aplica principiul Fail Fast si regenereaza sesiunea pentru a preveni Session Fixation.
 *
 * @return bool Returneaza true daca utilizatorul are o sesiune valida.
 */
if (!function_exists('checkAuthUser')) {
    function checkAuthUser(): bool
    {
        // Tratare actiune de delogare
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            unset($_SESSION['auth.user']);
            session_destroy();

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            session_regenerate_id(true);

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

        // Setare mesaj flash cu chei exclusiv in limba engleza
        setFlash(
            'warning', 
            __('Authentication Warning'), 
            __('You must log in to access this page.')
        );

        header('Location: login.php');
        exit;
    }
}

/**
 * Returneaza datele utilizatorului autentificat din sesiunea curenta.
 *
 * @return array|null Tablou cu datele utilizatorului sau null daca nu este autentificat.
 */
if (!function_exists('getCurrentAuthUser')) {
    function getCurrentAuthUser(): ?array
    {
        $user = $_SESSION['auth.user'] ?? null;
        return is_array($user) ? $user : null;
    }
}

/**
 * Formateaza o data din formatul specific SQL in formatul european standard.
 *
 * @param string $dateString Data in format text (ex: Y-m-d).
 * @return string Data formatata ca zi.luna.an.
 */
if (!function_exists('formatDate')) {
    function formatDate(string $dateString): string
    {
        $timestamp = strtotime($dateString);
        return date('d.m.Y', $timestamp ?: time());
    }
}

/**
 * Verifica daca utilizatorul curent are o anumita permisiune (Mockup).
 *
 * @param string $permission Identificatorul permisiunii verificate.
 * @return bool True daca permisiunea este acordata.
 */
if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return true; 
    }
}

/**
 * Genereaza un token CSRF criptografic si il salveaza in sesiune.
 *
 * @return string Token-ul generat in format hexazecimal.
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
 * Verifica validitatea unui token CSRF folosind o comparatie imuna la atacuri de sincronizare.
 *
 * @param string|null $token Token-ul primit pentru verificare.
 * @return bool True daca token-ul coincide cu cel din sesiune.
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
 * Inregistreaza un mesaj temporar (flash) in sesiunea aplicatiei.
 *
 * @param string $type Tipul mesajului (ex: success, error, warning).
 * @param string $title Titlul notificarii.
 * @param string $message Textul descriptiv al notificarii.
 * @param int $toastDelay Timpul de afisare in milisecunde.
 * @param string $redirectUrl URL optional pentru redirectionare.
 * @return void
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
 * Extrage si sterge mesajul flash existent in sesiune (consum unic).
 *
 * @return array|null Datele mesajului flash sau null daca nu exista.
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

/**
 * Returneaza codul pentru limba activa in aplicatie.
 *
 * @return string Codul de limba (implicit 'en').
 */
if (!function_exists('getLang')) {
    function getLang(): string
    {
        if (!isset($_SESSION['app_lang'])) {
            $_SESSION['app_lang'] = 'en';
        }

        return (string)$_SESSION['app_lang'];
    }
}

/**
 * Traduce o cheie text si inlocuieste parametrii dinamici intr-un mod securizat contra XSS.
 * Utilizeaza functia nativa din PHP 8.4 json_validate pentru siguranta sporita.
 *
 * @param string $key Cheia din cadrul de traducere.
 * @param array $replacements Vector asociativ cu parametrii de inlocuit.
 * @return string Textul final tradus si igienizat.
 */
if (!function_exists('__')) {
    function __(string $key, array $replacements = []): string
    {
        static $translations = null;

        if ($translations === null) {
            $lang = getLang();
            $path = __DIR__ . sprintf('/../../lang/%s.json', $lang);

            if (file_exists($path)) {
                $content = file_get_contents($path);
                $jsonValidate = function (string $json, int $depth = 512, int $flags = 0) {
                    if (function_exists('json_validate')) {
                        return json_validate($json, $depth, $flags);
                    }
                    $maxDepth = 0x7fffffff;
                    if (0 !== $flags && \defined('JSON_INVALID_UTF8_IGNORE') && \JSON_INVALID_UTF8_IGNORE !== $flags) {
                        throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
                    }
                    if ($depth <= 0) {
                        throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
                    }
                    if ($depth > $maxDepth) {
                        throw new \ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', $maxDepth));
                    }
                    json_decode($json, true, $depth, $flags);
                    return \JSON_ERROR_NONE === json_last_error();
                };
                if ($content !== false && $jsonValidate($content)) {
                    $translations = json_decode($content, true) ?: [];
                } else {
                    $translations = [];
                }
            } else {
                $translations = [];
            }
        }

        $text = $translations[$key] ?? $key;

        foreach ($replacements as $placeholder => $value) {
            $safeValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            $text = str_replace(':' . $placeholder, $safeValue, $text);
        }

        return $text;
    }
}