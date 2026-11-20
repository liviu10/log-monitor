<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\LogViaStream;
use App\Enums\LogLevel;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\UnauthorizedException;
use Closure;

/**
 * Role Middleware (RoleMiddleware)
 *
 * Blocks requests from users who do not have the required role to access the resource.
 *
 * @category Middleware
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class RoleMiddleware
{
    /**
     * Intercepts the request and checks if the user has the specified role.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The closure allowing execution to continue.
     * @param  string  $role  The required role name (e.g. ADMIN).
     */
    public function handle(array $route, Closure $next, string $role): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $_SESSION['auth.user'] ?? null;
        if (! is_array($user)) {
            $user = null;
        }
        $path = $route['path'] ?? '';
        $pathStr = is_string($path) ? $path : '';
        $isSettingsRoute = str_starts_with($pathStr, '/admin/settings');

        $rawRoleName = is_array($user) ? ($user['role_name'] ?? '') : '';
        $roleNameStr = is_string($rawRoleName) ? $rawRoleName : '';

        if (! is_array($user) || count($user) === 0 || $roleNameStr === '' || strtoupper($roleNameStr) !== strtoupper($role)) {
            if ($isSettingsRoute) {
                if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                    LogViaStream::send('WARNING', 'Security Alert: Non-admin user attempted to access settings route.', [
                        'user_id' => is_array($user) ? ($user['id'] ?? 'Guest') : 'Guest',
                        'role' => is_array($user) ? ($user['role_name'] ?? 'None') : 'None',
                        'path' => $pathStr,
                        'identifier' => 'Settings_Access_Violation',
                    ]);
                }
                throw new AccessDeniedException(__('Access Denied'));
            }
            throw new UnauthorizedException;
        }

        return $next($route);
    }
}
