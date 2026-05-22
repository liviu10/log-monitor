<?php

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;
use App\Utilities\MySQLWrapper;

/**
 * Clasa UserController
 *
 * Administreaza utilizatorii care au acces la panoul de control al sistemului de loguri.
 * Permite vizualizarea, crearea si eliminarea conturilor de administrator, 
 * incluzand reguli de securitate pentru a preveni auto-stergerea.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class UserController extends BaseController
{
    /**
     * Afiseaza lista tuturor administratorilor inregistrati.
     */
    public function index(): void
    {
        $this->checkAuth();
        
        $db = MySQLWrapper::getInstance();
        $users = $db->read('users');
        
        $this->render('users/index', [
            'users' => $users,
        ]);
    }

    /**
     * Proceseaza crearea unui nou cont de administrator.
     * Valideaza complexitatea minima a parolei si unicitatea numelui de utilizator.
     */
    public function store(array $data): never
    {
        $this->checkAuth();
        
        $payload = $data;
        $validator = new Validation([
            'username' => 'utilizator',
            'password' => 'parola',
        ]);
        
        $errors = $validator->validate([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
        ], $payload);

        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('users.php');
        }

        $userModel = new User();
        $userModel->create($payload['username'], $payload['password']);
        
        setFlash('success', 'Succes', 'Utilizatorul a fost creat cu succes.');
        $this->redirect('users.php');
    }

    /**
     * Sterge un utilizator din sistem.
     * Include o verificare de securitate pentru a impiedica un administrator sa isi stearga propriul cont.
     */
    public function delete(array $data): never
    {
        $this->checkAuth();
        
        $id = $data['id'] ?? null;
        if ($id) {
            // Nu lasam utilizatorul sa se stearga pe sine din sesiune
            if ((int)$id === (int)($_SESSION['auth.user']['id'] ?? 0)) {
                setFlash('danger', 'Eroare', 'Nu iti poti sterge propriul cont.');
                $this->redirect('users.php');
            }

            $db = MySQLWrapper::getInstance();
            $db->delete('users', ['id' => $id]);
            setFlash('success', 'Succes', 'Utilizatorul a fost sters.');
        }
        
        $this->redirect('users.php');
    }
}
