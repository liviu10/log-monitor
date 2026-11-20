<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\AccessDeniedException;
use App\Exceptions\UnauthorizedException;
use Closure;

/**
 * Middleware for dynamic authorization based on database permissions.
 * Admin Bypass Rule: Users with ADMIN role have global implicit access and bypass checks.
 * For other roles, access is blocked by default (default false) unless the permission is checked.
 *
 * @category Middleware
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class PermissionMiddleware
{
    /**
     * Intercepts request and verifies authorization.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The next middleware closure.
     */
    public function handle(array $route, Closure $next): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $_SESSION['auth.user'] ?? null;
        if (! is_array($user) || count($user) === 0) {
            throw new UnauthorizedException;
        }

        $rawRoleName = $user['role_name'] ?? '';
        $roleName = strtoupper(is_string($rawRoleName) ? $rawRoleName : '');
        $rawRoleId = $user['role_id'] ?? 0;
        $roleId = is_scalar($rawRoleId) ? (int) $rawRoleId : 0;

        // Absolute Security Rule: Admin has implicit full access everywhere
        if ($roleName === 'ADMIN') {
            return $next($route);
        }

        $handler = $route['handler'] ?? '';
        if (is_string($handler) && str_contains($handler, '@')) {
            [$controllerClass, $action] = explode('@', $handler);

            // Universally bypass permission checks for Guest and Common controllers
            if (str_starts_with($controllerClass, 'Guest\\') || str_starts_with($controllerClass, 'Common\\')) {
                return $next($route);
            }

            // Transform Admin\Settings\UsersController to admin.settings.users
            $keyBase = strtolower(str_replace(['\\', 'Controller'], ['.', ''], $controllerClass));

            $actionToCheck = strtolower($action);

            $permissionKey = "{$keyBase}.{$actionToCheck}";

            // Default False and block access if the permission is not explicitly true
            if (! hasPermission($permissionKey)) {
                throw new AccessDeniedException(__('Access Denied'));
            }
        }

        return $next($route);
    }
}
