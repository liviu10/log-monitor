<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Settings;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Core\PermissionScanner;
use App\Enums\LogLevel;
use App\Models\Role;
use App\Models\RolePermission;
use App\Utilities\PaginationHelper;
use Throwable;

/**
 * Controller for roles and permissions management in the settings module.
 */
class RolesController extends BaseController
{
    /** Permission groups that can never be toggled from the UI or the API. */
    private const HIDDEN_GROUPS = [
        'Admin - Settings - Roles',
        'Admin - Settings - Users',
        'Auth - Auth',
        'Common - Queue',
        'Common - Lang',
        'Common - Notification',
        'Common - NotifyManager',
    ];

    public function index(): string
    {
        $paginatedData = PaginationHelper::paginate(
            table: 'roles',
            columns: ['*'],
            baseWhere: '1=1',
            searchFields: ['name'],
            allowedSorts: ['id', 'name', 'is_active'],
            defaultSort: 'id',
            defaultOrder: 'asc'
        );

        $items = $paginatedData['items'];
        foreach ($items as &$item) {
            if (isset($item['name'])) {
                $item['name_label'] = __(is_string($item['name']) ? $item['name'] : '');
            }
        }
        unset($item);
        $paginatedData['items'] = $items;

        return $this->render('admin.settings.roles.index', [
            'title' => __('Roles Management'),
            'roles' => $paginatedData['items'],
            'pagination' => $paginatedData,
        ]);
    }

    public function permissions(string $roleId): string
    {
        $role = Role::findById((int) $roleId);

        if ($role === null) {
            throw new \RuntimeException(__('Role not found.'), 404);
        }

        $permissions = [];
        $roleName = $role['name'] ?? '';
        $roleNameStr = is_string($roleName) ? $roleName : '';
        if (strtoupper($roleNameStr) !== 'ADMIN') {
            PermissionScanner::syncToDatabase();

            $allPermissions = RolePermission::getByRoleId((int) $roleId);
            $hiddenGroups = self::HIDDEN_GROUPS;

            $permissions = array_filter($allPermissions, function ($perm) use ($hiddenGroups) {
                return ! in_array($perm['group_name'] ?? '', $hiddenGroups, true);
            });
        }

        return $this->render('admin.settings.roles.permissions', [
            'title' => __('Role Permissions'),
            'role' => $role,
            'permissions' => $permissions,
        ]);
    }

    public function updatePermissions(string $roleId): void
    {
        $role = Role::findById((int) $roleId);

        if ($role === null) {
            header('Content-Type: application/json', true, 404);
            // @codeCoverageIgnoreStart
            echo json_encode(['error' => __('Role not found.')]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        $roleName = $role['name'] ?? '';
        $roleNameStr = is_string($roleName) ? $roleName : '';
        if (strtoupper($roleNameStr) === 'ADMIN') {
            header('Content-Type: application/json', true, 403);
            // @codeCoverageIgnoreStart
            echo json_encode(['error' => __('ADMIN permissions cannot be modified.')]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        $inputData = file_get_contents('php://input');
        $input = [];
        if (is_string($inputData) && $inputData !== '') {
            try {
                $decoded = json_decode($inputData, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $input = $decoded;
                }
            } catch (Throwable) {
                // Ignore invalid JSON
            }
        }
        $permissions = $input['permissions'] ?? [];

        $caller = getCurrentAuthUser();
        $callerRole = is_array($caller) && is_string($caller['role_name'] ?? null) ? strtoupper($caller['role_name']) : '';
        $callerRoleId = is_array($caller) && is_numeric($caller['role_id'] ?? null) ? (int) $caller['role_id'] : 0;
        if ($callerRole !== 'ADMIN' && $callerRoleId === (int) $roleId) {
            header('Content-Type: application/json', true, 403);
            // @codeCoverageIgnoreStart
            echo json_encode(['error' => __('You cannot modify the permissions of your own role.')]);
            exit;
            // @codeCoverageIgnoreEnd
        }

        if (is_array($permissions)) {
            try {
                $allowedIds = [];
                foreach (RolePermission::getByRoleId((int) $roleId) as $perm) {
                    if (! in_array($perm['group_name'] ?? '', self::HIDDEN_GROUPS, true) && is_numeric($perm['id'] ?? null)) {
                        $allowedIds[(int) $perm['id']] = true;
                    }
                }
                $permissions = array_filter(
                    $permissions,
                    static fn ($permId): bool => isset($allowedIds[(int) $permId]),
                    ARRAY_FILTER_USE_KEY
                );

                RolePermission::updateBulk((int) $roleId, $permissions);
            } catch (Throwable $e) {
                if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Failed to update bulk permissions for role', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'identifier' => 'RolesController_UpdatePermissions_Failure',
                    ]);
                }
                header('Content-Type: application/json', true, 500);
                // @codeCoverageIgnoreStart
                echo json_encode(['error' => $e->getMessage()]);
                exit;
                // @codeCoverageIgnoreEnd
            }
        }

        $this->flash('success', __('Success'), __('Permissions updated successfully.'));

        header('Content-Type: application/json');
        // @codeCoverageIgnoreStart
        echo json_encode(['success' => true]);
        exit;
        // @codeCoverageIgnoreEnd
    }
}
