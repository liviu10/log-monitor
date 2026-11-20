<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\DashboardStatsScanner;
use App\Core\MySQLWrapper;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DummyStatsModel
{
    /** @return array<string, mixed> */
    public static function dashboardSummarySingle(): array
    {
        return ['title' => 'Single Metric', 'value' => 10];
    }

    public static function dashboardSummaryNonArray(): string
    {
        return 'not an array';
    }

    /** @return array<string, mixed> */
    public static function dashboardSummaryThrow(): array
    {
        throw new \RuntimeException('Method failure');
    }

    /** @return array<string, mixed> */
    public static function dashboardChartSingle(): array
    {
        return [
            'type' => 'bar',
            'title' => 'Sales Chart',
            'labels' => ['Jan', 'Feb'],
            'datasets' => [
                ['label' => 'Dataset 1', 'data' => [10, 20]],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function dashboardChartMultiple(): array
    {
        return [
            [
                'type' => 'line',
                'title' => 'Visitors Chart',
                'labels' => ['Mon', 'Tue'],
                'datasets' => [
                    ['label' => 'Dataset 2', 'data' => [100, 200]],
                ],
            ],
        ];
    }
}

class DummyStatsApiController
{
    /** @return array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>} */
    public static function getStatsData(): array
    {
        return [
            'summaries' => [
                ['title' => 'Contact Messages', 'value' => 5],
            ],
            'charts' => [
                [
                    'type' => 'doughnut',
                    'title' => 'Messages by Subject',
                    'labels' => ['General', 'Support'],
                    'datasets' => [
                        ['label' => 'Messages', 'data' => [3, 2]],
                    ],
                ],
            ],
        ];
    }
}

/**
 * Class DashboardStatsScannerTest
 *
 * Unit tests for the dynamic dashboard statistics scanner and caching system.
 *
 * @category Tests
 */
class DashboardStatsScannerTest extends TestCase
{
    /** @var MySQLWrapper&MockObject */
    private MySQLWrapper $dbMock;

    private string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(MySQLWrapper::class);
        $this->cachePath = \storage_path('cache').'/dashboard_stats.json';

        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }

        $_SESSION['app_lang'] = 'en';
        \config('::reset');
        $unused = \__('::reset');
        unset($unused);

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $this->dbMock);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, null);

        parent::tearDown();
    }

    private function configureDefaultDbMock(): void
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        $stmtMock->method('fetch')->willReturn(['total' => '15']);
        $stmtMock->method('fetchAll')->willReturn([
            ['role_name' => 'USER', 'count' => '10', 'language' => 'ro'],
            ['visibility' => 'Published', 'count' => '5', 'language' => 'en'],
        ]);

        $this->dbMock->method('query')->willReturn($stmtMock);
    }

    public function test_scan_aggregates_summaries_and_charts_from_models(): void
    {
        $this->configureDefaultDbMock();

        $scanner = new DashboardStatsScanner;
        $stats = $scanner->scan();

        self::assertArrayHasKey('summaries', $stats);
        self::assertArrayHasKey('charts', $stats);
        self::assertArrayHasKey('cached_at', $stats);
        self::assertNotEmpty($stats['summaries']);
        self::assertNotEmpty($stats['charts']);

        $firstSummary = $stats['summaries'][0];
        self::assertArrayHasKey('title', $firstSummary);
        self::assertArrayHasKey('value', $firstSummary);
    }

    public function test_get_stats_creates_cache_file_and_serves_from_disk_on_subsequent_call(): void
    {
        $this->configureDefaultDbMock();

        self::assertFalse(file_exists($this->cachePath));

        $scanner = new DashboardStatsScanner;
        $statsFirst = $scanner->getStats(false);
        self::assertTrue(file_exists($this->cachePath));

        // Create a strict new mock that expects ZERO queries on second getStats() call
        $secondDbMock = $this->createMock(MySQLWrapper::class);
        $secondDbMock->expects($this->never())->method('query');

        $reflection = new ReflectionClass(MySQLWrapper::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setValue(null, $secondDbMock);

        $statsSecond = $scanner->getStats(false);
        self::assertEquals($statsFirst['cached_at'], $statsSecond['cached_at']);
        self::assertNotEmpty($statsSecond['summaries']);
    }

    public function test_get_stats_with_force_refresh_invalidates_cache_and_recomputes(): void
    {
        $this->configureDefaultDbMock();

        // Populate cache initially
        $scanner = new DashboardStatsScanner;
        $initialStats = $scanner->getStats(false);
        self::assertTrue(file_exists($this->cachePath));

        // Force recompute
        $refreshedStats = $scanner->getStats(true);

        self::assertNotEmpty($refreshedStats['summaries']);
        self::assertTrue(file_exists($this->cachePath));
    }

    public function test_translate_stats_processes_labels_at_runtime(): void
    {
        $this->configureDefaultDbMock();

        $scanner = new DashboardStatsScanner;
        $stats = $scanner->getStats(false);

        // Verify that charts labels exist and have strings as values
        foreach ($stats['charts'] as $chart) {
            self::assertArrayHasKey('labels', $chart);
            self::assertIsArray($chart['labels']);
            foreach ($chart['labels'] as $label) {
                self::assertIsString($label);
            }
        }
    }

    public function test_get_stats_preserves_existing_session_language(): void
    {
        $this->configureDefaultDbMock();

        $_SESSION['app_lang'] = 'ro';
        try {
            $scanner = new DashboardStatsScanner;
            $stats = $scanner->getStats(true);
            self::assertNotEmpty($stats['summaries']);
            self::assertEquals('ro', $_SESSION['app_lang']);
        } finally {
            unset($_SESSION['app_lang']);
        }
    }

    public function test_scan_discovers_core_statistical_models(): void
    {
        $this->configureDefaultDbMock();

        $scanner = new DashboardStatsScanner;
        $stats = $scanner->scan();

        $titles = array_map(static fn (array $card): string => is_string($card['title'] ?? null) ? $card['title'] : '', $stats['summaries']);

        self::assertContains('Registered Users', $titles);
        self::assertContains('Active Apps', $titles);
        self::assertContains('Total Logs', $titles);
    }

    public function test_load_from_cache_handles_expired_cache(): void
    {
        $this->configureDefaultDbMock();

        $expiredData = [
            'summaries' => [['title' => 'Old Cache Item', 'value' => '99']],
            'charts' => [],
            'cached_at' => time() - 7200,
        ];
        file_put_contents($this->cachePath, json_encode($expiredData));
        touch($this->cachePath, time() - 7200);

        $scanner = new DashboardStatsScanner;
        $stats = $scanner->getStats(false);
        $titles = array_map(static fn (array $card): string => is_string($card['title'] ?? null) ? $card['title'] : '', $stats['summaries']);
        self::assertNotContains('Old Cache Item', $titles);
        self::assertContains('Registered Users', $titles);
    }

    public function test_load_from_cache_handles_corrupt_or_malformed_json(): void
    {
        $this->configureDefaultDbMock();

        $scanner = new DashboardStatsScanner;

        file_put_contents($this->cachePath, '{"invalid_json');
        $statsInvalid = $scanner->getStats(false);
        self::assertNotEmpty($statsInvalid['summaries']);

        file_put_contents($this->cachePath, json_encode(['foo' => 'bar']));
        $statsMissingKeys = $scanner->getStats(false);
        self::assertNotEmpty($statsMissingKeys['summaries']);

        file_put_contents($this->cachePath, json_encode([
            'summaries' => 'not_an_array',
            'charts' => 'not_an_array',
            'cached_at' => time(),
        ]));
        $statsInvalidTypes = $scanner->getStats(false);
        self::assertNotEmpty($statsInvalidTypes['summaries']);
    }

    public function test_scan_handles_no_summaries_gracefully(): void
    {
        $this->configureDefaultDbMock();
        $scanner = new DashboardStatsScanner;
        $stats = $scanner->scan();
        self::assertNotEmpty($stats['summaries']);
    }

    public function test_scan_with_custom_file_scanner_and_class_resolver(): void
    {
        $this->configureDefaultDbMock();

        $fileScanner = fn (string $dir): array => ['/virtual/DummyStatsModel.php'];
        $classResolver = fn (string $filePath): string => DummyStatsModel::class;

        $scanner = new DashboardStatsScanner($fileScanner, $classResolver);
        $stats = $scanner->scan();

        self::assertNotEmpty($stats['summaries']);
        self::assertNotEmpty($stats['charts']);

        $titles = array_map(static fn (array $c): string => is_string($c['title'] ?? null) ? $c['title'] : '', $stats['summaries']);
        self::assertContains('Single Metric', $titles);
    }

    public function test_scan_handles_file_scanner_exception(): void
    {
        $fileScanner = function (string $dir): array {
            throw new \RuntimeException('File scanner failure');
        };

        $scanner = new DashboardStatsScanner($fileScanner);
        $stats = $scanner->scan();

        self::assertEmpty($stats['summaries']);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withExceptionHandler(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    public function test_ensure_directory_exists_logs_exception_on_failure(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('ensureDirectoryExists');

        $this->withExceptionHandler(function () use ($method, $scanner) {
            $method->invoke($scanner, '/dev/null/forbidden/cache');
        });
        $this->expectNotToPerformAssertions();
    }

    public function test_remove_old_cache_logs_exception_on_failure(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('removeOldCache');

        $this->withExceptionHandler(function () use ($method, $scanner) {
            $method->invoke($scanner, sys_get_temp_dir());
        });
        $this->expectNotToPerformAssertions();
    }

    public function test_write_to_cache_logs_exception_on_failure(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('writeToCache');

        $this->withExceptionHandler(function () use ($method, $scanner) {
            $method->invoke($scanner, '/dev/null/forbidden/test.json', ['summaries' => [], 'charts' => [], 'cached_at' => time()]);
        });
        $this->expectNotToPerformAssertions();
    }

    public function test_load_from_cache_logs_exception_on_failure(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('loadFromCache');

        $result = $this->withExceptionHandler(function () use ($method, $scanner) {
            return $method->invoke($scanner, sys_get_temp_dir(), 3600);
        });
        self::assertNull($result);
    }

    public function test_scan_class_methods_catches_reflection_exception(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('scanClassMethods');

        $summaries = [];
        $charts = [];
        // Class does not exist, so new ReflectionClass throws ReflectionException
        $method->invokeArgs($scanner, ['NonExistentClassForReflectionDiscovery', &$summaries, &$charts]);

        self::assertEmpty($summaries);
        self::assertEmpty($charts);
    }

    public function test_get_class_name_from_file_edge_cases(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('getClassNameFromFile');

        // Non-existent path returns null
        self::assertNull($method->invoke($scanner, '/non/existent/file.php'));

        // Path outside of src/ returns null
        self::assertNull($method->invoke($scanner, __FILE__));

        // Path inside src/
        $coreModelPath = realpath(dirname(__DIR__, 2).'/src/Models/User.php');
        if (is_string($coreModelPath)) {
            self::assertSame('App\Models\User', $method->invoke($scanner, $coreModelPath));
        }
    }

    public function test_scan_directory_returns_early_if_not_dir(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('scanDirectory');

        $summaries = [];
        $charts = [];
        $method->invokeArgs($scanner, ['/non/existent/directory', &$summaries, &$charts]);

        self::assertEmpty($summaries);
        self::assertEmpty($charts);
    }

    public function test_log_exception_directly(): void
    {
        $scanner = new DashboardStatsScanner;
        $reflection = new ReflectionClass(DashboardStatsScanner::class);
        $method = $reflection->getMethod('logException');

        $method->invoke(
            $scanner,
            new \RuntimeException('Test diagnostic failure'),
            'WARNING',
            'Test diagnostic error message',
            'Test_Identifier',
            __METHOD__,
            __LINE__,
            ['custom_key' => 'custom_val']
        );

        $this->expectNotToPerformAssertions();
    }
}
