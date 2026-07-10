<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Utilities\Validation;
use App\Utilities\MySQLWrapper;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * UserController Class
 *
 * Manages administrator user accounts that have access to the log panel.
 * Offers view, creation, and removal operations for accounts, implementing defensive logic
 * to prevent accidental self-deletion.
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
     * Displays a list of all registered administrator users.
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
            LogViaStream::send(LogLevel::ERROR->value, 'Failed to fetch database users list', [
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
     * Processes the creation of a new administrator user account.
     * Validates the minimum complexity of the password and uniqueness of the username.
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
            LogViaStream::send(LogLevel::ERROR->value, 'Admin user creation process exception', [
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
     * Deletes a user from the system based on the ID sent via POST.
     * Includes a strict check to block the self-deletion of the current user.
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
                LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
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