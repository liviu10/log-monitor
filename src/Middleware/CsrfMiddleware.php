<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use RuntimeException;

/**
 * CSRF Middleware (CsrfMiddleware)
 *
 * Protects against Cross-Site Request Forgery (CSRF) attacks by validating the token on POST requests.
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
class CsrfMiddleware
{
    /**
     * Intercepts POST requests and validates CSRF token presence and correctness.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The next middleware closure.
     *
     * @throws RuntimeException If the CSRF token is invalid or missing.
     */
    public function handle(array $route, Closure $next): mixed
    {
        $method = config('server.request_method');
        if (is_string($method) && in_array(strtoupper($method), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $rawToken = $_POST['csrf_token'] ?? config('server.http_x_csrf_token') ?? null;

            // If token not found in POST / headers, check raw request body (for native PUT/DELETE/PATCH)
            // @codeCoverageIgnoreStart
            if ($rawToken === null || $rawToken === '') {
                $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
                $contentTypeStr = is_string($contentType) ? $contentType : '';
                if (str_contains($contentTypeStr, 'application/json')) {
                    $rawInput = file_get_contents('php://input');
                    if (is_string($rawInput) && $rawInput !== '') {
                        try {
                            $json = json_decode($rawInput, true);
                            if (is_array($json) && isset($json['csrf_token'])) {
                                $rawToken = $json['csrf_token'];
                            }
                        } catch (\Throwable) {
                            // Ignore invalid JSON when payload is malformed
                        }
                    }
                } else {
                    $rawInput = file_get_contents('php://input');
                    if (is_string($rawInput) && $rawInput !== '') {
                        parse_str($rawInput, $bodyData);
                        if (isset($bodyData['csrf_token'])) {
                            $rawToken = $bodyData['csrf_token'];
                        }
                    }
                }
            }
            // @codeCoverageIgnoreEnd

            $token = is_string($rawToken) ? $rawToken : null;

            if ($token === null || $token === '' || ! function_exists('verifyCsrfToken') || ! verifyCsrfToken($token)) {
                throw new RuntimeException(__('Invalid CSRF token.'), 403);
            }

            // Re-bind CSRF token to prevent Cross-User Forgery
            // If the user is authenticated, the token is bound to their session.
            // When transitioning from guest to auth (e.g. login), session_regenerate_id is called
            // which mitigates session fixation, but we should also ensure the CSRF token is refreshed.
            $authData = $_SESSION['auth.user'] ?? null;
            if (is_array($authData)) {
                $expectedCsrfUser = $_SESSION['csrf_token_user'] ?? null;
                if ($expectedCsrfUser !== ($authData['id'] ?? 0)) {
                    throw new RuntimeException(__('Invalid CSRF token for the current user context.'), 403);
                }
            }
        }

        return $next($route);
    }
}
