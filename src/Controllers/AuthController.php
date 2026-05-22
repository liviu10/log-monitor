<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;

/**
 * AuthController Class
 *
 * Manages the authentication, authorization, and logout processes for administrator users.
 * Includes the logic for displaying the login form, validating credentials using BCRYPT hashes 
 * and managing active sessions.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
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
     * Processes the authentication attempt of a user.
     * Validates input data and checks the password using password_verify.
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

        $userModel = new User();
        $user = $userModel->findByUsername($payload['username']);

        if ($user && password_verify($payload['password'], $user['password_hash'])) {
            $_SESSION['auth.user'] = [
                'id' => $user['id'],
                'username' => $user['username'],
            ];
            $this->redirect('index.php');
        }

        setFlash('danger', __('Authentication error'), __('Incorrect username or password.'));
        $this->redirect('login.php');
    }

    /**
     * Logs the user out by destroying the current session.
     */
    public function logout(): never
    {
        unset($_SESSION['auth.user']);
        session_destroy();
        $this->redirect('login.php');
    }
}
