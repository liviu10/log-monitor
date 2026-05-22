<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;
use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaCurl;

/**
 * Clasa UserController
 *
 * Gestioneaza conturile de utilizatori administratori care au acces la panoul de loguri.
 * Ofera operatiuni de vizualizare, creare si eliminare de conturi, implementand logici defensive
 * pentru prevenirea auto-stergerii accidentale.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class UserController extends BaseController
{
    /**
     * Afiseaza lista tuturor utilizatorilor administratori inregistrati.
     */
    public function index(): void
    {
        $this->checkAuth();
        
        try {
            $db = MySQLWrapper::getInstance();
            $users = $db->read('users');
            
            $this->render('users/index', [
                'users' => $users,
            ]);
        } catch (\Throwable $e) {
            LogViaCurl::send('ERROR', 'Failed to fetch database users list', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'identifier' => 'UserController_Index_DatabaseFailure'
            ]);
            throw new \RuntimeException(__('Unable to retrieve administrators list.'));
        }
    }

    /**
     * Proceseaza crearea unui nou cont de utilizator administrator.
     * Valideaza complexitatea minima a parolei si unicitatea numelui.
     */
    public function store(array $data): never
    {
        $this->checkAuth();
        
        $payload = $data;
        $validator = new Validation([
            'username' => __('Username'),
            'password' => __('Password'),
        ]);
        
        $errors = $validator->validate([
            'username' => ['required', 'string', 'min:3'],
            'password' => ['required', 'string', 'min:6'],
        ], $payload);

        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('users.php');
        }

        try {
            $userModel = new User();
            $userModel->create(trim($payload['username']), $payload['password']);
            
            setFlash('success', __('Success'), __('User created successfully.'));
        } catch (\Throwable $e) {
            LogViaCurl::send('ERROR', 'Admin user creation process exception', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'username' => $payload['username'] ?? null,
                'identifier' => 'UserController_Store_Exception'
            ]);
            setFlash('danger', __('Error'), __('Failed to create user. Possible duplicate username.'));
        }
        
        $this->redirect('users.php');
    }

    /**
     * Sterge un utilizator din sistem pe baza ID-ului trimis prin POST.
     * Include o verificare stricta pentru a bloca auto-stergerea utilizatorului curent.
     */
    public function delete(array $data): never
    {
        $this->checkAuth();
        
        $id = $data['id'] ?? null;
        if ($id) {
            $userId = (int)$id;
            $currentAuthId = (int)($_SESSION['auth.user']['id'] ?? 0);

            if ($userId === $currentAuthId) {
                setFlash('danger', __('Error'), __('You cannot delete your own account.'));
                $this->redirect('users.php');
            }

            try {
                $db = MySQLWrapper::getInstance();
                $db->delete('users', ['id' => $userId]);
                setFlash('success', __('Success'), __('User deleted successfully.'));
            } catch (\Throwable $e) {
                LogViaCurl::send('ERROR', 'Query execution failure event', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'sql_statement' => 'DELETE FROM users WHERE id = :id',
                    'sql_parameters' => ['id' => $userId],
                    'identifier' => 'UserController_Delete_Failure'
                ]);
                setFlash('danger', __('Error'), __('Failed to delete user from database.'));
            }
        }
        
        $this->redirect('users.php');
    }
}