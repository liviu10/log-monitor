<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Core\MySQLWrapper;
use App\Models\App;
use InvalidArgumentException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class AppTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    private App $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
        $this->model = new App($this->dbMock);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_find_by_api_key_throws_on_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->model->findByApiKey('   ');
    }

    public function test_find_by_api_key_returns_row(): void
    {
        $this->dbMock->expects($this->once())
            ->method('read')
            ->with('apps', ['api_key' => 'secret-123'])
            ->willReturn([['id' => 4, 'name' => 'Billing', 'api_key' => 'secret-123']]);

        $app = $this->model->findByApiKey('secret-123');
        self::assertSame(4, $app['id']);
        self::assertSame('Billing', $app['name']);
    }

    public function test_find_by_id_returns_null_on_invalid_or_missing(): void
    {
        self::assertNull($this->model->findById(0));

        $this->dbMock->expects($this->once())
            ->method('read')
            ->with('apps', ['id' => 99])
            ->willReturn([]);

        self::assertNull($this->model->findById(99));
    }

    public function test_create_update_and_delete(): void
    {
        $this->dbMock->expects($this->once())
            ->method('create')
            ->with('apps', ['name' => 'Mobile', 'api_key' => 'key-1'])
            ->willReturn(12);

        self::assertSame(12, $this->model->create('Mobile', 'key-1'));

        $this->dbMock->expects($this->once())
            ->method('update')
            ->with('apps', self::callback(fn (array $d): bool => ($d['name'] ?? '') === 'Mobile v2' && isset($d['updated_at'])), ['id' => 12])
            ->willReturn(1);

        $this->model->update(12, ['name' => 'Mobile v2']);

        $this->dbMock->expects($this->once())
            ->method('delete')
            ->with('apps', ['id' => 12])
            ->willReturn(1);

        $this->model->delete(12);
    }

    public function test_delete_throws_when_zero_rows_affected(): void
    {
        $this->dbMock->method('delete')->willReturn(0);
        $this->expectException(RuntimeException::class);
        $this->model->delete(999);
    }

    public function test_get_top_apps_and_dashboard_summary(): void
    {
        $stmtTop = $this->createMock(PDOStatement::class);
        $stmtTop->method('fetchAll')->willReturn([
            ['id' => 1, 'app_name' => 'CoreAPI', 'log_count' => 150, 'last_logged_at' => '2026-11-20 12:00:00'],
        ]);

        $stmtCount = $this->createMock(PDOStatement::class);
        $stmtCount->method('fetch')->willReturn(['total' => 5]);

        $this->dbMock->method('query')->willReturnOnConsecutiveCalls($stmtTop, $stmtCount);

        $top = App::getTopApps(5);
        self::assertCount(1, $top);
        self::assertSame('CoreAPI', $top[0]['app_name']);

        $summary = App::dashboardSummaryApps();
        self::assertSame(5, $summary['value']);
        self::assertSame('/admin/apps', $summary['url']);
    }
}
