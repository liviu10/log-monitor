<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Settings;

use App\Core\BaseController;
use App\Core\MySQLWrapper;
use App\Core\Queue;
use App\Core\Validation;
use App\Enums\Role as RoleEnum;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Utilities\FormGenerator;
use App\Utilities\PaginationHelper;
use App\Utilities\SessionManager;

/**
 * Controller for user management in the settings module.
 *
 * @category Controllers
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class UsersController extends BaseController
{
    /**
     * Lists all users and displays creation form.
     */
    public function index(): string
    {
        $paginatedData = PaginationHelper::paginate(
            table: 'users u',
            columns: ['u.*', 'r.name as role_name'],
            baseWhere: '1=1',
            joins: ['JOIN roles r ON u.role_id = r.id'],
            searchFields: ['u.first_name', 'u.last_name', 'u.email', 'r.name', 'u.status', 'u.created_at'],
            allowedSorts: ['id', 'first_name', 'last_name', 'email', 'role_name', 'status', 'created_at'],
            defaultSort: 'id',
            defaultOrder: 'asc',
            sortMap: [
                'role_name' => 'r.name',
                'first_name' => 'u.first_name',
                'last_name' => 'u.last_name',
                'email' => 'u.email',
                'status' => 'u.status',
                'created_at' => 'u.created_at',
            ]
        );

        $items = $paginatedData['items'];
        foreach ($items as &$item) {
            if (isset($item['status'])) {
                $statusVal = UserStatus::getLabel(is_string($item['status']) ? $item['status'] : null);
                $item['raw_status'] = is_string($item['status']) ? $item['status'] : 'ACTIVE';
                $item['status'] = $statusVal;
                $item['status_label'] = __($statusVal);
            }
            if (isset($item['role_name'])) {
                $item['role_label'] = __(is_string($item['role_name']) ? $item['role_name'] : '');
            }
        }
        unset($item);
        $paginatedData['items'] = $items;

        $roles = Role::all();

        $rolesOptions = array_map(function ($role) {
            $roleId = is_scalar($role['id'] ?? null) ? $role['id'] : 0;
            $roleName = is_string($role['name'] ?? null) ? $role['name'] : '';

            return [
                'value' => (string) $roleId,
                'label' => __($roleName),
            ];
        }, $roles);

        $authUser = getCurrentAuthUser();
        $authUserRoleId = is_array($authUser) && is_scalar($authUser['role_id'] ?? null) ? (int) $authUser['role_id'] : PHP_INT_MAX;
        $adminRoleId = Role::getIdByName(RoleEnum::ADMIN) ?? 1;
        $isAdmin = ($authUserRoleId === $adminRoleId);

        $editFieldsList = ['role_id'];
        $editEnums = ['role_id' => $rolesOptions];

        if ($isAdmin) {
            $editFieldsList[] = 'status';
            $editEnums['status'] = [
                ['value' => UserStatus::ACTIVE->value, 'label' => UserStatus::ACTIVE->label()],
                ['value' => UserStatus::INACTIVE->value, 'label' => UserStatus::INACTIVE->label()],
            ];
        }

        $createGenerator = new FormGenerator(
            MySQLWrapper::getInstance(),
            'users',
            ['first_name', 'last_name', 'email']
        );
        $createFields = FormGenerator::attachAntiBotFields($createGenerator->buildForm());

        $editGenerator = new FormGenerator(
            MySQLWrapper::getInstance(),
            'users',
            $editFieldsList,
            $editEnums
        );
        $editFields = FormGenerator::attachAntiBotFields($editGenerator->buildForm());

        /** @var array<string, mixed> $errors */
        $errors = SessionManager::get('errors', []);
        /** @var array<string, mixed> $old */
        $old = SessionManager::get('old', []);
        SessionManager::remove('errors');
        SessionManager::remove('old');

        return $this->render('admin.settings.users.index', [
            'users' => $paginatedData['items'],
            'pagination' => $paginatedData,
            'roles' => $roles,
            'errors' => $errors,
            'old' => $old,
            'createFields' => $createFields,
            'editFields' => $editFields,
        ]);
    }

    /**
     * Saves a new user in the system.
     *
     * @param  array<string, mixed>  $payload  The form data payload.
     */
    public function store(array $payload): void
    {
        $validator = new Validation([
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
        ]);

        $rawErrors = $validator->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:50'],
            'last_name' => ['required', 'string', 'min:2', 'max:50'],
            'email' => ['required', 'email'],
        ], $payload);

        $errors = [];
        if (count($rawErrors) > 0) {
            foreach ($rawErrors as $field => $rulesFailed) {
                foreach ($rulesFailed as $rule) {
                    $errors[$field][] = $validator->messages($field, $rule);
                }
            }
        }

        // Check if email already exists
        if (! isset($errors['email'])) {
            $rawEmail = $payload['email'] ?? '';
            $email = trim(is_string($rawEmail) ? $rawEmail : '');
            $existing = User::findByEmail($email);
            if ($existing !== null) {
                $errors['email'] = [__('The email address is already in use.')];
            }
        }

        if (count($errors) > 0) {
            header('Content-Type: application/json', true, 422);
            // @codeCoverageIgnoreStart
            echo json_encode(['errors' => $errors]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        // Get USER role automatically
        $roleId = Role::getIdByName(RoleEnum::USER) ?? 0;

        if ($roleId === 0) {
            header('Content-Type: application/json', true, 500);
            // @codeCoverageIgnoreStart
            echo json_encode(['errors' => ['general' => [__('Default USER role not found.')]]]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        // Generate secure password (16 hex characters)
        $generatedPassword = bin2hex(random_bytes(8));
        $passwordHash = password_hash($generatedPassword, PASSWORD_ARGON2ID);

        $rawFirstName = $payload['first_name'] ?? '';
        $firstName = trim(is_string($rawFirstName) ? $rawFirstName : '');

        $rawLastName = $payload['last_name'] ?? '';
        $lastName = trim(is_string($rawLastName) ? $rawLastName : '');

        $rawEmailVal = $payload['email'] ?? '';
        $emailVal = trim(is_string($rawEmailVal) ? $rawEmailVal : '');

        User::create([
            'role_id' => $roleId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $emailVal,
            'password_hash' => $passwordHash,
            'status' => 'ACTIVE',
        ]);

        $rawAppUrl = defined('APP_URL') ? APP_URL : '';
        $appUrl = is_scalar($rawAppUrl) && $rawAppUrl !== '' ? (string) $rawAppUrl : (is_string($u = config('app.url')) ? $u : 'http://localhost:8082');
        $loginLink = rtrim($appUrl, '/').'/login';

        Queue::push('emails', [
            'action' => 'email',
            'payload' => [
                'template_event' => 'user_welcome_credentials',
                'data' => [
                    'to' => $emailVal,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'temporary_password' => $generatedPassword,
                    'login_link' => $loginLink,
                ],
            ],
        ]);

        $this->flash('success', __('Success'), __('User created successfully.'));

        header('Content-Type: application/json');
        // @codeCoverageIgnoreStart
        echo json_encode(['success' => true]);
        exit;
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param  array<string, mixed>  $payload  The form data.
     * @param  string  $id  The unique user ID.
     */
    public function update(array $payload, string $id): void
    {
        $userId = (int) $id;

        // Prevent editing oneself
        $authUser = getCurrentAuthUser();
        $authUserId = is_array($authUser) && is_scalar($authUser['id'] ?? null) ? (int) $authUser['id'] : 0;
        if ($userId === $authUserId) {
            header('Content-Type: application/json', true, 403);
            // @codeCoverageIgnoreStart
            echo json_encode(['errors' => ['role_id' => [__('You do not have permission to access this page.')]]]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        $user = User::findById($userId);
        if ($user === null) {
            header('Content-Type: application/json', true, 404);
            // @codeCoverageIgnoreStart
            echo json_encode(['error' => __('User not found.')]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        // Restriction: Administrator can only modify the Role (role_id)
        $rawRoleId = $payload['role_id'] ?? 0;
        $roleId = is_scalar($rawRoleId) ? (int) $rawRoleId : 0;

        if (! Role::exists($roleId)) {
            header('Content-Type: application/json', true, 422);
            // @codeCoverageIgnoreStart
            echo json_encode(['errors' => ['role_id' => [__('Selected role is invalid.')]]]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        // Security check: IDOR/Privilege Escalation protection
        // A user cannot assign a role with an ID strictly less than or equal to their own (lower ID = higher privilege in Aegis logic, ADMIN=1)
        $authUserRoleId = is_array($authUser) && is_scalar($authUser['role_id'] ?? null) ? (int) $authUser['role_id'] : PHP_INT_MAX;

        // Admin can do whatever they want. Others cannot assign roles higher or equal to
        // themselves, and cannot touch accounts whose CURRENT role is ADMIN or higher/equal
        // to their own (otherwise they could demote administrators and lock them out).
        $adminRoleIdForCheck = Role::getIdByName(RoleEnum::ADMIN) ?? 1;
        $targetRoleId = is_scalar($user['role_id'] ?? null) ? (int) $user['role_id'] : 0;
        if ($authUserRoleId !== $adminRoleIdForCheck
            && ($targetRoleId === $adminRoleIdForCheck || $targetRoleId <= $authUserRoleId)) {
            header('Content-Type: application/json', true, 403);
            // @codeCoverageIgnoreStart
            echo json_encode(['errors' => ['role_id' => [__('You do not have permission to modify this user.')]]]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        if ($authUserRoleId !== $adminRoleIdForCheck && $roleId <= $authUserRoleId) {
            header('Content-Type: application/json', true, 403);
            // @codeCoverageIgnoreStart
            echo json_encode(['errors' => ['role_id' => [__('You do not have permission to assign this high-level role.')]]]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        $updateData = [
            'role_id' => $roleId,
        ];

        $adminRoleId = Role::getIdByName(RoleEnum::ADMIN) ?? 1;
        $isAdmin = ($authUserRoleId === $adminRoleId);

        if (isset($payload['status'])) {
            if (! $isAdmin) {
                header('Content-Type: application/json', true, 403);
                // @codeCoverageIgnoreStart
                echo json_encode(['errors' => ['status' => [__('Only administrators can update user status.')]]]);
                exit;
                // @codeCoverageIgnoreEnd
            }

            $rawStatus = is_string($payload['status']) ? strtoupper(trim($payload['status'])) : '';
            if ($rawStatus !== UserStatus::ACTIVE->value && $rawStatus !== UserStatus::INACTIVE->value) {
                header('Content-Type: application/json', true, 422);
                // @codeCoverageIgnoreStart
                echo json_encode(['errors' => ['status' => [__('Invalid user status.')]]]);
                exit;
                // @codeCoverageIgnoreEnd
            }

            $updateData['status'] = $rawStatus;
        }

        User::updateUser($userId, $updateData);

        $this->flash('success', __('Success'), __('User updated successfully.'));

        header('Content-Type: application/json');
        // @codeCoverageIgnoreStart
        echo json_encode(['success' => true]);
        exit;
        // @codeCoverageIgnoreEnd
    }
}
