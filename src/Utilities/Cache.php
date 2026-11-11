<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Cache Class
 *
 * File-based cache with expiration. Each key is stored in a separate JSON file (filename = key hash).
 *
 * Values are stored as JSON rather than using serialize(): a tampered cache file cannot
 * instantiate arbitrary objects. Only JSON-serializable values (scalars, null, arrays) can be cached;
 * anything else throws an exception on set() rather than returning an unexpected value on get().
 *
 * Writes are atomic (temporary file + rename): readers never see partially written files.
 * Corrupted cache files (modified externally) throw an exception instead of silently resulting in a cache miss.
 *
 * Usage:
 *   use App\Utilities\Cache;
 *
 *   $cache = new Cache(__DIR__.'/storage/cache');
 *
 *   $rates = $cache->remember('bnr-rates', 3600, fn () => HttpClient::json('GET', $bnrUrl));
 *   $cache->set('settings', ['theme' => 'dark'], ttl: null);   // no expiration
 *   $value = $cache->get('settings', default: []);
 *   $cache->forget('settings');
 *   $cache->prune();                                           // removes expired files (e.g. from cron)
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
final readonly class Cache
{
    /** @var string Cache file extension. */
    private const string EXTENSION = '.cache.json';

    private string $directory;

    /**
     * @param  string  $directory  Cache directory; must exist and be writable.
     *                             Use a dedicated directory: clear() deletes all cache files inside it.
     *
     * @throws RuntimeException If the directory does not exist or is not writable.
     */
    public function __construct(string $directory)
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Cache: directory '{$directory}' does not exist or is not writable.");
        }

        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * Returns the cached value or $default if it does not exist or has expired.
     *
     * @phpstan-impure
     *
     * @throws InvalidArgumentException If the key is empty.
     * @throws UnexpectedValueException If the cache file is corrupted.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        [$found, $value] = $this->read($key);

        return $found ? $value : $default;
    }

    /**
     * Checks whether the key exists and has not expired (a stored null value counts as existing).
     *
     * @phpstan-impure
     *
     * @throws UnexpectedValueException If the cache file is corrupted.
     */
    public function has(string $key): bool
    {
        return $this->read($key)[0];
    }

    /**
     * Stores a value in the cache.
     *
     * @param  string  $key  The key (any non-empty string).
     * @param  mixed  $value  JSON-serializable value: scalar, null, or array (no objects, resources, NAN/INF).
     * @param  int|null  $ttl  Time to live in seconds; null = no expiration.
     *
     * @throws InvalidArgumentException If the key is empty, $ttl < 1, or the value cannot be stored.
     * @throws RuntimeException If the file cannot be written.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException('Cache::set(): $ttl must be at least 1 second or null (no expiration).');
        }

        self::assertStorable($value, 'value');

        try {
            $json = json_encode(
                ['key' => $key, 'expires' => $ttl === null ? null : time() + $ttl, 'value' => $value],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Cache::set(): the value cannot be stored: '.$e->getMessage().'.', 0, $e);
        }

        $this->writeAtomically($this->path($key), $json);
    }

    /**
     * Gets a value from the cache; if missing, computes it via $callback, caches, and returns it.
     *
     * @param  Closure(): mixed  $callback  Computes the value (called only on a cache miss).
     * @param  int|null  $ttl  Time to live in seconds; null = no expiration.
     * @return mixed Cached value (decoded JSON) or the computed value.
     *
     * @phpstan-impure
     *
     * @throws InvalidArgumentException If the computed value cannot be stored.
     */
    public function remember(string $key, ?int $ttl, Closure $callback): mixed
    {
        [$found, $value] = $this->read($key);

        if ($found) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Deletes a key (does not throw if it does not exist).
     *
     * @return bool True if the key existed and was deleted.
     *
     * @throws RuntimeException If the file exists but cannot be deleted.
     */
    public function forget(string $key): bool
    {
        $path = $this->path($key);

        if (! is_file($path)) {
            return false;
        }

        $this->delete($path);

        return true;
    }

    /**
     * Deletes all cache files in the directory.
     *
     * @return int Number of deleted files.
     *
     * @throws RuntimeException If a file cannot be deleted.
     */
    public function clear(): int
    {
        $count = 0;
        foreach ($this->files() as $file) {
            $this->delete($file);
            $count++;
        }

        return $count;
    }

    /**
     * Deletes expired cache files (suitable for a cron job). Corrupted files are also deleted.
     *
     * @return int Number of deleted files.
     */
    public function prune(): int
    {
        $count = 0;
        $now = time();

        foreach ($this->files() as $file) {
            $entry = json_decode((string) @file_get_contents($file), true);
            $expires = is_array($entry) ? ($entry['expires'] ?? null) : false;

            if (! is_array($entry) || (is_int($expires) && $expires <= $now)) {
                $this->delete($file);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{0: bool, 1: mixed} [found, value]
     *
     * @throws InvalidArgumentException If the key is empty.
     * @throws UnexpectedValueException If the cache file is corrupted.
     */
    private function read(string $key): array
    {
        $path = $this->path($key);

        if (! is_file($path)) {
            return [false, null];
        }

        [$content] = $this->capture(static fn (): string|false => file_get_contents($path));

        if ($content === false) {
            // Sters intre is_file() si citire (alt proces): este un "miss" normal.
            return [false, null];
        }

        $entry = json_decode($content, true);

        if (! is_array($entry) || ! array_key_exists('value', $entry) || ! array_key_exists('expires', $entry)
            || ($entry['key'] ?? null) !== $key || ! is_int($entry['expires']) && $entry['expires'] !== null) {
            throw new UnexpectedValueException("Cache: corrupted cache file '{$path}'.");
        }

        if ($entry['expires'] !== null && $entry['expires'] <= time()) {
            $this->delete($path);

            return [false, null];
        }

        return [true, $entry['value']];
    }

    /**
     * @throws InvalidArgumentException If the key is empty.
     */
    private function path(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache: the key cannot be empty.');
        }

        return $this->directory.'/'.hash('sha256', $key).self::EXTENSION;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), self::EXTENSION)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Recursively verifies that the value contains only JSON types.
     *
     * @throws InvalidArgumentException On objects, resources, or infinite numbers.
     */
    private static function assertStorable(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::assertStorable($item, $path.'['.$key.']');
            }

            return;
        }

        if (is_object($value) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new InvalidArgumentException("Cache::set(): {$path} is ".get_debug_type($value).'; only scalars, null and arrays can be cached.');
        }
    }

    /**
     * @throws RuntimeException If writing fails.
     */
    private function writeAtomically(string $path, string $content): void
    {
        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            [$written, $warning] = $this->capture(static fn (): int|false => file_put_contents($temp, $content));
            if ($written !== strlen($content)) {
                throw new RuntimeException("Cache: cannot write '{$path}'".($warning === null ? '.' : ": {$warning}."));
            }

            [$renamed, $warning] = $this->capture(static fn (): bool => rename($temp, $path));
            if (! $renamed) {
                throw new RuntimeException("Cache: cannot write '{$path}'".($warning === null ? '.' : ": {$warning}."));
            }
        } finally {
            if (is_file($temp)) {
                $this->capture(static fn (): bool => unlink($temp));
            }
        }
    }

    /**
     * Deletes a file; if it was already deleted by another process, it is not an error.
     *
     * @throws RuntimeException If the file exists but cannot be deleted.
     */
    private function delete(string $path): void
    {
        [$deleted, $warning] = $this->capture(static fn (): bool => unlink($path));

        if (! $deleted && is_file($path)) {
            throw new RuntimeException("Cache: cannot delete '{$path}'".($warning === null ? '.' : ": {$warning}."));
        }
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
