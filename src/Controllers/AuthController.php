<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;
use App\Utilities\LogViaCurl;

/**
 * Clasa AuthController
 *
 * Gestioneaza procesele de autentificare, autorizare si delogare pentru utilizatorii administratori.
 * Include logica pentru afisarea formularului de login, validarea credentialelor utilizand hash-uri Argon2id/BCRYPT
 * si managementul sesiunilor active intr-un mod securizat.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AuthController extends BaseController
{
    /**
     * Afiseaza pagina de login.
     * Daca utilizatorul este deja autentificat, il redirectioneaza spre dashboard.
     */
    public function showLogin(): void
    {
        if (isset($_SESSION['auth.user'])) {
            $this->redirect('index.php');
        }
        $this->render('auth/login');
    }

    /**
     * Proceseaza tentativa de autentificare a unui utilizator.
     * Valideaza datele de intrare si verifica parola utilizand functii securizate native.
     */
    public function login(array $data): never
    {
        $payload = $data;
        
        $validator = new Validation([
            'username' => __('Username'),
            'password' => __('Password'),
        ]);

        $errors = $validator->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], $payload);

        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('login.php');
        }

        try {
            $userModel = new User();
            $user = $userModel->findByUsername(trim($payload['username']));

            if ($user && password_verify($payload['password'], $user['password_hash'])) {
                // Prevenirea atacurilor de tip Session Fixation prin regenerarea ID-ului sesiunii
                session_regenerate_id(true);
                
                $_SESSION['auth.user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                ];
                $this->redirect('index.php');
            }
            
            // Logare audit pentru esec autentificare (potential atac fortat)
            LogViaCurl::send('WARNING', 'Failed authentication attempt', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'username' => $payload['username'],
                'identifier' => 'AuthController_Login_FailedAttempt'
            ]);
        } catch (\Throwable $e) {
            LogViaCurl::send('ERROR', 'Critical authentication exception process', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'AuthController_Login_SystemException'
            ]);
        }

        setFlash('danger', __('Authentication error'), __('Incorrect username or password.'));
        $this->redirect('login.php');
    }

    /**
     * Delogheaza utilizatorul si distruge complet orice urma a sesiunii active.
     */
    public function logout(): never
    {
        // 1. Golirea completa a vectorului global $_SESSION pentru a sterge datele din memoria runtime
        $_SESSION = [];

        // 2. Stergerea si invalidarea totala a cookie-ului de sesiune de pe client (browser)
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }

        // 3. Distrugerea fizica a datelor sesiunii de pe server
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // 4. Redirectionare defensiva catre pagina de login
        $this->redirect('login.php');
    }
}