<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Core\MySQLWrapper;
use App\Utilities\PaginationHelper;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Class PaginationHelperTest
 *
 * @category Tests
 */
class PaginationHelperTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    protected function setUp(): void
    {
        parent::setUp();

        $_GET = [];

        $this->dbMock = $this->createMock(MySQLWrapper::class);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, null);

        $_GET = [];
        parent::tearDown();
    }

    /**
     * Configures the mock DB to expect specific COUNT and SELECT queries.
     *
     * @param  array<int, mixed>  $expectedCountParams
     * @param  array<int, mixed>  $expectedSelectParams
     * @param  array<int, array<string, mixed>>  $selectResults
     */
    private function expectQueries(
        string $expectedCountSql,
        array $expectedCountParams,
        int $totalCount,
        string $expectedSelectSql,
        array $expectedSelectParams,
        array $selectResults
    ): void {
        $countStmtMock = $this->createMock(PDOStatement::class);
        $countStmtMock->expects($this->once())->method('fetch')->willReturn(['cnt' => $totalCount]);

        $selectStmtMock = $this->createMock(PDOStatement::class);
        $selectStmtMock->expects($this->once())->method('fetchAll')->willReturn($selectResults);

        $this->dbMock->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $sql, array $params) use ($expectedCountSql, $expectedCountParams, $countStmtMock, $expectedSelectSql, $expectedSelectParams, $selectStmtMock) {
                if (str_starts_with($sql, 'SELECT COUNT')) {
                    self::assertEquals($expectedCountSql, $sql);
                    self::assertEquals($expectedCountParams, $params);

                    return $countStmtMock;
                }

                self::assertEquals($expectedSelectSql, $sql);
                self::assertEquals($expectedSelectParams, $params);

                return $selectStmtMock;
            });
    }

    public function test_paginate_with_defaults(): void
    {
        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            25,
            'SELECT `id`, `name` FROM users WHERE 1=1 ORDER BY `id` desc LIMIT 10 OFFSET 0',
            [],
            [['id' => 1, 'name' => 'Test']]
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id', 'name'],
            allowedSorts: ['id', 'name']
        );

        self::assertEquals(25, $result['total_items']);
        self::assertEquals(3, $result['total_pages']);
        self::assertEquals(1, $result['current_page']);
        self::assertEquals(10, $result['per_page']);
        self::assertEquals('id', $result['sort']);
        self::assertEquals('desc', $result['order']);
        self::assertEquals('', $result['search']);
        self::assertCount(1, $result['items']);
    }

    public function test_paginate_applies_url_parameters(): void
    {
        $_GET['page'] = '3';
        $_GET['per_page'] = '5';
        $_GET['sort'] = 'name';
        $_GET['order'] = 'asc';

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            12,
            'SELECT `id`, `name` FROM users WHERE 1=1 ORDER BY `name` asc LIMIT 5 OFFSET 10',
            [],
            [['id' => 11, 'name' => 'Alice'], ['id' => 12, 'name' => 'Bob']]
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id', 'name'],
            allowedSorts: ['id', 'name']
        );

        self::assertEquals(12, $result['total_items']);
        self::assertEquals(3, $result['total_pages']);
        self::assertEquals(3, $result['current_page']);
        self::assertEquals(5, $result['per_page']);
        self::assertEquals('name', $result['sort']);
        self::assertEquals('asc', $result['order']);
        self::assertCount(2, $result['items']);
    }

    public function test_paginate_applies_filters(): void
    {
        $_GET['filter_role'] = 'admin';
        $_GET['filter_status'] = 'active';
        $_GET['filter_ignore_me'] = 'all'; // 'all' should be ignored
        $_GET['filter_empty'] = ''; // Empty string should be ignored

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1 AND `role` = ? AND `status` = ?',
            ['admin', 'active'],
            5,
            'SELECT `id`, `name` FROM users WHERE 1=1 AND `role` = ? AND `status` = ? ORDER BY `id` desc LIMIT 10 OFFSET 0',
            ['admin', 'active'],
            []
        );

        PaginationHelper::paginate(
            table: 'users',
            columns: ['id', 'name'],
            allowedSorts: ['id']
        );
    }

    public function test_paginate_ignores_filter_keys_that_are_not_plain_identifiers(): void
    {
        $_GET['filter_id`=1 OR SLEEP(5) OR `id'] = '1';
        $_GET['filter_c.status'] = 'active';

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1 AND `c`.`status` = ?',
            ['active'],
            1,
            'SELECT `id`, `name` FROM users WHERE 1=1 AND `c`.`status` = ? ORDER BY `id` desc LIMIT 10 OFFSET 0',
            ['active'],
            []
        );

        PaginationHelper::paginate(
            table: 'users',
            columns: ['id', 'name'],
            allowedSorts: ['id']
        );
    }

    public function test_paginate_applies_search_with_multiple_words(): void
    {
        $_GET['search'] = 'john doe';

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1 AND (`first_name` LIKE ? OR `email` LIKE ?) AND (`first_name` LIKE ? OR `email` LIKE ?)',
            ['%john%', '%john%', '%doe%', '%doe%'],
            2,
            'SELECT * FROM users WHERE 1=1 AND (`first_name` LIKE ? OR `email` LIKE ?) AND (`first_name` LIKE ? OR `email` LIKE ?) ORDER BY `id` desc LIMIT 10 OFFSET 0',
            ['%john%', '%john%', '%doe%', '%doe%'],
            []
        );

        PaginationHelper::paginate(
            table: 'users',
            columns: ['*'],
            searchFields: ['first_name', 'email'],
            allowedSorts: ['id']
        );
    }

    public function test_paginate_handles_joins_and_column_aliases(): void
    {
        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users u LEFT JOIN roles r ON u.role_id = r.id  WHERE 1=1',
            [],
            10,
            'SELECT u.id, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id  WHERE 1=1 ORDER BY u.id desc LIMIT 10 OFFSET 0',
            [],
            []
        );

        PaginationHelper::paginate(
            table: 'users u',
            columns: ['u.id', 'r.name as role_name'],
            joins: ['LEFT JOIN roles r ON u.role_id = r.id'],
            allowedSorts: ['u.id'],
            defaultSort: 'u.id'
        );
    }

    public function test_paginate_invalid_sort_reverts_to_default(): void
    {
        $_GET['sort'] = 'password_hash'; // Not in allowed sorts

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            0,
            'SELECT `id` FROM users WHERE 1=1 ORDER BY `id` desc LIMIT 10 OFFSET 0',
            [],
            []
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id'],
            allowedSorts: ['id', 'name'],
            defaultSort: 'id'
        );

        self::assertEquals('id', $result['sort']);
    }

    public function test_paginate_sort_mapping(): void
    {
        $_GET['sort'] = 'role_name'; // Allowed sort, but maps to something else

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            0,
            'SELECT `id` FROM users WHERE 1=1 ORDER BY r.name desc LIMIT 10 OFFSET 0',
            [],
            []
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id'],
            allowedSorts: ['id', 'role_name'],
            defaultSort: 'id',
            defaultOrder: 'desc',
            sortMap: ['role_name' => 'r.name']
        );

        self::assertEquals('role_name', $result['sort']); // The returned sort is the original alias, but the query uses map
    }

    public function test_paginate_page_boundaries_upper(): void
    {
        $_GET['page'] = '100'; // Way past total pages
        $_GET['per_page'] = '10';

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            25, // 3 pages total
            'SELECT `id` FROM users WHERE 1=1 ORDER BY `id` desc LIMIT 10 OFFSET 20', // Should cap at offset 20 (page 3)
            [],
            []
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id'],
            allowedSorts: ['id']
        );

        self::assertEquals(3, $result['current_page']);
    }

    public function test_paginate_page_boundaries_lower(): void
    {
        // Test page < 1
        $_GET['page'] = '-5';
        $_GET['per_page'] = '4'; // min is 5

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            25,
            'SELECT `id` FROM users WHERE 1=1 ORDER BY `id` desc LIMIT 5 OFFSET 0', // Per page minimum is 5, page minimum is 1
            [],
            []
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id'],
            allowedSorts: ['id']
        );

        self::assertEquals(1, $result['current_page']);
        self::assertEquals(5, $result['per_page']);
    }

    /**
     * Tests that paginate() properly escapes search parameters to prevent SQL injection.
     */
    public function test_paginate_prevents_sql_injection_on_search(): void
    {
        $_GET['search'] = "admin' OR 1=1; --";

        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1 AND (`username` LIKE ?) AND (`username` LIKE ?) AND (`username` LIKE ?) AND (`username` LIKE ?)',
            ['%admin\'%', '%OR%', '%1=1;%', '%--%'],
            0,
            'SELECT `id` FROM users WHERE 1=1 AND (`username` LIKE ?) AND (`username` LIKE ?) AND (`username` LIKE ?) AND (`username` LIKE ?) ORDER BY `id` desc LIMIT 10 OFFSET 0',
            ['%admin\'%', '%OR%', '%1=1;%', '%--%'],
            []
        );

        PaginationHelper::paginate(
            table: 'users',
            columns: ['id'],
            searchFields: ['username'],
            allowedSorts: ['id']
        );
    }

    public function test_paginate_sanitizes_malformed_sort_by_value(): void
    {
        $this->expectQueries(
            'SELECT COUNT(*) as cnt FROM users WHERE 1=1',
            [],
            0,
            'SELECT `id` FROM users WHERE 1=1 ORDER BY `id` desc LIMIT 10 OFFSET 0',
            [],
            []
        );

        $result = PaginationHelper::paginate(
            table: 'users',
            columns: ['id'],
            allowedSorts: [],
            defaultSort: 'invalid; sql injection!'
        );

        self::assertEquals('id', $result['sort']);
    }
}
