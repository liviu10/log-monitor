<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * Per-account login throttling (brute-force / credential-stuffing protection).
 *
 * Counts failed attempts per normalised e-mail (hashed, so no address is stored)
 * in a small locked file. After MAX_ATTEMPTS failures inside WINDOW_SECONDS the
 * account is locked for WINDOW_SECONDS. Works the same for unknown addresses, so
 * it does not reveal which accounts exist. Complements the per-IP rate limit.
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.5
 *
 * @license  Proprietary
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const WINDOW_SECONDS = 900;

    /** @var string|null Directory override (tests). */
    public static ?string $directory = null;

    /**
     * True when the account is currently locked.
     */
    public static function tooManyAttempts(string $email): bool
    {
        $state = self::read($email);

        return $state['count'] >= self::MAX_ATTEMPTS && (time() - $state['first']) < self::WINDOW_SECONDS;
    }

    /**
     * Records one failed attempt.
     */
    public static function hit(string $email): void
    {
        self::mutate($email, static function (array $state): array {
            if ((time() - $state['first']) >= self::WINDOW_SECONDS) {
                return ['count' => 1, 'first' => time()];
            }

            return ['count' => $state['count'] + 1, 'first' => $state['first']];
        });
    }

    /**
     * Clears the counter after a successful login.
     */
    public static function clear(string $email): void
    {
        $file = self::file($email);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * @return array{count: int, first: int}
     */
    private static function read(string $email): array
    {
        $file = self::file($email);
        $raw = is_file($file) ? @file_get_contents($file) : false;

        return self::decode(is_string($raw) ? $raw : '');
    }

    /**
     * @param  callable(array{count: int, first: int}): array{count: int, first: int}  $fn
     */
    private static function mutate(string $email, callable $fn): void
    {
        $file = self::file($email);
        $dir = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            flock($fp, LOCK_EX);
            $raw = stream_get_contents($fp);
            $state = $fn(self::decode(is_string($raw) ? $raw : ''));
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($state));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @return array{count: int, first: int}
     */
    private static function decode(string $raw): array
    {
        $data = json_decode($raw, true);
        $count = is_array($data) && is_int($data['count'] ?? null) ? $data['count'] : 0;
        $first = is_array($data) && is_int($data['first'] ?? null) ? $data['first'] : 0;

        return ['count' => $count, 'first' => $first];
    }

    private static function file(string $email): string
    {
        $dir = self::$directory ?? dirname(__DIR__, 2).'/storage/framework/cache';

        return $dir.'/login_'.hash('sha256', strtolower(trim($email))).'.json';
    }
}
