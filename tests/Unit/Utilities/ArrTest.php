<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Arr;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

final class ArrTest extends TestCase
{
    /** @var array<array-key, mixed> */
    private const array DATA = [
        'user' => [
            'name' => 'Ion',
            'address' => ['city' => 'Iasi', 'zip' => null],
            'roles' => ['admin', 'editor'],
        ],
        'app.name' => 'Portal',
        0 => 'zero',
    ];

    /**
     * @return list<array{id: int, name: string, dept: string, salary: int}>
     */
    private function users(): array
    {
        return [
            ['id' => 10, 'name' => 'Ion', 'dept' => 'IT', 'salary' => 5000],
            ['id' => 11, 'name' => 'Ana', 'dept' => 'HR', 'salary' => 4000],
            ['id' => 12, 'name' => 'Dan', 'dept' => 'IT', 'salary' => 6000],
        ];
    }

    /**
     * Callback fara tipuri declarate, cum apare in codul vechi; verificarea bool se face la executie.
     */
    private function untypedCallback(Closure $callback): Closure
    {
        return $callback;
    }

    // get / has / require

    public function test_get_with_dot_notation(): void
    {
        $this->assertSame('Ion', Arr::get(self::DATA, 'user.name'));
        $this->assertSame('Iasi', Arr::get(self::DATA, 'user.address.city'));
        $this->assertSame('editor', Arr::get(self::DATA, 'user.roles.1'));
        $this->assertSame('zero', Arr::get(self::DATA, 0));
        $this->assertSame('zero', Arr::get(self::DATA, '0'));
    }

    public function test_get_literal_key_with_dot_has_priority(): void
    {
        $this->assertSame('Portal', Arr::get(self::DATA, 'app.name'));
    }

    public function test_get_null_value_is_found(): void
    {
        $this->assertNull(Arr::get(self::DATA, 'user.address.zip'));
        $this->assertTrue(Arr::has(self::DATA, 'user.address.zip'));
    }

    public function test_get_missing_key_throws_without_default(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage("Arr::get(): key 'user.address.street' does not exist.");

        Arr::get(self::DATA, 'user.address.street');
    }

    public function test_get_missing_key_with_default(): void
    {
        $this->assertSame('', Arr::get(self::DATA, 'user.address.street', ''));
        $this->assertNull(Arr::get(self::DATA, 'user.name.first', null), 'traversare prin string');
        $this->assertSame(1, Arr::get([], 5, 1));
    }

    public function test_get_reads_public_object_properties(): void
    {
        $user = new stdClass;
        $user->address = (object) ['city' => 'Cluj'];

        $this->assertSame('Cluj', Arr::get(['user' => $user], 'user.address.city'));
        $this->assertFalse(Arr::has(['user' => $user], 'user.phone'));
    }

    public function test_get_does_not_read_private_properties(): void
    {
        $object = new class
        {
            public string $visible = 'y';

            private string $secret = 'x';

            public function secret(): string
            {
                return $this->secret;
            }
        };

        $this->assertSame('y', Arr::get(['o' => $object], 'o.visible'));
        $this->assertFalse(Arr::has(['o' => $object], 'o.secret'));
    }

    public function test_empty_segment_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Arr::get(): invalid key 'user..name' (empty segment).");

        Arr::get(self::DATA, 'user..name');
    }

    public function test_has(): void
    {
        $this->assertTrue(Arr::has(self::DATA, 'user'));
        $this->assertTrue(Arr::has(self::DATA, 'user.roles.0'));
        $this->assertFalse(Arr::has(self::DATA, 'user.roles.5'));
        $this->assertFalse(Arr::has(self::DATA, 'missing'));
        $this->assertFalse(Arr::has([], 0));
    }

    public function test_require_returns_values(): void
    {
        $this->assertSame(
            ['user.name' => 'Ion', 'app.name' => 'Portal', 0 => 'zero'],
            Arr::require(self::DATA, ['user.name', 'app.name', 0]),
        );
    }

    public function test_require_reports_all_missing_keys(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage("Arr::require(): missing keys: 'email', 'user.phone'.");

        Arr::require(self::DATA, ['email', 'user.name', 'user.phone']);
    }

    // set / forget

    public function test_set_creates_missing_levels_and_does_not_modify_original(): void
    {
        $original = ['a' => ['b' => 1]];

        $result = Arr::set($original, 'a.c.d', 2);

        $this->assertSame(['a' => ['b' => 1, 'c' => ['d' => 2]]], $result);
        $this->assertSame(['a' => ['b' => 1]], $original);
    }

    public function test_set_overwrites_and_handles_literal_and_int_keys(): void
    {
        $this->assertSame(['a' => 2], Arr::set(['a' => 1], 'a', 2));
        $this->assertSame(['a.b' => 2], Arr::set(['a.b' => 1], 'a.b', 2), 'cheia literala exista');
        $this->assertSame(['x', 'y'], Arr::set(['x'], 1, 'y'));
        $this->assertSame(['list' => ['x', 'z']], Arr::set(['list' => ['x', 'y']], 'list.1', 'z'));
    }

    public function test_set_through_non_array_throws(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Arr::set(): cannot set 'user.name.first': 'user.name' is string, not an array.");

        Arr::set(self::DATA, 'user.name.first', 'Ion');
    }

    public function test_forget(): void
    {
        $result = Arr::forget(self::DATA, 'user.address.city');

        $this->assertFalse(Arr::has($result, 'user.address.city'));
        $this->assertTrue(Arr::has($result, 'user.address.zip'));
        $this->assertTrue(Arr::has(self::DATA, 'user.address.city'), 'originalul neschimbat');
        $this->assertSame(self::DATA, Arr::forget(self::DATA, 'user.missing.key'), 'cheie lipsa = fara efect');
        $this->assertSame(self::DATA, Arr::forget(self::DATA, 'user.name.first'), 'prin string = fara efect');
        $this->assertSame(['b' => 2], Arr::forget(['a.x' => 1, 'b' => 2], 'a.x'));
    }

    // only / except

    public function test_only_and_except(): void
    {
        $input = ['name' => 'Ion', 'email' => 'i@x.ro', 'is_admin' => true];

        $this->assertSame(['name' => 'Ion', 'email' => 'i@x.ro'], Arr::only($input, ['email', 'name', 'missing']));
        $this->assertSame(['name' => 'Ion'], Arr::except($input, ['email', 'is_admin', 'missing']));
    }

    // pluck / keyBy / groupBy

    public function test_pluck(): void
    {
        $this->assertSame(['Ion', 'Ana', 'Dan'], Arr::pluck($this->users(), 'name'));
        $this->assertSame([10 => 'Ion', 11 => 'Ana', 12 => 'Dan'], Arr::pluck($this->users(), 'name', 'id'));
        $this->assertSame(['ION', 'ANA', 'DAN'], Arr::pluck($this->users(), static fn (array $user): string => strtoupper($user['name'])));
        $this->assertSame(['Iasi'], Arr::pluck([self::DATA], 'user.address.city'));
        $this->assertSame([], Arr::pluck([], 'name'));
    }

    public function test_pluck_from_objects(): void
    {
        $items = [(object) ['id' => 1, 'name' => 'Ion'], (object) ['id' => 2, 'name' => 'Ana']];

        $this->assertSame([1 => 'Ion', 2 => 'Ana'], Arr::pluck($items, 'name', 'id'));
    }

    public function test_pluck_missing_field_throws_instead_of_skipping(): void
    {
        $items = [['email' => 'a@x.ro'], ['name' => 'fara email']];

        $this->assertSame(['a@x.ro'], array_column($items, 'email'), 'premisa: nativ, randul se pierde in tacere');

        try {
            Arr::pluck($items, 'email');
            $this->fail('Expected OutOfBoundsException');
        } catch (OutOfBoundsException $e) {
            $this->assertSame("Arr::pluck(): item #1 (array) has no key 'email'.", $e->getMessage());
            $this->assertStringNotContainsString('fara email', $e->getMessage());
        }
    }

    public function test_key_by(): void
    {
        $byId = Arr::keyBy($this->users(), 'id');

        $this->assertSame([10, 11, 12], array_keys($byId));
        $this->assertSame($this->users()[1], $byId[11]);
        $this->assertSame(['ion', 'ana', 'dan'], array_keys(Arr::keyBy($this->users(), static fn (array $user): string => strtolower($user['name']))));
    }

    public function test_key_by_duplicate_throws(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Arr::keyBy(): item #2 has a duplicate key.');

        Arr::keyBy($this->users(), 'dept');
    }

    public function test_key_by_rejects_non_scalar_key(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Arr::keyBy(): the key of item #0 must be int or string, got null.');

        Arr::keyBy([['id' => null]], 'id');
    }

    public function test_group_by(): void
    {
        $groups = Arr::groupBy($this->users(), 'dept');

        $this->assertSame(['IT', 'HR'], array_keys($groups));
        $this->assertSame([$this->users()[0], $this->users()[2]], $groups['IT']);
        $this->assertSame([$this->users()[1]], $groups['HR']);

        $bySalary = Arr::groupBy($this->users(), static fn (array $user): string => $user['salary'] >= 5000 ? 'mare' : 'mic');
        $this->assertCount(2, $bySalary['mare']);
    }

    public function test_group_by_rejects_bool_key(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the key of item #0 must be int or string, got bool');

        Arr::groupBy([['active' => true]], 'active');
    }

    // first / last / find / any / all

    public function test_first_and_last(): void
    {
        $this->assertSame($this->users()[0], Arr::first($this->users()));
        $this->assertSame($this->users()[2], Arr::last($this->users()));
        $this->assertSame($this->users()[1], Arr::first($this->users(), static fn (array $user): bool => $user['dept'] === 'HR'));
        $this->assertSame($this->users()[2], Arr::last($this->users(), static fn (array $user): bool => $user['dept'] === 'IT'));
        $this->assertSame('b', Arr::last(['x' => 'a', 'y' => 'b']));
        $this->assertSame('b', Arr::first(['x' => 'a', 'y' => 'b'], static fn (string $value, string $key): bool => $key === 'y'));
    }

    public function test_first_and_last_with_default(): void
    {
        $this->assertNull(Arr::first([], null, null));
        $this->assertSame('-', Arr::last($this->users(), static fn (array $user): bool => $user['dept'] === 'X', '-'));
    }

    public function test_first_empty_array_throws(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Arr::first(): array is empty.');

        Arr::first([]);
    }

    public function test_last_no_match_throws(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Arr::last(): no element matches the callback.');

        Arr::last($this->users(), static fn (array $user): bool => $user['id'] > 100);
    }

    public function test_first_returns_null_element_distinct_from_missing(): void
    {
        $this->assertNull(Arr::first([null, 1]));
    }

    public function test_find_and_find_key(): void
    {
        $this->assertSame($this->users()[2], Arr::find($this->users(), static fn (array $user): bool => $user['name'] === 'Dan'));
        $this->assertNull(Arr::find($this->users(), static fn (array $user): bool => $user['name'] === 'X'));
        $this->assertSame(1, Arr::findKey($this->users(), static fn (array $user): bool => $user['name'] === 'Ana'));
        $this->assertSame('b', Arr::findKey(['a' => 1, 'b' => 2], static fn (int $value, string $key): bool => $value === 2));
        $this->assertNull(Arr::findKey([], static fn (): bool => true));
    }

    public function test_any_and_all(): void
    {
        $this->assertTrue(Arr::any($this->users(), static fn (array $user): bool => $user['dept'] === 'HR'));
        $this->assertFalse(Arr::any($this->users(), static fn (array $user): bool => $user['dept'] === 'X'));
        $this->assertFalse(Arr::any([], static fn (): bool => true));

        $this->assertTrue(Arr::all($this->users(), static fn (array $user): bool => $user['salary'] > 0));
        $this->assertFalse(Arr::all($this->users(), static fn (array $user): bool => $user['dept'] === 'IT'));
        $this->assertTrue(Arr::all([], static fn (): bool => false));
    }

    public function test_callback_must_return_bool(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Arr::any(): callback must return bool, got int.');

        Arr::any([1, 2], $this->untypedCallback(static fn (int $value): int => $value - 1));
    }

    // contains / search

    public function test_contains_is_strict(): void
    {
        $this->assertTrue(Arr::contains([1, '2', null], '2'));
        $this->assertFalse(Arr::contains([1, '2'], 2));
        $this->assertFalse(Arr::contains(['abc'], 0));
        $this->assertFalse(Arr::contains([0], false));
        $this->assertTrue(Arr::contains([null], null));
    }

    public function test_search(): void
    {
        $this->assertSame(0, Arr::search(['a', 'b'], 'a'), 'cheia 0 nu se confunda cu false');
        $this->assertSame('y', Arr::search(['x' => 1, 'y' => '1'], '1'));
    }

    public function test_search_missing_throws(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Arr::search(): value not found.');

        Arr::search(['1', '2'], 1);
    }

    // flatten / wrap

    public function test_flatten(): void
    {
        $nested = ['a' => 1, 'b' => [2, 'c' => [3, [4]]], 5];

        $this->assertSame([1, 2, 3, 4, 5], Arr::flatten($nested));
        $this->assertSame([1, 2, [3, [4]], 5], Arr::flatten($nested, 1));
        $this->assertSame([1, 2, 3, [4], 5], Arr::flatten($nested, 2));
        $this->assertSame([], Arr::flatten([[], [[]]]));
    }

    public function test_flatten_rejects_zero_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Arr::flatten(): depth must be >= 1, got 0.');

        Arr::flatten([], 0);
    }

    public function test_wrap(): void
    {
        $this->assertSame([], Arr::wrap(null));
        $this->assertSame(['a'], Arr::wrap('a'));
        $this->assertSame([0], Arr::wrap(0));
        $this->assertSame([false], Arr::wrap(false));
        $this->assertSame(['x' => 1], Arr::wrap(['x' => 1]));
    }

    // sortBy / where / sum

    public function test_sort_by(): void
    {
        $this->assertSame(['Ana', 'Ion', 'Dan'], Arr::pluck(Arr::sortBy($this->users(), 'salary'), 'name'));
        $this->assertSame(['Dan', 'Ion', 'Ana'], Arr::pluck(Arr::sortBy($this->users(), 'salary', true), 'name'));
        $this->assertSame(['Ana', 'Dan', 'Ion'], Arr::pluck(Arr::sortBy($this->users(), 'name'), 'name'));
        $this->assertSame([0, 1, 2], array_keys(Arr::sortBy($this->users(), 'name')), 'lista ramane lista');
    }

    public function test_sort_by_preserves_keys_of_assoc_array(): void
    {
        $prices = ['b' => ['p' => 2], 'a' => ['p' => 3], 'c' => ['p' => 1]];

        $this->assertSame(['c', 'b', 'a'], array_keys(Arr::sortBy($prices, 'p')));
    }

    public function test_sort_by_is_stable(): void
    {
        $sorted = Arr::sortBy($this->users(), 'dept');

        $this->assertSame(['Ana', 'Ion', 'Dan'], Arr::pluck($sorted, 'name'), 'Ion inaintea lui Dan, ca in original');
    }

    public function test_sort_by_numeric_strings_and_dates(): void
    {
        $rows = [['v' => '10'], ['v' => '9'], ['v' => '100']];
        $this->assertSame(['9', '10', '100'], Arr::pluck(Arr::sortBy($rows, 'v'), 'v'));

        $dates = [['d' => new DateTimeImmutable('2026-03-01')], ['d' => new DateTimeImmutable('2025-01-01')]];
        $this->assertSame('2025', Arr::sortBy($dates, 'd')[0]['d']->format('Y'));

        $this->assertSame([1, 2.5, 3], Arr::sortBy([3, 1, 2.5], static fn (int|float $value): int|float => $value));
    }

    public function test_sort_by_rejects_mixed_types(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Arr::sortBy(): cannot compare number (item #1) with string (earlier items).');

        Arr::sortBy([['v' => 'a'], ['v' => 1]], 'v');
    }

    public function test_sort_by_rejects_null(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Arr::sortBy(): item #1 cannot be sorted (null).');

        Arr::sortBy([['v' => 1], ['v' => null]], 'v');
    }

    public function test_where_is_strict(): void
    {
        $this->assertSame([$this->users()[0], $this->users()[2]], Arr::where($this->users(), 'dept', 'IT'));
        $this->assertSame([], Arr::where($this->users(), 'id', '10'), "'10' !== 10");
        $this->assertSame(['b' => ['x' => 1]], Arr::where(['a' => ['x' => 2], 'b' => ['x' => 1]], 'x', 1), 'chei pastrate');
    }

    public function test_sum(): void
    {
        $this->assertSame(15000, Arr::sum($this->users(), 'salary'));
        $this->assertSame(6, Arr::sum([1, 2, 3]));
        $this->assertEqualsWithDelta(3.5, Arr::sum([1, 2.5]), PHP_FLOAT_EPSILON);
        $this->assertSame(0, Arr::sum([]));
        $this->assertSame(2, Arr::sum($this->users(), static fn (array $user): int => $user['dept'] === 'IT' ? 1 : 0));
    }

    public function test_sum_rejects_numeric_string(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Arr::sum(): item #1 must be int or float, got string.');

        Arr::sum([1, '2']);
    }

    public function test_is_assoc(): void
    {
        $this->assertFalse(Arr::isAssoc([]));
        $this->assertFalse(Arr::isAssoc(['a', 'b']));
        $this->assertTrue(Arr::isAssoc([1 => 'a', 0 => 'b']));
        $this->assertTrue(Arr::isAssoc(['x' => 1]));
    }

    public function test_string_field_is_never_called_as_function(): void
    {
        $this->assertSame([5], Arr::pluck([['count' => 5]], 'count'));

        $this->expectException(OutOfBoundsException::class);

        Arr::pluck([['a' => 1]], 'count');
    }
}
