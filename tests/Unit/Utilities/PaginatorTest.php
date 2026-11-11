<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Paginator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    private const array SORTABLE = ['name' => ['u.last_name', 'u.first_name'], 'created' => 'u.created_at'];

    /**
     * @param  array<array-key, mixed>  $query
     */
    private function pager(array $query = [], int $perPage = 20): Paginator
    {
        return Paginator::fromRequest($query, self::SORTABLE, 'created', 'desc', $perPage);
    }

    // citirea parametrilor

    public function test_valid_request(): void
    {
        $pager = $this->pager(['page' => '3', 'per_page' => '50', 'sort' => 'name', 'order' => 'ASC', 'search' => "  Ion \t Pop "]);

        $this->assertSame(3, $pager->page);
        $this->assertSame(50, $pager->perPage);
        $this->assertSame('name', $pager->sort);
        $this->assertSame('asc', $pager->order);
        $this->assertSame('Ion Pop', $pager->search);
        $this->assertSame(100, $pager->offset());
        $this->assertSame('u.last_name ASC, u.first_name ASC', $pager->orderBy());
    }

    public function test_defaults(): void
    {
        $pager = $this->pager();

        $this->assertSame([1, 20, 'created', 'desc', ''], [$pager->page, $pager->perPage, $pager->sort, $pager->order, $pager->search]);
        $this->assertSame('u.created_at DESC', $pager->orderBy());
        $this->assertSame(0, $pager->offset());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'text' => [['page' => 'abc', 'per_page' => 'x', 'sort' => 'parola', 'order' => 'sideways']];
        yield 'zero si negativ' => [['page' => '0', 'per_page' => '-5']];
        yield 'zecimal' => [['page' => '2.5', 'per_page' => '10.0']];
        yield 'prea mare' => [['page' => '99999999999999999999', 'per_page' => '100000']];
        yield 'array' => [['page' => ['1'], 'sort' => ['name'], 'search' => ['x']]];
        yield 'injectie in sortare' => [['sort' => 'created_at; DROP TABLE users', 'order' => 'desc, (SELECT 1)']];
        yield 'zerouri in fata' => [['page' => '007']];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    #[DataProvider('invalidInputProvider')]
    public function test_invalid_input_falls_back_to_defaults(array $query): void
    {
        $pager = $this->pager($query);

        $this->assertSame(1, $pager->page);
        $this->assertSame(20, $pager->perPage);
        $this->assertSame('u.created_at DESC', $pager->orderBy());
        $this->assertSame('', $pager->search);
    }

    public function test_page_limits(): void
    {
        $this->assertSame(Paginator::MAX_PAGE, $this->pager(['page' => (string) Paginator::MAX_PAGE])->page);
        $this->assertSame(1, $this->pager(['page' => (string) (Paginator::MAX_PAGE + 1)])->page);
        $this->assertSame(100, $this->pager(['per_page' => '100'])->perPage);
        $this->assertSame(20, $this->pager(['per_page' => '101'])->perPage);
    }

    public function test_too_long_or_invalid_search_is_ignored(): void
    {
        $this->assertSame('', $this->pager(['search' => str_repeat('a', Paginator::MAX_SEARCH_LENGTH + 1)])->search);
        $this->assertSame(str_repeat('a', 100), $this->pager(['search' => str_repeat('a', 100)])->search);
        $this->assertSame('', $this->pager(['search' => "abc\xC3\x28"])->search, 'UTF-8 invalid');
    }

    /**
     * @return iterable<string, array{array<string, string|list<string>>, string, string, int, int, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'lista goala' => [[], 'x', 'asc', 20, 100, 'sortable must not be empty'];
        yield 'implicit necunoscut' => [['name' => 'n'], 'x', 'asc', 20, 100, "default sort 'x' is not in the sortable list"];
        yield 'ordine invalida' => [['name' => 'n'], 'name', 'up', 20, 100, "default order must be 'asc' or 'desc'"];
        yield 'perPage peste maxim' => [['name' => 'n'], 'name', 'asc', 200, 100, 'perPage must be between 1 and maxPerPage'];
        yield 'coloana goala' => [['name' => ''], 'name', 'asc', 20, 100, "sortable entry 'name' must map"];
        yield 'lista de coloane goala' => [['name' => []], 'name', 'asc', 20, 100, "sortable entry 'name' must map"];
    }

    /**
     * @param  array<string, string|list<string>>  $sortable
     */
    #[DataProvider('invalidConfigProvider')]
    public function test_invalid_configuration_throws(array $sortable, string $default, string $order, int $perPage, int $max, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Paginator::fromRequest([], $sortable, $default, $order, $perPage, $max);
    }

    // cautare

    public function test_search_condition(): void
    {
        $pager = $this->pager(['search' => 'ion 50%_!']);

        $this->assertSame(
            [
                "(u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!') AND (u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')",
                ['%ion%', '%ion%', '%50!%!_!!%', '%50!%!_!!%'],
            ],
            $pager->searchCondition(['u.name', 'u.email']),
        );
    }

    public function test_search_condition_named_and_case_insensitive(): void
    {
        $pager = $this->pager(['search' => 'ion']);

        $this->assertSame(
            ["(LOWER(u.name) LIKE LOWER(:paginator_search_0) ESCAPE '!')", ['paginator_search_0' => '%ion%']],
            $pager->searchCondition(['u.name'], caseInsensitive: true, named: true),
        );
    }

    public function test_empty_search_matches_everything(): void
    {
        $this->assertSame(['1 = 1', []], $this->pager()->searchCondition(['u.name']));
    }

    public function test_search_word_limit(): void
    {
        $pager = $this->pager(['search' => implode(' ', range(1, 15))]);

        [, $params] = $pager->searchCondition(['c']);

        $this->assertCount(Paginator::MAX_SEARCH_WORDS, $params);
    }

    public function test_search_condition_rejects_empty_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pager(['search' => 'x'])->searchCondition([]);
    }

    public function test_search_and_sort_on_a_real_database(): void
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE u (id INTEGER PRIMARY KEY, last_name TEXT, first_name TEXT, created_at TEXT)');
        $stmt = $pdo->prepare('INSERT INTO u (last_name, first_name, created_at) VALUES (?, ?, ?)');
        foreach ([['Pop', 'Ion'], ['Pop', 'Ana'], ['Ionescu', 'Dan'], ['100%_real', 'Eva'], ['100 real', 'Gelu']] as $i => [$last, $first]) {
            $stmt->execute([$last, $first, '2026-01-0'.($i + 1)]);
        }

        $pager = Paginator::fromRequest(['search' => 'pop', 'sort' => 'name', 'order' => 'asc'], ['name' => ['last_name', 'first_name', 'id'], 'created' => 'created_at'], 'created');
        [$search, $params] = $pager->searchCondition(['last_name', 'first_name']);
        $stmt = $pdo->prepare("SELECT first_name FROM u WHERE {$search} ORDER BY ".$pager->orderBy());
        $stmt->execute($params);
        $items = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['Ana', 'Ion'], $items, 'LIKE in SQLite nu tine cont de majuscule ASCII');

        $literal = Paginator::fromRequest(['search' => '100%_'], ['created' => 'created_at'], 'created');
        [$search, $params] = $literal->searchCondition(['last_name']);
        $stmt = $pdo->prepare("SELECT first_name FROM u WHERE {$search}");
        $stmt->execute($params);
        $items = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['Eva'], $items, '% si _ cautate literal');
    }

    // navigare

    public function test_pages(): void
    {
        $pager = $this->pager(perPage: 10);

        $this->assertSame(1, $pager->pages(0));
        $this->assertSame(1, $pager->pages(10));
        $this->assertSame(2, $pager->pages(11));

        $this->expectException(InvalidArgumentException::class);
        $pager->pages(-1);
    }

    /**
     * @return iterable<string, array{int, int, int, list<int|null>}>
     */
    public static function pageNumbersProvider(): iterable
    {
        yield 'mijloc' => [6, 200, 2, [1, null, 4, 5, 6, 7, 8, null, 20]];
        yield 'inceput' => [1, 200, 2, [1, 2, 3, null, 20]];
        yield 'sfarsit' => [20, 200, 2, [1, null, 18, 19, 20]];
        yield 'putine pagini' => [1, 50, 2, [1, 2, 3, 4, 5]];
        yield 'o singura pagina sarita se afiseaza' => [4, 200, 1, [1, 2, 3, 4, 5, null, 20]];
        yield 'fara rezultate' => [1, 0, 2, [1]];
        yield 'fereastra zero' => [5, 100, 0, [1, null, 5, null, 10]];
    }

    /**
     * @param  list<int|null>  $expected
     */
    #[DataProvider('pageNumbersProvider')]
    public function test_page_numbers(int $page, int $total, int $window, array $expected): void
    {
        $pager = $this->pager(['page' => (string) $page], 10);

        $this->assertSame($expected, $pager->pageNumbers($total, $window));
    }

    public function test_query_keeps_application_filters(): void
    {
        $pager = $this->pager(['page' => '2', 'status' => 'active', 'search' => ' ion ', 'sort' => 'invalid']);

        $this->assertSame(
            ['page' => 3, 'status' => 'active', 'search' => 'ion', 'sort' => 'created', 'per_page' => 20, 'order' => 'desc'],
            $pager->query(3),
        );
        $this->assertArrayNotHasKey('search', $this->pager(['search' => ''])->query(1));
        $this->assertSame('page=1&per_page=20&sort=created&order=desc', http_build_query($this->pager()->query(1)));
    }
}
