<?php

declare(strict_types=1);

namespace App\Utilities;

use InvalidArgumentException;
use LogicException;

/**
 * SecurityHeaders Class
 *
 * HTTP security headers with strict, configurable defaults:
 * Content-Security-Policy (with per-request nonce), Strict-Transport-Security, X-Frame-Options,
 * X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Cross-Origin-Opener-Policy.
 *
 * Rules:
 *   - headers() only computes the header array (without side effects); send() emits them and strips X-Powered-By;
 *   - HSTS is sent only over HTTPS (it would be ignored on HTTP and would break local development);
 *   - Default CSP: only 'self', scripts only with nonce, no inline styles, no frames;
 *     directives are overridden individually, null removes a directive;
 *   - Options and values are validated (a misspelled option throws an exception, rather than silently omitting the header).
 *
 * Usage:
 *   require_once 'SecurityHeaders.php';
 *   use App\Utilities\SecurityHeaders;
 *
 *   SecurityHeaders::send([
 *       'csp' => ['img-src' => "'self' data: https://cdn.example.com", 'style-src' => "'self' 'unsafe-inline'"],
 *       'frameOptions' => 'SAMEORIGIN',
 *   ]);
 *
 *   <script nonce="<?= SecurityHeaders::nonce() ?>">...</script>
 *
 *   // Together with Response:
 *   $response = Response::html($html);
 *   foreach (SecurityHeaders::headers() as $name => $value) {
 *       $response = $response->withHeader($name, $value);
 *   }
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class SecurityHeaders
{
    private const array OPTIONS = [
        'csp', 'cspReportOnly', 'hsts', 'hstsSubdomains', 'hstsPreload', 'frameOptions',
        'referrerPolicy', 'permissionsPolicy', 'crossOriginOpenerPolicy', 'trustForwarded',
    ];

    /** @var string Placeholder replaced in CSP directives with per-request nonce. */
    private const string NONCE = '{nonce}';

    /** @var array<string, string> */
    private const array CSP = [
        'default-src' => "'self'",
        'script-src' => "'self' 'nonce-".self::NONCE."'",
        'style-src' => "'self'",
        'img-src' => "'self' data:",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'none'",
    ];

    /** @var array<string, string> */
    private const array PERMISSIONS = [
        'camera' => '()', 'microphone' => '()', 'geolocation' => '()', 'payment' => '()', 'usb' => '()',
        'magnetometer' => '()', 'gyroscope' => '()',
    ];

    private const array REFERRER_POLICIES = [
        'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
        'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url',
    ];

    private static ?string $nonce = null;

    private function __construct() {}

    /**
     * Per-request CSP nonce (consistent across the entire request), for <script nonce="...">.
     */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    /**
     * Computes security headers without sending them.
     *
     * Options:
     *   csp                      array directive => value (null removes directive) or false (no CSP);
     *                            '{nonce}' in value is replaced with per-request nonce
     *   cspReportOnly            true = Content-Security-Policy-Report-Only (testing without blocking)
     *   hsts                     max-age in seconds (default 1 year) or false
     *   hstsSubdomains           includeSubDomains (default true); hstsPreload (default false)
     *   frameOptions             'DENY' (default), 'SAMEORIGIN' or false
     *   referrerPolicy           defaults to 'strict-origin-when-cross-origin' or false
     *   permissionsPolicy        array feature => allowlist ('()' = disabled) overriding defaults; false = no header
     *   crossOriginOpenerPolicy  'same-origin' (default), 'same-origin-allow-popups', 'unsafe-none' or false
     *   trustForwarded           Detect HTTPS via X-Forwarded-Proto (only behind a trusted proxy)
     *
     * @param  array<string, mixed>  $options
     * @param  array<array-key, mixed>|null  $server  Defaults to $_SERVER (for HTTPS detection).
     * @return array<string, string>
     *
     * @throws InvalidArgumentException If an option is unrecognized or has an invalid value.
     */
    public static function headers(array $options = [], ?array $server = null): array
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException("SecurityHeaders: unknown option '".implode("', '", $unknown)."'.");
        }

        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $csp = self::csp($options['csp'] ?? []);
        if ($csp !== null) {
            $headers[self::bool($options, 'cspReportOnly', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $csp;
        }

        $hsts = $options['hsts'] ?? 31_536_000;
        if ($hsts !== false && self::isHttps($server ?? $_SERVER, self::bool($options, 'trustForwarded', false))) {
            if (! is_int($hsts) || $hsts < 0) {
                throw new InvalidArgumentException('SecurityHeaders: option \'hsts\' must be a number of seconds (>= 0) or false.');
            }

            $headers['Strict-Transport-Security'] = "max-age={$hsts}"
                .(self::bool($options, 'hstsSubdomains', true) ? '; includeSubDomains' : '')
                .(self::bool($options, 'hstsPreload', false) ? '; preload' : '');
        }

        $frame = self::choice($options, 'frameOptions', 'DENY', ['DENY', 'SAMEORIGIN']);
        if ($frame !== null) {
            $headers['X-Frame-Options'] = $frame;
        }

        $referrer = self::choice($options, 'referrerPolicy', 'strict-origin-when-cross-origin', self::REFERRER_POLICIES);
        if ($referrer !== null) {
            $headers['Referrer-Policy'] = $referrer;
        }

        $permissions = self::permissions($options['permissionsPolicy'] ?? []);
        if ($permissions !== null) {
            $headers['Permissions-Policy'] = $permissions;
        }

        $coop = self::choice($options, 'crossOriginOpenerPolicy', 'same-origin', ['same-origin', 'same-origin-allow-popups', 'unsafe-none']);
        if ($coop !== null) {
            $headers['Cross-Origin-Opener-Policy'] = $coop;
        }

        return $headers;
    }

    /**
     * Emits headers and strips X-Powered-By (PHP version).
     *
     * @param  array<string, mixed>  $options  See headers().
     *
     * @throws InvalidArgumentException If an option is invalid.
     * @throws LogicException If headers have already been sent (with output file and line).
     */
    public static function send(array $options = []): void
    {
        $headers = self::headers($options);

        if (headers_sent($file, $line)) {
            throw new LogicException("SecurityHeaders::send(): headers already sent (output started at {$file}:{$line}).");
        }

        header_remove('X-Powered-By');
        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function csp(mixed $option): ?string
    {
        if ($option === false) {
            return null;
        }

        if (! is_array($option)) {
            throw new InvalidArgumentException('SecurityHeaders: option \'csp\' must be an array of directives or false.');
        }

        $directives = self::CSP;
        foreach ($option as $name => $value) {
            $name = (string) $name;
            if (preg_match('/^[a-z][a-z-]*$/', $name) !== 1) {
                throw new InvalidArgumentException("SecurityHeaders: invalid CSP directive name '{$name}'.");
            }

            if ($value === null) {
                unset($directives[$name]);

                continue;
            }

            // ';' si ',' ar incheia directiva sau politica; liniile noi ar injecta header-e.
            if (! is_string($value) || preg_match('/[;,\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException("SecurityHeaders: invalid value for CSP directive '{$name}' (expected a string without ';', ',' or control characters).");
            }

            $directives[$name] = trim($value);
        }

        $parts = [];
        foreach ($directives as $name => $value) {
            $parts[] = trim($name.' '.str_replace(self::NONCE, self::nonce(), $value));
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function permissions(mixed $option): ?string
    {
        if ($option === false) {
            return null;
        }

        if (! is_array($option)) {
            throw new InvalidArgumentException('SecurityHeaders: option \'permissionsPolicy\' must be an array or false.');
        }

        $policy = self::PERMISSIONS;
        foreach ($option as $feature => $allowlist) {
            $feature = (string) $feature;
            if (preg_match('/^[a-z][a-z-]*$/', $feature) !== 1) {
                throw new InvalidArgumentException("SecurityHeaders: invalid Permissions-Policy feature '{$feature}'.");
            }

            if ($allowlist === null) {
                unset($policy[$feature]);

                continue;
            }

            // Lista: () | * | (self "https://a.ro") | self
            if (! is_string($allowlist) || preg_match('/^(\*|self|\((\s*(self|\*|"https?:\/\/[^"\s,;]+")\s*)*\))$/', $allowlist) !== 1) {
                throw new InvalidArgumentException("SecurityHeaders: invalid Permissions-Policy allowlist for '{$feature}' (expected e.g. '()', '(self)', '(self \"https://a.ro\")').");
            }

            $policy[$feature] = $allowlist;
        }

        $parts = [];
        foreach ($policy as $feature => $allowlist) {
            $parts[] = "{$feature}={$allowlist}";
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $allowed
     *
     * @throws InvalidArgumentException
     */
    private static function choice(array $options, string $key, string $default, array $allowed): ?string
    {
        $value = $options[$key] ?? $default;
        if ($value === false) {
            return null;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("SecurityHeaders: option '{$key}' must be one of '".implode("', '", $allowed)."' or false.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    private static function bool(array $options, string $key, bool $default): bool
    {
        $value = $options[$key] ?? $default;
        if (! is_bool($value)) {
            throw new InvalidArgumentException("SecurityHeaders: option '{$key}' must be bool, got ".get_debug_type($value).'.');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $server
     */
    private static function isHttps(array $server, bool $trustForwarded): bool
    {
        if ($trustForwarded && is_string($server['HTTP_X_FORWARDED_PROTO'] ?? null)) {
            return strtolower(trim(explode(',', $server['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
        }

        $https = $server['HTTPS'] ?? null;
        $port = $server['SERVER_PORT'] ?? null;

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off') || $port === '443' || $port === 443;
    }
}
