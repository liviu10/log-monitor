<?php

declare(strict_types=1);

namespace App\Core;

use App\Utilities\DirectoryScanner;
use ReflectionClass;
use ReflectionMethod;

/**
 * Utility for scanning application controllers using Reflection API.
 * Synchronizes discovered permissions in the database.
 */
final class PermissionScanner
{
    /**
     * Scans the controllers directory and returns all exposed valid permissions.
     *
     * @return array<int, array{permission_key: string, name: string, group_name: string}>
     */
    public static function scan(): array
    {
        $permissions = [];
        $projectRoot = dirname(__DIR__, 2);

        $controllersDir = realpath($projectRoot.'/src/Controllers');
        if (! is_string($controllersDir) || ! is_dir($controllersDir)) {
            return $permissions;
        }

        $files = DirectoryScanner::scan($controllersDir, 'php');

        foreach ($files as $filePath) {
            $className = self::getClassNameFromFile($filePath);

            if ($className !== null && $className !== '' && class_exists($className)) {
                $reflection = new ReflectionClass($className);

                // @codeCoverageIgnoreStart
                if (! $reflection->isInstantiable()) {
                    continue;
                }
                // @codeCoverageIgnoreEnd

                if (str_starts_with($className, 'App\\Controllers\\Guest\\') ||
                    str_starts_with($className, 'App\\Controllers\\Common\\')) {
                    continue;
                }

                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method) {
                    $methodName = $method->getName();

                    // @codeCoverageIgnoreStart
                    if (str_starts_with($methodName, '__')) {
                        continue;
                    }
                    if ($method->getDeclaringClass()->getName() !== $className) {
                        continue;
                    }
                    // @codeCoverageIgnoreEnd

                    $shortClass = str_replace(['App\\Controllers\\', 'Controller'], '', $className);
                    $keyBase = strtolower(str_replace('\\', '.', $shortClass));
                    $permissionKey = "{$keyBase}.".strtolower($methodName);

                    $groupName = str_replace('\\', ' - ', ltrim($shortClass, '\\'));
                    $name = $shortClass.' - '.ucfirst($methodName);

                    $permissions[] = [
                        'permission_key' => $permissionKey,
                        'name' => $name,
                        'group_name' => $groupName,
                    ];
                }
            }
        }

        return $permissions;
    }

    /**
     * Synchronizes scanned permissions with the `role_permissions` table for non-admin roles.
     */
    public static function syncToDatabase(): void
    {
        $permissions = self::scan();
        $db = MySQLWrapper::getInstance();

        $stmtRoles = $db->query("SELECT id, name FROM roles WHERE name != 'ADMIN'");
        $roles = $stmtRoles->fetchAll();
        /** @var array<int, array<string, mixed>> $roles */
        foreach ($roles as $role) {
            $rawId = $role['id'] ?? 0;
            $roleId = is_scalar($rawId) ? (int) $rawId : 0;
            // @codeCoverageIgnoreStart
            if ($roleId === 0) {
                continue;
            }
            // @codeCoverageIgnoreEnd

            foreach ($permissions as $p) {
                $stmtCheck = $db->query(
                    'SELECT id FROM role_permissions WHERE role_id = ? AND `permission_key` = ? LIMIT 1',
                    [$roleId, $p['permission_key']]
                );

                if ($stmtCheck->fetch() === false) {
                    $db->create('role_permissions', [
                        'role_id' => $roleId,
                        'permission_key' => $p['permission_key'],
                        'name' => $p['name'],
                        'group_name' => $p['group_name'],
                        'value' => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        }
    }

    private static function getClassNameFromFile(string $filePath): ?string
    {
        $realFilePath = realpath($filePath);
        if (! is_string($realFilePath)) {
            return null;
        }

        $projectRoot = dirname(__DIR__, 2);
        $srcDir = $projectRoot.'/src';
        if (str_starts_with($realFilePath, $srcDir)) {
            $relative = substr($realFilePath, strlen($srcDir));
            $relative = ltrim($relative, DIRECTORY_SEPARATOR);

            return 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);
        }

        // @codeCoverageIgnoreStart
        return null;
        // @codeCoverageIgnoreEnd
    }
}
