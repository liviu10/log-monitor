<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\MySQLWrapper;

/**
 * Backend pagination, filtering, sorting, and search utility.
 */
final class PaginationHelper
{
    /**
     * Dynamically and securely generates a paginated, filtered, and sorted query.
     *
     * @param  string  $table  Table name.
     * @param  array<int, string>  $columns  Fields to select.
     * @param  string  $baseWhere  Base WHERE clause.
     * @param  array<int, mixed>  $baseParams  Base clause parameters.
     * @param  array<int, string>  $joins  JOIN clauses.
     * @param  array<int, string>  $searchFields  Fields to search in.
     * @param  array<int, string>  $allowedSorts  Allowed sorting fields.
     * @param  string  $defaultSort  Default sort field.
     * @param  string  $defaultOrder  Default sort direction (asc/desc).
     * @param  array<string, string>  $sortMap  Sorting fields mapping.
     * @return array{items: array<int, array<string, mixed>>, total_items: int, total_pages: int, current_page: int, per_page: int, sort: string, order: string, search: string}
     */
    public static function paginate(
        string $table,
        array $columns,
        string $baseWhere = '1=1',
        array $baseParams = [],
        array $joins = [],
        array $searchFields = [],
        array $allowedSorts = [],
        string $defaultSort = 'id',
        string $defaultOrder = 'desc',
        array $sortMap = []
    ): array {
        $db = MySQLWrapper::getInstance();

        // 1. Retrieve query parameters from URL (query string)
        $rawSearch = $_GET['search'] ?? '';
        $search = trim(is_string($rawSearch) ? $rawSearch : '');

        $rawSortBy = $_GET['sort'] ?? $defaultSort;
        $sortBy = trim(is_string($rawSortBy) ? $rawSortBy : $defaultSort);

        $rawOrder = $_GET['order'] ?? $defaultOrder;
        $orderStr = is_string($rawOrder) ? $rawOrder : $defaultOrder;
        $order = strtolower(trim($orderStr)) === 'asc' ? 'asc' : 'desc';

        $rawPage = $_GET['page'] ?? 1;
        $pageInt = is_scalar($rawPage) ? (int) $rawPage : 1;
        $page = max(1, $pageInt);

        $rawPerPage = $_GET['per_page'] ?? 10;
        $perPageInt = is_scalar($rawPerPage) ? (int) $rawPerPage : 10;
        $perPage = min(100, max(5, $perPageInt));

        // Validation of sorting field
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = $defaultSort;
        }

        // Strict sanitization: ensure sortBy contains only valid characters (prevent SQL injection)
        if (preg_match('/^[a-zA-Z0-9_\.]+$/', $sortBy) !== 1) {
            $sortBy = 'id';
        }

        // WHERE clause construction
        $whereClauses = [$baseWhere];
        $params = $baseParams;

        // 2. Apply dynamic filters (GET parameters like filter_<field>)
        foreach ($_GET as $key => $val) {
            if (str_starts_with($key, 'filter_')) {
                $field = substr($key, 7); // eliminam prefixul
                // The key is user input injected as an identifier: accept only `column` or `table.column`.
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}(\.[A-Za-z_][A-Za-z0-9_]{0,63})?$/', $field) !== 1) {
                    continue;
                }
                if ($val !== '' && $val !== 'all' && is_string($val)) {
                    $escapedField = '`'.str_replace('.', '`.`', $field).'`';
                    $whereClauses[] = "{$escapedField} = ?";
                    $params[] = $val;
                }
            }
        }

        // 3. Apply global search (supports multiple space-separated keywords)
        if ($search !== '' && count($searchFields) > 0) {
            $words = array_filter(explode(' ', $search), static fn (string $val): bool => $val !== '');
            foreach ($words as $word) {
                $wordClauses = [];
                foreach ($searchFields as $field) {
                    $escapedField = str_contains($field, '.') ? $field : "`{$field}`";
                    $wordClauses[] = "{$escapedField} LIKE ?";
                    $params[] = "%{$word}%";
                }
                $whereClauses[] = '('.implode(' OR ', $wordClauses).')';
            }
        }

        $whereSql = implode(' AND ', $whereClauses);

        // Join SQL
        $joinSql = '';
        if (count($joins) > 0) {
            $joinSql = ' '.implode(' ', $joins).' ';
        }

        // 4. Determine number of total records
        $countSql = "SELECT COUNT(*) as cnt FROM {$table}{$joinSql} WHERE {$whereSql}";
        $countStmt = $db->query($countSql, $params);
        $countRow = $countStmt->fetch();
        $totalItems = 0;
        if (is_array($countRow)) {
            $rawCnt = $countRow['cnt'] ?? 0;
            $totalItems = is_scalar($rawCnt) ? (int) $rawCnt : 0;
        }

        // Calculate pagination details
        $totalPages = (int) ceil($totalItems / $perPage);
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;

        // 5. Select records with pagination
        $selectCols = [];
        foreach ($columns as $col) {
            if ($col === '*' || str_contains($col, ' as ') || str_contains($col, ' AS ') || str_contains($col, '.') || str_contains($col, '(')) {
                $selectCols[] = $col;
            } else {
                $selectCols[] = "`{$col}`";
            }
        }
        $selectSql = implode(', ', $selectCols);

        // Sort clause (with mapping if applicable)
        $sortSql = $sortBy;
        if (isset($sortMap[$sortBy])) {
            $sortSql = $sortMap[$sortBy];
        } elseif (! str_contains($sortBy, '.')) {
            $sortSql = "`{$sortBy}`";
        }

        $itemsSql = "SELECT {$selectSql} FROM {$table}{$joinSql} WHERE {$whereSql} ORDER BY {$sortSql} {$order} LIMIT {$perPage} OFFSET {$offset}";
        $itemsStmt = $db->query($itemsSql, $params);
        $itemsFetched = $itemsStmt->fetchAll();
        /** @var array<int, array<string, mixed>> $items */
        $items = $itemsFetched;

        return [
            'items' => $items,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'per_page' => $perPage,
            'sort' => $sortBy,
            'order' => $order,
            'search' => $search,
        ];
    }
}
