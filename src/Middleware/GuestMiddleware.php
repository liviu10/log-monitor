<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;

/**
 * Guest Middleware (GuestMiddleware)
 *
 * Redirects already authenticated users away from visitor-only pages.
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
class GuestMiddleware
{
    /**
     * Intercepts the request and redirects already authenticated users.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The closure that allows execution continuation.
     */
    public function handle(array $route, Closure $next): mixed
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['auth.user'])) {
            redirect('/admin');
        }

        return $next($route);
    }
}
