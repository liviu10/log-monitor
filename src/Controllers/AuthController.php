<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * AuthController Class
 *
 * Manages the authentication, authorization, and logout processes for admin users.
 * Includes logic for displaying the login form, validating credentials using Argon2id/BCRYPT hashes,
 * and secure active session management.
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
     * Displays the login page.
     * If the user is already authenticated, redirects them to the dashboard.
     */
    public function showLogin(): void
    {
        if (isset($_SESSION['auth.user'])) {
            $this->redirect('index.php');
        }
        $this->render('auth/login');
    }

    /**
     * Processes a user authentication attempt.
     * Validates input data and verifies password using native secure functions.
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
                // Prevent Session Fixation attacks by regenerating the session ID
                session_regenerate_id(true);
                
                $_SESSION['auth.user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                ];
                $this->redirect('index.php');
            }
            
            // Audit log for authentication failure (potential brute force attack)
            LogViaStream::send(LogLevel::WARNING->value, 'Failed authentication attempt', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'username' => $payload['username'],
                'identifier' => 'AuthController_Login_FailedAttempt'
            ]);
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Critical authentication exception process', [
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
     * Logs the user out and completely destroys any trace of the active session.
     */
    public function logout(): never
    {
        // 1. Completely clear the global $_SESSION array to erase data from runtime memory
        $_SESSION = [];

        // 2. Erase and fully invalidate the session cookie on the client (browser)
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

        // 3. Physically destroy session data on the server
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // 4. Defensive redirection to the login page
        $this->redirect('login.php');
    }
}