<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Throwable;

/**
 * Authentication Middleware (AuthMiddleware)
 *
 * Prevents access for unauthenticated users and redirects them to login.
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
class AuthMiddleware
{
    /** Maximum age of the cached role/status before it is re-read from the database. */
    private const REVALIDATE_SECONDS = 60;

    /**
     * Intercepts request and blocks access for unauthenticated users.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The next closure permitting execution.
     */
    public function handle(array $route, Closure $next): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (! isset($_SESSION['auth.user'])) {
            // Anti-Enumeration: Returnam 404 in loc de 302 pentru a ascunde complet rutele de atacatori
            throw new \RuntimeException(__('Page not found.'), 404);
        }

        $this->revalidateSession();

        return $next($route);
    }

    /**
     * Re-reads the user's status and role from the database at most once per
     * REVALIDATE_SECONDS. A deactivated or deleted account is logged out and a
     * demoted user loses the old role immediately, instead of keeping the
     * snapshot taken at login for the whole session lifetime.
     */
    private function revalidateSession(): void
    {
        $verifiedAt = $_SESSION['auth.verified_at'] ?? 0;
        if (is_int($verifiedAt) && (time() - $verifiedAt) < self::REVALIDATE_SECONDS) {
            return;
        }

        $sessionUser = $_SESSION['auth.user'];
        $rawId = is_array($sessionUser) ? ($sessionUser['id'] ?? null) : null;
        $userId = is_numeric($rawId) ? (int) $rawId : 0;

        try {
            $fresh = $userId > 0 ? User::findById($userId) : null;
        } catch (Throwable) {
            // Database unavailable: keep the session (the request will fail elsewhere anyway).
            return;
        }

        $status = is_array($fresh) && is_string($fresh['status'] ?? null) ? $fresh['status'] : '';
        if (! is_array($fresh) || $status !== UserStatus::ACTIVE->value) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            throw new \RuntimeException(__('Page not found.'), 404);
        }

        $sessionUser['role_id'] = is_scalar($fresh['role_id'] ?? null) ? (int) $fresh['role_id'] : 0;
        $sessionUser['role_name'] = is_string($fresh['role_name'] ?? null) ? $fresh['role_name'] : '';
        $sessionUser['status'] = UserStatus::getLabel($status);
        $_SESSION['auth.user'] = $sessionUser;
        $_SESSION['auth.verified_at'] = time();
    }
}
