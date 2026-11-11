<?php

declare(strict_types=1);

namespace App\Utilities;

use InvalidArgumentException;

/**
 * Paginator Class
 *
 * Pagination, sorting, and search parameters from the URL, validated and transformed into safe
 * SQL fragments. Works with MySQL, Oracle, and SQLite (via the wrapper's paginate() method).
 *
 * URL parameters represent user input: an invalid value (?page=abc, an unknown sort column,
 * ?per_page=100000) falls back to the default value rather than producing an error.
 * Misconfiguration (by the developer) throws an exception.
 *
 * Security:
 *   - Sorting is only permitted on keys defined in $sortable; the SQL receives the expression
 *     associated with the key (written by the developer), never raw URL input;
 *   - Search uses bound parameters, and %, _, and ! in the search term are escaped
 *     (LIKE ... ESCAPE '!', supported across MySQL, Oracle, and SQLite).
 *
 * Usage:
 *   require_once 'Paginator.php';
 *   use App\Utilities\Paginator;
 *
 *   $pager = Paginator::fromRequest($_GET,
 *       sortable: ['name' => ['u.last_name', 'u.first_name'], 'created' => 'u.created_at'],
 *       defaultSort: 'created', defaultOrder: 'desc');
 *
 *   [$search, $searchParams] = $pager->searchCondition(['u.last_name', 'u.email']);
 *   $result = $db->paginate(
 *       "SELECT u.* FROM users u WHERE u.active = ? AND {$search} ORDER BY ".$pager->orderBy(),
 *       [1, ...$searchParams], $pager->page, $pager->perPage);
 *
 *   foreach ($pager->pageNumbers($result['total']) as $number) {
 *       echo $number === null ? '...' : '<a href="?'.http_build_query($pager->query($number)).'">'.$number.'</a>';
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
final readonly class Paginator
{
    /** @var int Maximum page accepted from the URL (prevents huge offsets). */
    public const int MAX_PAGE = 1_000_000;

    /** @var int Maximum search term length in characters. */
    public const int MAX_SEARCH_LENGTH = 100;

    /** @var int Maximum number of search words (subsequent words are ignored). */
    public const int MAX_SEARCH_WORDS = 10;

    /**
     * @param  array<string, list<string>>  $sortable  Sort key -> SQL expressions mapping.
     * @param  array<array-key, mixed>  $query  Initial URL parameters (preserved in query()).
     */
    private function __construct(
        public int $page,
        public int $perPage,
        public string $sort,
        public string $order,
        public string $search,
        private array $sortable,
        private array $query,
    ) {}

    /**
     * Reads page, per_page, sort, order, and search from URL parameters (typically $_GET).
     *
     * @param  array<array-key, mixed>  $query
     * @param  array<string, string|list<string>>  $sortable  URL key -> SQL column(s) mapping
     *                                                        (e.g., 'name' => ['u.last_name', 'u.first_name']).
     *
     * @throws InvalidArgumentException If the configuration is invalid.
     */
    public static function fromRequest(
        array $query,
        array $sortable,
        string $defaultSort,
        string $defaultOrder = 'asc',
        int $perPage = 20,
        int $maxPerPage = 100,
    ): self {
        $columns = self::sortable($sortable);

        if (! isset($columns[$defaultSort])) {
            throw new InvalidArgumentException("Paginator::fromRequest(): default sort '{$defaultSort}' is not in the sortable list.");
        }

        if ($defaultOrder !== 'asc' && $defaultOrder !== 'desc') {
            throw new InvalidArgumentException("Paginator::fromRequest(): default order must be 'asc' or 'desc', got '{$defaultOrder}'.");
        }

        if ($perPage < 1 || $maxPerPage < $perPage) {
            throw new InvalidArgumentException("Paginator::fromRequest(): perPage must be between 1 and maxPerPage, got {$perPage} and {$maxPerPage}.");
        }

        $sort = self::text($query, 'sort');
        $order = strtolower(self::text($query, 'order'));
        $search = trim((string) preg_replace('/\s+/u', ' ', self::text($query, 'search')));

        return new self(
            page: self::integer($query, 'page', 1, self::MAX_PAGE) ?? 1,
            perPage: self::integer($query, 'per_page', 1, $maxPerPage) ?? $perPage,
            sort: isset($columns[$sort]) ? $sort : $defaultSort,
            order: $order === 'asc' || $order === 'desc' ? $order : $defaultOrder,
            search: mb_strlen($search) <= self::MAX_SEARCH_LENGTH ? $search : '',
            sortable: $columns,
            query: $query,
        );
    }

    /**
     * Offset of the first row on the current page (for LIMIT ... OFFSET, if not using paginate()).
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The ORDER BY clause fragment (without the keyword): 'u.last_name DESC, u.first_name DESC'.
     * For a stable order across pages, the last column should be unique (e.g., u.id).
     */
    public function orderBy(): string
    {
        $direction = strtoupper($this->order);

        return implode(', ', array_map(static fn (string $column): string => $column.' '.$direction, $this->sortable[$this->sort]));
    }

    /**
     * Search condition: each word must match in at least one of the columns.
     * With an empty search string: ['1 = 1', []]. Parameters are positional (?), or named
     * (:paginator_search_0, ...) when $named = true, to match the query style.
     *
     * @param  list<string>  $columns  SQL columns to search in (written by the developer).
     * @return array{0: string, 1: array<array-key, string>}
     *
     * @throws InvalidArgumentException If the column list is empty.
     */
    public function searchCondition(array $columns, bool $caseInsensitive = false, bool $named = false): array
    {
        if ($columns === [] || in_array('', $columns, true)) {
            throw new InvalidArgumentException('Paginator::searchCondition(): columns must be a non-empty list of non-empty strings.');
        }

        if ($this->search === '') {
            return ['1 = 1', []];
        }

        $words = array_slice(explode(' ', $this->search), 0, self::MAX_SEARCH_WORDS);
        $conditions = [];
        $params = [];

        foreach ($words as $word) {
            $pattern = '%'.strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $alternatives = [];

            foreach ($columns as $column) {
                $placeholder = $named ? ':paginator_search_'.count($params) : '?';
                $alternatives[] = ($caseInsensitive ? "LOWER({$column}) LIKE LOWER({$placeholder})" : "{$column} LIKE {$placeholder}")." ESCAPE '!'";

                if ($named) {
                    $params['paginator_search_'.count($params)] = $pattern;
                } else {
                    $params[] = $pattern;
                }
            }

            $conditions[] = '('.implode(' OR ', $alternatives).')';
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Total number of pages for a given record count (minimum 1).
     *
     * @throws InvalidArgumentException If total is negative.
     */
    public function pages(int $total): int
    {
        if ($total < 0) {
            throw new InvalidArgumentException("Paginator::pages(): total must be >= 0, got {$total}.");
        }

        return max(1, (int) ceil($total / $this->perPage));
    }

    /**
     * Page numbers for navigation, with null for skipped ranges:
     * page 6 of 20, window 2 -> [1, null, 4, 5, 6, 7, 8, null, 20].
     *
     * @return list<int|null>
     *
     * @throws InvalidArgumentException If total or window is negative.
     */
    public function pageNumbers(int $total, int $window = 2): array
    {
        if ($window < 0) {
            throw new InvalidArgumentException("Paginator::pageNumbers(): window must be >= 0, got {$window}.");
        }

        $last = $this->pages($total);
        $numbers = [];
        $previous = 0;

        for ($number = 1; $number <= $last; $number++) {
            if ($number !== 1 && $number !== $last && abs($number - $this->page) > $window) {
                continue;
            }
            if ($number - $previous > 1) {
                // A single skipped page is shown directly (an ellipsis is redundant for 1 page).
                $numbers[] = $number - $previous === 2 ? $number - 1 : null;
            }
            $numbers[] = $number;
            $previous = $number;
        }

        return $numbers;
    }

    /**
     * URL parameters for page $page: incoming parameters (including custom application filters)
     * with normalized values. For links: '?'.http_build_query($pager->query(3)).
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If $page is less than 1.
     */
    public function query(int $page): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException("Paginator::query(): page must be >= 1, got {$page}.");
        }

        $query = [...$this->query, 'page' => $page, 'per_page' => $this->perPage, 'sort' => $this->sort, 'order' => $this->order];

        if ($this->search === '') {
            unset($query['search']);
        } else {
            $query['search'] = $this->search;
        }

        return $query;
    }

    /**
     * Validates the sortable column list and normalizes it to key -> list of expressions.
     *
     * @param  array<string, string|list<string>>  $sortable
     * @return array<string, list<string>>
     *
     * @throws InvalidArgumentException If the list is empty or contains invalid entries.
     */
    private static function sortable(array $sortable): array
    {
        if ($sortable === []) {
            throw new InvalidArgumentException('Paginator::fromRequest(): sortable must not be empty.');
        }

        $columns = [];
        foreach ($sortable as $key => $expression) {
            $list = is_string($expression) ? [$expression] : $expression;

            if ($key === '' || $list === [] || in_array('', $list, true)) {
                throw new InvalidArgumentException("Paginator::fromRequest(): sortable entry '{$key}' must map a non-empty key to non-empty column names.");
            }
            $columns[$key] = $list;
        }

        return $columns;
    }

    /**
     * A text parameter from URL; any other value (missing, array from ?x[]=) -> ''.
     *
     * @param  array<array-key, mixed>  $query
     */
    private static function text(array $query, string $key): string
    {
        $value = $query[$key] ?? '';

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : '';
    }

    /**
     * A positive integer from the URL within the specified range; otherwise null.
     *
     * @param  array<array-key, mixed>  $query
     */
    private static function integer(array $query, string $key, int $min, int $max): ?int
    {
        $value = self::text($query, $key);

        if (preg_match('/^[1-9]\d{0,9}$/D', $value) !== 1) {
            return null;
        }

        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }
}
