<?php

declare(strict_types=1);

namespace App\Utilities;

use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * Csrf Class
 *
 * Protection against Cross-Site Request Forgery: a session-scoped random token, embedded in forms
 * and validated on every state-changing request (POST, PUT, PATCH, DELETE).
 *
 * Rules:
 *   - the PHP session must be started beforehand (Session::start() or session_start());
 *   - the token contains 256 random bits and is compared in constant time;
 *   - check() accepts the token from the form field or from the X-CSRF-TOKEN header (AJAX);
 *     GET, HEAD, and OPTIONS requests are not checked (they must not modify data);
 *   - an invalid token throws an UnexpectedValueException with code 419 (in application: 419 / 403 response);
 *   - call regenerate() after authentication.
 *
 * Usage:
 *   require_once 'Csrf.php';
 *   use App\Utilities\Csrf;
 *
 *   <form method="post"><?= Csrf::field() ?> ... </form>
 *   <meta name="csrf-token" content="<?= Csrf::token() ?>">     // for fetch(): X-CSRF-TOKEN header
 *
 *   Csrf::check();                                              // at the start of request handling, before processing
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
final class Csrf
{
    /** @var string The session storage key. */
    private const string KEY = '__csrf';

    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private function __construct() {}

    /**
     * Returns the current session token (generated on first access), 64 hexadecimal characters.
     *
     * @throws LogicException If the session is not started.
     */
    public static function token(): string
    {
        self::assertSession('token');

        $token = $_SESSION[self::KEY] ?? null;
        if (! is_string($token) || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $token;
        }

        return $token;
    }

    /**
     * Generates a hidden form input: <input type="hidden" name="_token" value="...">.
     *
     * @throws InvalidArgumentException If the field name is invalid.
     * @throws LogicException If the session is not started.
     */
    public static function field(string $name = '_token'): string
    {
        self::assertFieldName('field', $name);

        return '<input type="hidden" name="'.$name.'" value="'.self::token().'">';
    }

    /**
     * Verifies an incoming token. Returns false for anything that is not exactly equal to the session token.
     *
     * @throws LogicException If the session is not started.
     */
    public static function verify(mixed $token): bool
    {
        self::assertSession('verify');

        $expected = $_SESSION[self::KEY] ?? null;

        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Validates the current request: for state-changing HTTP methods, the token in $field
     * (or the X-CSRF-TOKEN header) must match the session token.
     *
     * @param  array<array-key, mixed>|null  $post  Defaults to $_POST.
     * @param  array<array-key, mixed>|null  $server  Defaults to $_SERVER.
     *
     * @throws UnexpectedValueException With code 419 if the token is missing or invalid.
     * @throws InvalidArgumentException If the field name is invalid.
     * @throws LogicException If the session is not started.
     */
    public static function check(?array $post = null, ?array $server = null, string $field = '_token'): void
    {
        self::assertFieldName('check', $field);
        self::assertSession('check');

        $post ??= $_POST;
        $server ??= $_SERVER;

        $method = $server['REQUEST_METHOD'] ?? 'GET';
        if (is_string($method) && in_array(strtoupper($method), self::SAFE_METHODS, true)) {
            return;
        }

        $token = $post[$field] ?? $server['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($token === null || $token === '') {
            throw new UnexpectedValueException('Csrf::check(): the CSRF token is missing.', 419);
        }

        if (! self::verify($token)) {
            throw new UnexpectedValueException('Csrf::check(): the CSRF token is invalid or expired.', 419);
        }
    }

    /**
     * Generates a new token (after authentication or privilege changes); previous forms become invalid.
     *
     * @throws LogicException If the session is not started.
     */
    public static function regenerate(): string
    {
        self::assertSession('regenerate');

        unset($_SESSION[self::KEY]);

        return self::token();
    }

    /**
     * @throws LogicException
     */
    private static function assertSession(string $method): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new LogicException("Csrf::{$method}(): the session is not started; start it before using CSRF tokens.");
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertFieldName(string $method, string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Csrf::{$method}(): invalid field name '{$name}'.");
        }
    }
}
