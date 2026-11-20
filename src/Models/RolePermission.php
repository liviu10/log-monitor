<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Enums\LogLevel;
use Throwable;

/**
 * Role Permissions Model
 *
 * Manages query permissions check per role from database.
 *
 * @category Model
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class RolePermission
{
    /**
     * Checks if a specific role has a granted permission.
     *
     * @param  int  $roleId  The role ID.
     * @param  string  $permissionKey  The permission key.
     * @return bool True if the permission is granted.
     */
    public static function isGranted(int $roleId, string $permissionKey): bool
    {
        try {
            $db = MySQLWrapper::getInstance();
            $row = $db->read('role_permissions', [
                'role_id' => $roleId,
                'permission_key' => $permissionKey,
                'value' => 1,
            ]);

            return count($row) > 0;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to check if role permission is granted', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'RolePermission_IsGranted_Failure',
                ]);
            }

            return false;
        }
    }

    /**
     * Returns all permissions associated with a role.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getByRoleId(int $roleId): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query(
            'SELECT * FROM role_permissions WHERE role_id = ? ORDER BY group_name ASC, permission_key ASC',
            [$roleId]
        );
        $rows = $stmt->fetchAll();
        /** @var array<int, array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * Updates a role's permissions in bulk using a transaction.
     *
     * @param  array<int|string, mixed>  $permissions
     */
    public static function updateBulk(int $roleId, array $permissions): void
    {
        $db = MySQLWrapper::getInstance();
        $conn = $db->getConnection();

        $conn->beginTransaction();
        try {
            foreach ($permissions as $permId => $val) {
                $db->update('role_permissions', [
                    'value' => ((bool) $val) ? 1 : 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], [
                    'id' => (int) $permId,
                    'role_id' => $roleId,
                ]);
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollBack();
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to update bulk role permissions in database transaction', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'RolePermission_UpdateBulk_Failure',
                ]);
            }
            throw $e;
        }
    }
}
