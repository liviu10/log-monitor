<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;

/**
 * Security Headers Middleware
 *
 * Emits HTTP security headers globally along with strict anti-indexing / anti-AI directives.
 */
class SecurityHeadersMiddleware
{
    /**
     * Intercepts request and applies security headers before passing it further.
     *
     * @param  array<string, mixed>  $route  The current route data.
     * @param  Closure  $next  The next closure permitting execution.
     */
    public function handle(array $route, Closure $next): mixed
    {
        if (! headers_sent()) {
            header_remove('X-Powered-By');
            header_remove('Server');

            header('X-Frame-Options: DENY');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=()');

            // Strict anti-indexing & anti-AI crawler enforcement
            header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai');

            $httpsValue = $_SERVER['HTTPS'] ?? '';
            $httpsRaw = is_scalar($httpsValue) ? (string) $httpsValue : '';
            $https = strtolower($httpsRaw);
            $fwdProtoRaw = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            $fwdProto = is_scalar($fwdProtoRaw) ? (string) $fwdProtoRaw : '';
            $isSecure = ($https !== '' && $https !== 'off') || ($fwdProto === 'https');

            $hostValue = $_SERVER['HTTP_HOST'] ?? '';
            $hostRaw = is_scalar($hostValue) ? (string) $hostValue : '';
            $isLocalhost = in_array($hostRaw, ['localhost', '127.0.0.1', '[::1]'], true) || str_starts_with($hostRaw, 'localhost:');

            if ($isSecure || $isLocalhost) {
                header('Cross-Origin-Opener-Policy: same-origin');
            }

            $nonce = defined('APP_NONCE') ? constant('APP_NONCE') : '';
            header(
                "Content-Security-Policy: default-src 'self'; ".
                "script-src 'self' 'unsafe-eval' 'nonce-".$nonce."'; ".
                "style-src 'self' 'unsafe-inline'; ".
                "font-src 'self'; ".
                "img-src 'self' data:; ".
                "connect-src 'self'; ".
                "frame-ancestors 'none'; ".
                "object-src 'none'; ".
                "base-uri 'self'; ".
                "form-action 'self';"
            );
        }

        return $next($route);
    }
}
