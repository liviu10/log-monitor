<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Models\User;
use App\Utilities\LogViaStream;
use App\Utilities\Validation;

/**
 * UserController Class
 *
 * Manages administrator user accounts that have access to the log panel.
 * Offers view, creation, and removal operations for accounts, implementing defensive logic
 * to prevent accidental self-deletion.
 *
 * @category Controller
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
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
            $userModel = new User();
            $users = $userModel->getAll();

            $this->render('users/index', [
                'users' => $users,
            ]);
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to fetch database users list', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'UserController_Index_DatabaseFailure',
                ]);
            }

            throw new \RuntimeException(__('Unable to retrieve administrators list.'));
        }
    }

    /**
     * Processes the creation of a new administrator user account.
     * Validates the minimum complexity of the password and uniqueness of the username.
     *
     * @param  array<array-key, mixed>  $data
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
            'username' => ['required', 'string', 'min:3', 'max:50'],
            'password' => ['required', 'string', 'min:6'],
        ], $payload);

        if (! empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('users.php');
        }

        try {
            $userModel = new User;
            $usernameVal = $payload['username'] ?? '';
            $passwordVal = $payload['password'] ?? '';

            if (is_string($usernameVal) && is_string($passwordVal)) {
                $userModel->create(trim($usernameVal), $passwordVal);
                setFlash('success', __('Success'), __('User created successfully.'));
            } else {
                setFlash('danger', __('Error'), __('Invalid username or password format.'));
            }
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Admin user creation process exception', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'username' => $payload['username'] ?? null,
                    'identifier' => 'UserController_Store_Exception',
                ]);
            }

            setFlash('danger', __('Error'), __('Failed to create user. Possible duplicate username.'));
        }

        $this->redirect('users.php');
    }

    /**
     * Deletes a user from the system based on the ID sent via POST.
     * Includes a strict check to block the self-deletion of the current user.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function delete(array $data): never
    {
        $this->checkAuth();

        $id = $data['id'] ?? null;
        if ($id) {
            $userId = (is_int($id) || is_string($id)) ? (int) $id : 0;
            $authUser = $_SESSION['auth.user'] ?? null;
            $currentAuthId = 0;
            if (is_array($authUser)) {
                $rawAuthId = $authUser['id'] ?? null;
                $currentAuthId = (is_int($rawAuthId) || is_string($rawAuthId)) ? (int) $rawAuthId : 0;
            }

            if ($userId === $currentAuthId) {
                setFlash('danger', __('Error'), __('You cannot delete your own account.'));
                $this->redirect('users.php');
            }

            try {
                $userModel = new User();
                $userModel->delete($userId);
                setFlash('success', __('Success'), __('User deleted successfully.'));
            } catch (\Throwable $e) {
                if (class_exists('App\Utilities\LogViaStream')) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Query execution failure event', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'exception_trace' => $e->getTraceAsString(),
                        'sql_statement' => 'DELETE FROM users WHERE id = :id',
                        'sql_parameters' => ['id' => $userId],
                        'identifier' => 'UserController_Delete_Failure',
                    ]);
                }

                setFlash('danger', __('Error'), __('Failed to delete user from database.'));
            }
        }

        $this->redirect('users.php');
    }
}
