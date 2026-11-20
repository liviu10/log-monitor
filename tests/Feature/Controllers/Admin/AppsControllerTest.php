<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers\Admin;

use App\Controllers\Admin\AppsController;
use App\Core\MySQLWrapper;
use App\Exceptions\RedirectException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AppsControllerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    /** @var array<int|string, mixed> */
    private array $originalSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [
            'auth.user' => ['id' => 1, 'email' => 'admin@logmonitor.local', 'role_name' => 'ADMIN', 'role_id' => 1],
        ];

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $reflection = new ReflectionClass(MySQLWrapper::class);
        $reflection->getProperty('instance')->setValue(null, null);
        parent::tearDown();
    }

    public function test_index_renders_apps_list(): void
    {
        $this->dbMock->expects($this->once())
            ->method('read')
            ->with('apps', [], ['*'])
            ->willReturn([
                ['id' => 1, 'name' => 'CheckoutService', 'api_key' => 'abc123key'],
            ]);

        $controller = new AppsController;
        $html = $controller->index();
        self::assertStringContainsString('Manage Applications', $html);
        self::assertStringContainsString('CheckoutService', $html);
        self::assertStringContainsString('abc123key', $html);
    }

    public function test_store_creates_app_with_generated_api_key(): void
    {
        $this->dbMock->expects($this->once())
            ->method('create')
            ->with('apps', self::callback(function (array $params): bool {
                return ($params['name'] ?? '') === 'TelemetryApp'
                    && is_string($params['api_key'] ?? null)
                    && strlen($params['api_key']) === 64;
            }))
            ->willReturn(5);

        $controller = new AppsController;
        try {
            $controller->store(['name' => 'TelemetryApp']);
        } catch (RedirectException $e) {
            self::assertSame('/admin/apps', $e->getUrl());
            $flash = is_array($_SESSION['app_flash'] ?? null) ? $_SESSION['app_flash'] : [];
            self::assertSame('success', $flash['type'] ?? '');
        }
    }

    public function test_update_regenerate_key_and_delete(): void
    {
        $this->dbMock->expects($this->exactly(2))->method('update')->willReturn(1);
        $this->dbMock->expects($this->once())->method('delete')->with('apps', ['id' => 5])->willReturn(1);

        $controller = new AppsController;

        try {
            $controller->update(['name' => 'RenamedApp'], '5');
        } catch (RedirectException $e) {
            self::assertSame('/admin/apps', $e->getUrl());
        }

        try {
            $controller->regenerateKey('5');
        } catch (RedirectException $e) {
            self::assertSame('/admin/apps', $e->getUrl());
        }

        try {
            $controller->delete('5');
        } catch (RedirectException $e) {
            self::assertSame('/admin/apps', $e->getUrl());
        }
    }
}
