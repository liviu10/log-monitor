<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\MySQLWrapper;
use App\Enums\Role as RoleEnum;

/**
 * Role Model
 *
 * Manages queries and changes in the roles table.
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
class Role
{
    /**
     * List all roles sorted alphabetically.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT * FROM roles ORDER BY name ASC');
        $rows = $stmt->fetchAll();
        /** @var array<int, array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * Checks if a role exists by ID.
     */
    public static function exists(int $id): bool
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT id FROM roles WHERE id = ? LIMIT 1', [$id]);

        return (bool) $stmt->fetch();
    }

    /**
     * Find a role by ID.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT * FROM roles WHERE id = ? LIMIT 1', [$id]);
        $role = $stmt->fetch();

        if (is_array($role)) {
            /** @var array<string, mixed> $role */
            return $role;
        }

        return null;
    }

    /**
     * Delete is prohibited for roles.
     *
     * @throws \RuntimeException
     */
    public static function delete(int $id): bool
    {
        throw new \RuntimeException(__('Roles deletion is prohibited.'));
    }

    /**
     * Retrieves a role ID by its Enum name.
     */
    public static function getIdByName(RoleEnum $roleEnum): ?int
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT id FROM roles WHERE name = ? LIMIT 1', [$roleEnum->value]);
        $row = $stmt->fetch();

        return (is_array($row) && isset($row['id']) && is_scalar($row['id'])) ? (int) $row['id'] : null;
    }
}
