<?php

declare(strict_types=1);

namespace App\Utilities;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use OutOfBoundsException;
use UnexpectedValueException;

/**
 * Arr Class
 *
 * Array operations with strict comparisons and explicit errors.
 *
 * Pitfalls of native functions avoided:
 *   - array_column() silently skips rows missing the requested key; pluck() throws an exception;
 *   - array_column() with an index silently overwrites duplicates; keyBy() throws an exception;
 *   - in_array() / array_search() perform loose comparisons by default ('abc' == 0 in PHP 7); here everything is strict;
 *   - array_search() returns 0 or false, easily confused; search() throws an exception if missing;
 *   - array_find(), array_any(), array_all() only exist starting from PHP 8.4.
 *
 * Dot notation: 'user.address.city' reads $array['user']['address']['city'].
 * A literal existing key ('user.name' => ...) takes precedence over dot notation.
 * For object elements, only public properties are read.
 *
 * The field used by pluck/keyBy/groupBy/sortBy/where/sum is a key (dot notation supported)
 * or a Closure. A string is never interpreted as a function name ('count' is a key).
 *
 * Callbacks receive ($value, $key) and must return bool (otherwise an exception is thrown).
 *
 * Error messages include the requested keys and item position, never raw values from the array.
 *
 * Usage:
 *   require_once 'Arr.php';
 *   use App\Utilities\Arr;
 *
 *   $city = Arr::get($data, 'user.address.city');          // throws an exception if missing
 *   $city = Arr::get($data, 'user.address.city', '');      // default value
 *   $input = Arr::require($_POST, ['name', 'email']);      // all missing keys reported at once
 *   $emails = Arr::pluck($users, 'email');
 *   $byId = Arr::keyBy($users, 'id');
 *   $admin = Arr::first($users, fn (array $user): bool => $user['role'] === 'admin');
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
final class Arr
{
    private function __construct() {}

    /**
     * Gets the value of a key (with dot notation support).
     * With two arguments the key is required; with a third argument, it acts as the default value.
     *
     * @param  array<array-key, mixed>  $array
     *
     * @throws OutOfBoundsException If the key is missing and no default value is provided.
     * @throws InvalidArgumentException If the key contains an empty segment ('a..b').
     */
    public static function get(array $array, string|int $key, mixed $default = null): mixed
    {
        [$found, $value] = self::lookup('get', $array, $key);

        if (! $found) {
            return func_num_args() > 2 ? $default : throw self::missingKey('get', $key);
        }

        return $value;
    }

    /**
     * Checks whether a key exists (a null value counts as existing).
     *
     * @param  array<array-key, mixed>  $array
     *
     * @throws InvalidArgumentException If the key contains an empty segment.
     */
    public static function has(array $array, string|int $key): bool
    {
        return self::lookup('has', $array, $key)[0];
    }

    /**
     * Returns the values of the requested keys, all of which are required. All missing keys are reported at once.
     *
     * @param  array<array-key, mixed>  $array
     * @param  list<string|int>  $keys
     * @return array<array-key, mixed> Requested key => value, in the order specified in $keys.
     *
     * @throws OutOfBoundsException If at least one key is missing.
     * @throws InvalidArgumentException If a key contains an empty segment.
     */
    public static function require(array $array, array $keys): array
    {
        $values = [];
        $missing = [];

        foreach ($keys as $key) {
            [$found, $value] = self::lookup('require', $array, $key);
            if ($found) {
                $values[$key] = $value;
            } else {
                $missing[] = "'{$key}'";
            }
        }

        if ($missing !== []) {
            throw new OutOfBoundsException('Arr::require(): missing keys: '.implode(', ', $missing).'.');
        }

        return $values;
    }

    /**
     * Returns a copy of the array with the value set (using dot notation); missing levels are created.
     *
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If an intermediate level exists and is not an array.
     * @throws InvalidArgumentException If the key contains an empty segment.
     */
    public static function set(array $array, string|int $key, mixed $value): array
    {
        $segments = self::path('set', $array, $key);
        $last = array_pop($segments);

        $current = &$array;
        $walked = [];
        foreach ($segments as $segment) {
            $walked[] = $segment;
            if (! array_key_exists($segment, $current)) {
                $current[$segment] = [];
            } elseif (! is_array($current[$segment])) {
                throw new UnexpectedValueException(
                    "Arr::set(): cannot set '{$key}': '".implode('.', $walked)."' is ".get_debug_type($current[$segment]).', not an array.',
                );
            }
            $current = &$current[$segment];
        }

        $current[$last] = $value;
        unset($current);

        return $array;
    }

    /**
     * Returns a copy of the array without the given key (using dot notation). A missing key is not an error.
     *
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If the key contains an empty segment.
     */
    public static function forget(array $array, string|int $key): array
    {
        return self::remove($array, self::path('forget', $array, $key));
    }

    /**
     * Returns only the specified keys (top-level only, without dot notation); missing keys are ignored.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  array<TKey, TValue>  $array
     * @param  list<array-key>  $keys
     * @return array<TKey, TValue>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Returns all keys except the specified ones (top-level only, without dot notation).
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param  array<TKey, TValue>  $array
     * @param  list<array-key>  $keys
     * @return array<TKey, TValue>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Returns the values of a field from each item; with $key, the result is keyed by another field.
     *
     *   Arr::pluck($users, 'email')            -> ['a@x.ro', 'b@x.ro']
     *   Arr::pluck($users, 'email', 'id')      -> [10 => 'a@x.ro', 11 => 'b@x.ro']
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $items
     * @param  string|int|Closure(TValue, array-key): mixed  $value
     * @param  string|int|Closure(TValue, array-key): mixed|null  $key
     * @return array<array-key, mixed>
     *
     * @throws OutOfBoundsException If an item does not have the requested field.
     * @throws UnexpectedValueException If the index is not an int/string or is duplicated.
     */
    public static function pluck(array $items, string|int|Closure $value, string|int|Closure|null $key = null): array
    {
        $result = [];
        $position = 0;
        foreach ($items as $index => $item) {
            $fieldValue = self::field('pluck', $item, $index, $value, $position);
            if ($key === null) {
                $result[] = $fieldValue;
            } else {
                $result[self::uniqueKey('pluck', $result, $item, $index, $key, $position)] = $fieldValue;
            }
            $position++;
        }

        return $result;
    }

    /**
     * Returns the items keyed by a field. A duplicate index is an error (preventing accidental item loss).
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $items
     * @param  string|int|Closure(TValue, array-key): mixed  $key
     * @return array<array-key, TValue>
     *
     * @throws OutOfBoundsException If an item does not have the requested field.
     * @throws UnexpectedValueException If the index is not an int/string or is duplicated.
     */
    public static function keyBy(array $items, string|int|Closure $key): array
    {
        $result = [];
        $position = 0;
        foreach ($items as $index => $item) {
            $result[self::uniqueKey('keyBy', $result, $item, $index, $key, $position)] = $item;
            $position++;
        }

        return $result;
    }

    /**
     * Groups items by a field: [field value => list of items].
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $items
     * @param  string|int|Closure(TValue, array-key): mixed  $key
     * @return array<array-key, list<TValue>>
     *
     * @throws OutOfBoundsException If an item does not have the requested field.
     * @throws UnexpectedValueException If the grouping value is not an int/string.
     */
    public static function groupBy(array $items, string|int|Closure $key): array
    {
        $groups = [];
        $position = 0;
        foreach ($items as $index => $item) {
            $group = self::groupKey('groupBy', self::field('groupBy', $item, $index, $key, $position), $position);
            $groups[$group][] = $item;
            $position++;
        }

        return $groups;
    }

    /**
     * Returns the first element (matching the callback, if provided).
     * With $default, it is returned instead of throwing an exception.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $array
     * @param  (Closure(TValue, array-key): bool)|null  $callback
     * @return TValue|mixed
     *
     * @throws OutOfBoundsException If no matching element is found and no $default is provided.
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    public static function first(array $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        foreach ($array as $key => $value) {
            if (! $callback instanceof Closure || self::test('first', $callback, $value, $key)) {
                return $value;
            }
        }

        return func_num_args() > 2 ? $default : throw self::noElement('first', $array);
    }

    /**
     * Returns the last element (matching the callback, if provided).
     * With $default, it is returned instead of throwing an exception.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $array
     * @param  (Closure(TValue, array-key): bool)|null  $callback
     * @return TValue|mixed
     *
     * @throws OutOfBoundsException If no matching element is found and no $default is provided.
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    public static function last(array $array, ?Closure $callback = null, mixed $default = null): mixed
    {
        foreach (array_reverse($array, true) as $key => $value) {
            if (! $callback instanceof Closure || self::test('last', $callback, $value, $key)) {
                return $value;
            }
        }

        return func_num_args() > 2 ? $default : throw self::noElement('last', $array);
    }

    /**
     * Returns the first element that satisfies the callback, or null (like array_find() in PHP 8.4).
     * When null can be a valid element value, use first().
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $array
     * @param  Closure(TValue, array-key): bool  $callback
     * @return TValue|null
     *
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    public static function find(array $array, Closure $callback): mixed
    {
        foreach ($array as $key => $value) {
            if (self::test('find', $callback, $value, $key)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns the key of the first element that satisfies the callback, or null (like array_find_key() in PHP 8.4).
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $array
     * @param  Closure(TValue, array-key): bool  $callback
     *
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    public static function findKey(array $array, Closure $callback): string|int|null
    {
        foreach ($array as $key => $value) {
            if (self::test('findKey', $callback, $value, $key)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Checks whether at least one element satisfies the callback (like array_any() in PHP 8.4). Empty array -> false.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $array
     * @param  Closure(TValue, array-key): bool  $callback
     *
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    public static function any(array $array, Closure $callback): bool
    {
        foreach ($array as $key => $value) {
            if (self::test('any', $callback, $value, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether all elements satisfy the callback (like array_all() in PHP 8.4). Empty array -> true.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $array
     * @param  Closure(TValue, array-key): bool  $callback
     *
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    public static function all(array $array, Closure $callback): bool
    {
        foreach ($array as $key => $value) {
            if (! self::test('all', $callback, $value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks strictly (===) whether the value exists in the array.
     *
     * @param  array<array-key, mixed>  $array
     */
    public static function contains(array $array, mixed $value): bool
    {
        return in_array($value, $array, true);
    }

    /**
     * Returns the key of the first occurrence of the value (strict comparison).
     *
     * @param  array<array-key, mixed>  $array
     *
     * @throws OutOfBoundsException If the value does not exist in the array.
     */
    public static function search(array $array, mixed $value): string|int
    {
        $key = array_search($value, $array, true);

        return $key === false ? throw new OutOfBoundsException('Arr::search(): value not found.') : $key;
    }

    /**
     * Flattens nested arrays into a single list up to the specified depth. Keys are not preserved.
     *
     * @param  array<array-key, mixed>  $array
     * @return list<mixed>
     *
     * @throws InvalidArgumentException If $depth is less than 1.
     */
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        if ($depth < 1) {
            throw new InvalidArgumentException("Arr::flatten(): depth must be >= 1, got {$depth}.");
        }

        $result = [];
        foreach ($array as $value) {
            if (! is_array($value)) {
                $result[] = $value;
            } elseif ($depth === 1) {
                array_push($result, ...array_values($value));
            } else {
                array_push($result, ...self::flatten($value, $depth - 1));
            }
        }

        return $result;
    }

    /**
     * Wraps a value into an array: null -> [], array -> unchanged, anything else -> [value].
     *
     * @return array<array-key, mixed>
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Returns the items sorted by a field (stable sort). A list remains a list; for associative
     * arrays, keys are preserved. Values must be of the same kind: numbers, strings,
     * bool, or dates (DateTimeInterface); a mixed set (e.g. string and int, null) is an error.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $items
     * @param  string|int|Closure(TValue, array-key): mixed  $key
     * @return array<array-key, TValue>
     *
     * @throws OutOfBoundsException If an item does not have the requested field.
     * @throws UnexpectedValueException If the values cannot be compared with each other.
     */
    public static function sortBy(array $items, string|int|Closure $key, bool $descending = false): array
    {
        $values = [];
        $kind = null;
        $position = 0;
        foreach ($items as $index => $item) {
            $value = self::field('sortBy', $item, $index, $key, $position);
            $itemKind = self::sortKind($value, $position);
            if ($kind !== null && $itemKind !== $kind) {
                throw new UnexpectedValueException(
                    "Arr::sortBy(): cannot compare {$itemKind} (item #{$position}) with {$kind} (earlier items).",
                );
            }
            $kind = $itemKind;
            $values[$index] = $value;
            $position++;
        }

        $isList = array_is_list($items);
        uksort($items, static fn (int|string $a, int|string $b): int => $descending
            ? $values[$b] <=> $values[$a]
            : $values[$a] <=> $values[$b]);

        return $isList ? array_values($items) : $items;
    }

    /**
     * Returns the items whose field is strictly equal (===) to the given value.
     * A list remains a list; for associative arrays, keys are preserved.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $items
     * @param  string|int|Closure(TValue, array-key): mixed  $key
     * @return array<array-key, TValue>
     *
     * @throws OutOfBoundsException If an item does not have the requested field.
     */
    public static function where(array $items, string|int|Closure $key, mixed $value): array
    {
        $result = [];
        $position = 0;
        foreach ($items as $index => $item) {
            if (self::field('where', $item, $index, $key, $position++) === $value) {
                $result[$index] = $item;
            }
        }

        return array_is_list($items) ? array_values($result) : $result;
    }

    /**
     * Returns the sum of values (or of a specific field). Values must be int or float:
     * a numeric string (e.g. from a database) is an error and must be cast explicitly.
     * For monetary amounts, use Money rather than float.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $items
     * @param  string|int|Closure(TValue, array-key): mixed|null  $key
     *
     * @throws OutOfBoundsException If an item does not have the requested field.
     * @throws UnexpectedValueException If a value is not an int or float.
     */
    public static function sum(array $items, string|int|Closure|null $key = null): int|float
    {
        $sum = 0;
        $position = 0;
        foreach ($items as $index => $item) {
            $value = $key === null ? $item : self::field('sum', $item, $index, $key, $position);
            if (! is_int($value) && ! is_float($value)) {
                throw new UnexpectedValueException('Arr::sum(): item #'.$position.' must be int or float, got '.get_debug_type($value).'.');
            }
            $sum += $value;
            $position++;
        }

        return $sum;
    }

    /**
     * Checks whether the array has associative keys (is not a sequential list 0, 1, 2...). Empty array -> false.
     *
     * @param  array<array-key, mixed>  $array
     */
    public static function isAssoc(array $array): bool
    {
        return ! array_is_list($array);
    }

    /**
     * Looks up a key (with dot notation support) in arrays and public object properties.
     *
     * @return array{0: bool, 1: mixed} [found, value]
     *
     * @throws InvalidArgumentException If the key contains an empty segment.
     */
    private static function lookup(string $method, mixed $target, string|int $key): array
    {
        foreach (self::path($method, $target, $key) as $segment) {
            if (is_object($target)) {
                $target = get_object_vars($target);
            }
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return [false, null];
            }
            $target = $target[$segment];
        }

        return [true, $target];
    }

    /**
     * Returns key segments. An integer key or a key that exists literally is treated as a single segment.
     *
     * @return non-empty-list<string|int>
     *
     * @throws InvalidArgumentException If the key contains an empty segment.
     */
    private static function path(string $method, mixed $target, string|int $key): array
    {
        $direct = is_object($target) ? get_object_vars($target) : $target;

        if (is_int($key) || (is_array($direct) && array_key_exists($key, $direct))) {
            return [$key];
        }

        $segments = explode('.', $key);
        if (in_array('', $segments, true)) {
            throw new InvalidArgumentException("Arr::{$method}(): invalid key '{$key}' (empty segment).");
        }

        return $segments;
    }

    /**
     * Returns a copy of the array without the specified path; a nonexistent path (or one passing through a non-array) leaves it unchanged.
     *
     * @param  array<array-key, mixed>  $array
     * @param  list<string|int>  $segments
     * @return array<array-key, mixed>
     */
    private static function remove(array $array, array $segments): array
    {
        $segment = array_shift($segments);

        if ($segment === null || ! array_key_exists($segment, $array)) {
            return $array;
        }

        if ($segments === []) {
            unset($array[$segment]);
        } elseif (is_array($array[$segment])) {
            $array[$segment] = self::remove($array[$segment], $segments);
        }

        return $array;
    }

    /**
     * Returns the field value of an item: from a Closure or a key (with dot notation support).
     *
     * @template TItem
     *
     * @param  TItem  $item
     * @param  string|int|Closure(TItem, array-key): mixed  $field
     *
     * @throws OutOfBoundsException If the item does not have the requested field.
     */
    private static function field(string $method, mixed $item, int|string $index, string|int|Closure $field, int $position): mixed
    {
        if ($field instanceof Closure) {
            return $field($item, $index);
        }

        [$found, $value] = self::lookup($method, $item, $field);

        return $found ? $value : throw new OutOfBoundsException(
            "Arr::{$method}(): item #{$position} (".get_debug_type($item).") has no key '{$field}'.",
        );
    }

    /**
     * Resolves the result key for an item (the value of field $key), verifying it is not already used.
     *
     * @template TItem
     *
     * @param  array<array-key, mixed>  $result
     * @param  TItem  $item
     * @param  string|int|Closure(TItem, array-key): mixed  $key
     *
     * @throws OutOfBoundsException If the item does not have the requested field.
     * @throws UnexpectedValueException If the key is not an int/string or is duplicated.
     */
    private static function uniqueKey(string $method, array $result, mixed $item, int|string $index, string|int|Closure $key, int $position): int|string
    {
        $newKey = self::groupKey($method, self::field($method, $item, $index, $key, $position), $position);

        if (array_key_exists($newKey, $result)) {
            throw new UnexpectedValueException("Arr::{$method}(): item #{$position} has a duplicate key.");
        }

        return $newKey;
    }

    /**
     * @throws UnexpectedValueException If the value cannot be an array key.
     */
    private static function groupKey(string $method, mixed $value, int $position): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        throw new UnexpectedValueException("Arr::{$method}(): the key of item #{$position} must be int or string, got ".get_debug_type($value).'.');
    }

    /**
     * Determines the value type for sortBy(): only values of the same kind can be compared.
     *
     * @throws UnexpectedValueException If the value cannot be sorted.
     */
    private static function sortKind(mixed $value, int $position): string
    {
        return match (true) {
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_bool($value) => 'bool',
            $value instanceof DateTimeInterface => 'date',
            default => throw new UnexpectedValueException(
                "Arr::sortBy(): item #{$position} cannot be sorted (".get_debug_type($value).').',
            ),
        };
    }

    /**
     * Executes the callback and verifies that it returns a boolean.
     *
     * @throws UnexpectedValueException If the callback does not return a bool.
     */
    private static function test(string $method, Closure $callback, mixed $value, int|string $key): bool
    {
        $result = $callback($value, $key);

        if (! is_bool($result)) {
            throw new UnexpectedValueException("Arr::{$method}(): callback must return bool, got ".get_debug_type($result).'.');
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private static function noElement(string $method, array $array): OutOfBoundsException
    {
        $reason = $array === [] ? 'array is empty' : 'no element matches the callback';

        return new OutOfBoundsException("Arr::{$method}(): {$reason}.");
    }

    private static function missingKey(string $method, string|int $key): OutOfBoundsException
    {
        return new OutOfBoundsException("Arr::{$method}(): key '{$key}' does not exist.");
    }
}
