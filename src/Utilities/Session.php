<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use RuntimeException;

/**
 * Session Class
 *
 * PHP sessions started with secure defaults and managed via explicit methods.
 * Natively, session_start() after output produces only a warning, client-supplied IDs are accepted
 * (session fixation if use_strict_mode is disabled), and values that cannot be serialized (Closure)
 * are silently lost at the end of the request.
 *
 * Rules:
 *   - start() must be called explicitly; cookie HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode;
 *   - Accessing the session before calling start() = LogicException (not a standard lost $_SESSION);
 *   - get() without a default value throws an exception if the key is missing;
 *   - After authentication call regenerate(); on logout call destroy();
 *   - flash() retains a value only for the subsequent request (flash messages after redirect),
 *     flashInput() / old() repopulates forms after failed validation;
 *   - idleTimeout clears the session after a specified period of inactivity.
 *
 * Usage:
 *   require_once 'Session.php';
 *   use App\Utilities\Session;
 *
 *   Session::start(['name' => 'APPSESSID', 'idleTimeout' => 1800]);
 *   Session::set('user_id', 5);
 *   $userId = Session::get('user_id');             // missing -> throws exception
 *   $tema = Session::get('tema', 'light');         // missing -> 'light'
 *   Session::regenerate();                         // after login
 *   Session::flash('status', 'Polita a fost salvata.');
 *   $status = Session::flashed('status');          // in the next request
 *   Session::destroy();                            // logout
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
final class Session
{
    /** @var string Reserved key for internal metadata (flash, last activity). */
    private const string META = '__session';

    private const array OPTIONS = ['name', 'lifetime', 'path', 'domain', 'secure', 'sameSite', 'savePath', 'idleTimeout'];

    private static bool $expired = false;

    private function __construct() {}

    /**
     * Starts the session. If already active (e.g. started by legacy code), does nothing.
     *
     * Options:
     *   name         Cookie name (defaults to php.ini setting);
     *   lifetime     Cookie lifetime in seconds (0 = until browser is closed);
     *   path, domain Cookie path and domain (defaults to '/' and current domain);
     *   secure       Defaults to true only over HTTPS;
     *   sameSite     Lax (default), Strict, or None;
     *   savePath     Session files directory path;
     *   idleTimeout  Seconds of inactivity after which session is cleared (see expired()).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException If an option is unrecognized or invalid.
     * @throws LogicException If headers have already been sent.
     * @throws RuntimeException If session cannot be started.
     */
    public static function start(array $options = []): void
    {
        self::$expired = false;

        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Session::start(): unknown option '".implode("', '", $unknown)."'.");
        }

        $name = self::option($options, 'name', 'string');
        $lifetime = self::option($options, 'lifetime', 'int') ?? 0;
        $path = self::option($options, 'path', 'string') ?? '/';
        $domain = self::option($options, 'domain', 'string') ?? '';
        $secure = self::option($options, 'secure', 'bool') ?? self::isHttps();
        $sameSite = self::option($options, 'sameSite', 'string') ?? 'Lax';
        $savePath = self::option($options, 'savePath', 'string');
        $idleTimeout = self::option($options, 'idleTimeout', 'int');

        if (! in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException("Session::start(): sameSite must be Lax, Strict or None, got '{$sameSite}'.");
        }

        if ($sameSite === 'None' && ! $secure) {
            throw new InvalidArgumentException('Session::start(): sameSite None requires secure: true.');
        }

        if ($idleTimeout !== null && $idleTimeout < 1) {
            throw new InvalidArgumentException('Session::start(): idleTimeout must be at least 1 second.');
        }

        if ($name !== null && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("Session::start(): invalid session name '{$name}' (letters, digits and underscore, starting with a letter).");
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (session_status() === PHP_SESSION_DISABLED) {
            throw new RuntimeException('Session::start(): sessions are disabled in this PHP installation.');
        }

        if (headers_sent($file, $line)) {
            throw new LogicException("Session::start(): headers already sent (output started at {$file}:{$line}).");
        }

        $settings = [
            'use_strict_mode' => true,
            'use_cookies' => true,
            'cookie_httponly' => true,
            'cookie_secure' => $secure,
            'cookie_samesite' => $sameSite,
            'cookie_lifetime' => $lifetime,
            'cookie_path' => $path,
            'cookie_domain' => $domain,
            'cache_limiter' => 'nocache',
        ];

        if ($name !== null) {
            $settings['name'] = $name;
        }

        if ($savePath !== null) {
            if (! is_dir($savePath) || ! is_writable($savePath)) {
                throw new RuntimeException("Session::start(): save path '{$savePath}' is not a writable directory.");
            }

            $settings['save_path'] = $savePath;
        }

        [$started, $warning] = self::capture(static fn (): bool => session_start($settings));
        if (! $started) {
            throw new RuntimeException('Session::start(): cannot start the session'.($warning === null ? '.' : ": {$warning}."));
        }

        self::afterStart($idleTimeout);
    }

    public static function isStarted(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Checks if the session was cleared during start() due to inactivity (idleTimeout).
     */
    public static function expired(): bool
    {
        return self::$expired;
    }

    /**
     * @throws LogicException If the session is not started.
     */
    public static function id(): string
    {
        self::assertStarted('id');

        return (string) session_id();
    }

    /**
     * Retrieves the value of a key. With a single argument, the key is required; with two arguments, the second is the default.
     *
     * @throws LogicException If the session is not started.
     * @throws OutOfBoundsException If the key does not exist and no default value was provided.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::assertStarted('get');

        if (array_key_exists($key, $_SESSION) && $key !== self::META) {
            return $_SESSION[$key];
        }

        if (func_num_args() > 1) {
            return $default;
        }

        throw new OutOfBoundsException("Session::get(): key '{$key}' does not exist.");
    }

    /**
     * @throws LogicException If the session is not started.
     * @throws InvalidArgumentException If the key is reserved or the value cannot be stored (Closure, resource).
     */
    public static function set(string $key, mixed $value): void
    {
        self::assertStarted('set');
        self::assertKey('set', $key);
        self::assertStorable('set', $key, $value);

        $_SESSION[$key] = $value;
    }

    /**
     * @throws LogicException If the session is not started.
     */
    public static function has(string $key): bool
    {
        self::assertStarted('has');

        return $key !== self::META && array_key_exists($key, $_SESSION);
    }

    /**
     * @throws LogicException If the session is not started.
     */
    public static function forget(string $key): void
    {
        self::assertStarted('forget');

        if ($key !== self::META) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Retrieves the value and removes it from the session.
     *
     * @throws LogicException If the session is not started.
     * @throws OutOfBoundsException If the key does not exist and no default value was provided.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = func_num_args() > 1 ? self::get($key, $default) : self::get($key);
        self::forget($key);

        return $value;
    }

    /**
     * All application values (excluding internal metadata).
     *
     * @return array<array-key, mixed>
     *
     * @throws LogicException If the session is not started.
     */
    public static function all(): array
    {
        self::assertStarted('all');

        $all = $_SESSION;
        unset($all[self::META]);

        return $all;
    }

    /**
     * Clears all application values; the session remains active with the same ID.
     *
     * @throws LogicException If the session is not started.
     */
    public static function clear(): void
    {
        self::assertStarted('clear');

        $meta = $_SESSION[self::META] ?? null;
        $_SESSION = [];
        if ($meta !== null) {
            $_SESSION[self::META] = $meta;
        }
    }

    /**
     * Generates a new session ID while preserving data; invalidates the previous ID.
     * Call after authentication and privilege changes (mitigates session fixation).
     *
     * @throws LogicException If the session is not started or headers were sent.
     * @throws RuntimeException If the session ID cannot be regenerated.
     */
    public static function regenerate(): void
    {
        self::assertStarted('regenerate');
        self::assertHeadersNotSent('regenerate');

        [$done, $warning] = self::capture(static fn (): bool => session_regenerate_id(true));
        if (! $done) {
            throw new RuntimeException('Session::regenerate(): cannot regenerate the session ID'.($warning === null ? '.' : ": {$warning}."));
        }
    }

    /**
     * Logout: clears session data, deletes the session cookie, and destroys server-side session.
     *
     * @throws LogicException If the session is not started or headers were sent.
     * @throws RuntimeException If the session cannot be destroyed.
     */
    public static function destroy(): void
    {
        self::assertStarted('destroy');
        self::assertHeadersNotSent('destroy');

        $_SESSION = [];

        $params = session_get_cookie_params();
        [$deleted, $warning] = self::capture(static fn (): bool => setcookie((string) session_name(), '', [
            'expires' => 1,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]));

        if (! $deleted) {
            throw new RuntimeException('Session::destroy(): cannot delete the session cookie'.($warning === null ? '.' : ": {$warning}."));
        }

        [$destroyed, $warning] = self::capture(static fn (): bool => session_destroy());
        if (! $destroyed) {
            throw new RuntimeException('Session::destroy(): cannot destroy the session'.($warning === null ? '.' : ": {$warning}."));
        }
    }

    /**
     * Writes session data and releases the file lock (for long-running requests: exports, reports).
     * After close(), the session can no longer be modified within the current request.
     *
     * @throws LogicException If the session is not started.
     * @throws RuntimeException If the session cannot be written.
     */
    public static function close(): void
    {
        self::assertStarted('close');

        [$closed, $warning] = self::capture(static fn (): bool => session_write_close());
        if (! $closed) {
            throw new RuntimeException('Session::close(): cannot write the session'.($warning === null ? '.' : ": {$warning}."));
        }
    }

    /**
     * Stores a value available only in the subsequent request (e.g. flash message after redirect).
     *
     * @throws LogicException If the session is not started.
     * @throws InvalidArgumentException If the value cannot be stored.
     */
    public static function flash(string $key, mixed $value): void
    {
        self::assertStarted('flash');
        self::assertStorable('flash', $key, $value);

        $next = self::meta('next');
        $next[$key] = $value;
        self::storeMeta('next', $next);
    }

    /**
     * Retrieves a value flashed in the previous request or returns $default.
     *
     * @throws LogicException If the session is not started.
     */
    public static function flashed(string $key, mixed $default = null): mixed
    {
        self::assertStarted('flashed');

        $current = self::meta('current');

        return array_key_exists($key, $current) ? $current[$key] : $default;
    }

    /**
     * Retains form input for the subsequent request (after validation failure and redirect).
     * Do not save passwords: omit them from $input.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws LogicException If the session is not started.
     * @throws InvalidArgumentException If input data cannot be stored.
     */
    public static function flashInput(array $input): void
    {
        self::assertStarted('flashInput');
        self::assertStorable('flashInput', 'input', $input);

        self::storeMeta('nextInput', $input);
    }

    /**
     * Retrieves form input flashed in the previous request (for repopulation) or returns $default.
     *
     * @throws LogicException If the session is not started.
     */
    public static function old(string $key, mixed $default = null): mixed
    {
        self::assertStarted('old');

        $input = self::meta('input');

        return array_key_exists($key, $input) ? $input[$key] : $default;
    }

    /**
     * Moves flashed values for current request and evaluates idleTimeout.
     *
     * @throws RuntimeException If the expired session cannot be regenerated with a new ID.
     */
    private static function afterStart(?int $idleTimeout): void
    {
        $meta = $_SESSION[self::META] ?? [];
        $meta = is_array($meta) ? $meta : [];
        $now = time();

        $lastActivity = $meta['lastActivity'] ?? null;
        if ($idleTimeout !== null && is_int($lastActivity) && $now - $lastActivity > $idleTimeout) {
            $_SESSION = [];
            $meta = [];
            self::$expired = true;

            [$regenerated, $warning] = self::capture(static fn (): bool => session_regenerate_id(true));
            if (! $regenerated) {
                throw new RuntimeException('Session::start(): cannot regenerate the expired session'.($warning === null ? '.' : ": {$warning}."));
            }
        }

        $_SESSION[self::META] = [
            'current' => is_array($meta['next'] ?? null) ? $meta['next'] : [],
            'next' => [],
            'input' => is_array($meta['nextInput'] ?? null) ? $meta['nextInput'] : [],
            'nextInput' => [],
            'lastActivity' => $now,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function meta(string $key): array
    {
        $meta = $_SESSION[self::META] ?? null;
        $value = is_array($meta) ? ($meta[$key] ?? null) : null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function storeMeta(string $key, array $value): void
    {
        $meta = $_SESSION[self::META] ?? null;
        $meta = is_array($meta) ? $meta : [];
        $meta[$key] = $value;
        $_SESSION[self::META] = $meta;
    }

    /**
     * Retrieves an option validated against the expected type or null if missing.
     *
     * @param  array<string, mixed>  $options
     * @param  'string'|'int'|'bool'  $type
     * @return ($type is 'string' ? ?string : ($type is 'int' ? ?int : ?bool))
     *
     * @throws InvalidArgumentException If option type does not match.
     */
    private static function option(array $options, string $key, string $type): string|int|bool|null
    {
        $value = $options[$key] ?? null;
        if ($value === null || ((is_string($value) || is_int($value) || is_bool($value)) && get_debug_type($value) === $type)) {
            return $value;
        }

        throw new InvalidArgumentException("Session::start(): option '{$key}' must be {$type}, got ".get_debug_type($value).'.');
    }

    /**
     * @throws LogicException
     */
    private static function assertStarted(string $method): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException("Session::{$method}(): the session is not started; call Session::start() first.");
        }
    }

    /**
     * @throws LogicException
     */
    private static function assertHeadersNotSent(string $method): void
    {
        if (headers_sent($file, $line)) {
            throw new LogicException("Session::{$method}(): headers already sent (output started at {$file}:{$line}).");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertKey(string $method, string $key): void
    {
        if ($key === '' || $key === self::META) {
            throw new InvalidArgumentException("Session::{$method}(): the key '{$key}' is empty or reserved.");
        }
    }

    /**
     * Closures and resources cannot be serialized: they would be lost at request termination.
     *
     * @throws InvalidArgumentException
     */
    private static function assertStorable(string $method, string $key, mixed $value): void
    {
        if ($value instanceof Closure || is_resource($value) || get_debug_type($value) === 'resource (closed)') {
            throw new InvalidArgumentException("Session::{$method}(): the value for '{$key}' cannot be stored in the session (".get_debug_type($value).').');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertStorable($method, $key, $item);
            }
        }
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? null;
        $port = $_SERVER['SERVER_PORT'] ?? null;

        return (is_string($https) && $https !== '' && strtolower($https) !== 'off') || $port === '443' || $port === 443;
    }

    /**
     * Executes a session operation and captures any emitted warning (preventing dispatch to application handler).
     *
     * @param  Closure(): bool  $operation
     * @return array{bool, ?string}
     */
    private static function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= preg_replace('/^\w+\(\):\s*/', '', $message) ?? $message;

            return true;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }
}
