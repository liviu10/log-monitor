<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;
use App\Utilities\MySQLWrapper;

/**
 * UserController Class
 *
 * Manages users who have access to the log system control panel.
 * Allows viewing, creating, and deleting administrator accounts, 
 * including security rules to prevent self-deletion.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class UserController extends BaseController
{
    /**
     * Displays the list of all registered administrators.
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
     * Processes the creation of a new administrator account.
     * Validates minimum password complexity and username uniqueness.
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

        $userModel = new User();
        $userModel->create($payload['username'], $payload['password']);
        
        setFlash('success', __('Success'), __('User created successfully.'));
        $this->redirect('users.php');
    }

    /**
     * Deletes a user from the system.
     * Includes a security check to prevent an administrator from deleting their own account.
     */
    public function delete(array $data): never
    {
        $this->checkAuth();
        
        $id = $data['id'] ?? null;
        if ($id) {
            // Preventing user from deleting their own session
            if ((int)$id === (int)($_SESSION['auth.user']['id'] ?? 0)) {
                setFlash('danger', __('Error'), __('You cannot delete your own account.'));
                $this->redirect('users.php');
            }

            $db = MySQLWrapper::getInstance();
            $db->delete('users', ['id' => $id]);
            setFlash('success', __('Success'), __('User deleted successfully.'));
        }
        
        $this->redirect('users.php');
    }
}
