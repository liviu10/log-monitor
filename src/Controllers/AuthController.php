<?php

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;

/**
 * Clasa AuthController
 *
 * Gestioneaza procesele de autentificare, autorizare si deconectare a utilizatorilor administratori.
 * Include logica pentru afisarea formularului de login, validarea credentialelor folosind hash-uri BCRYPT 
 * si gestionarea sesiunilor active.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class AuthController extends BaseController
{
    /**
     * Afiseaza pagina de login.
     * Daca utilizatorul este deja autentificat, il redirectioneaza catre dashboard.
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
     * Valideaza datele de intrare si verifica parola folosind password_verify.
     */
    public function login(array $data): never
    {
        $payload = $data;
        
        $validator = new Validation([
            'username' => 'utilizator',
            'password' => 'parola',
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

        setFlash('danger', 'Eroare autentificare', 'Utilizator sau parola incorecta.');
        $this->redirect('login.php');
    }

    /**
     * Deconecteaza utilizatorul prin distrugerea sesiunii curente.
     */
    public function logout(): never
    {
        unset($_SESSION['auth.user']);
        session_destroy();
        $this->redirect('login.php');
    }
}
