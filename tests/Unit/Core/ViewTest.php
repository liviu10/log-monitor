<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\View;
use eftec\bladeone\BladeOne;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Class ViewTest
 *
 * Unit tests for the View Core class.
 * Uses Reflection to inject a mocked BladeOne engine to avoid physical file dependencies.
 *
 * @category Tests
 */
class ViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        // Reset the static blade instance to avoid side-effects between tests
        $this->resetBladeInstance(null);
        parent::tearDown();
    }

    private function resetBladeInstance(?BladeOne $instance): void
    {
        $reflection = new ReflectionClass(View::class);
        $bladeProp = $reflection->getProperty('blade');
        $bladeProp->setValue(null, $instance);
    }

    public function test_get_engine_returns_blade_one_instance(): void
    {
        $this->resetBladeInstance(null);

        $engine = View::getEngine();
        self::assertInstanceOf(BladeOne::class, $engine);

        // Successive calls should return the same instance
        $engine2 = View::getEngine();
        self::assertSame($engine, $engine2);
    }

    public function test_render_successful_execution_and_session_flushing(): void
    {
        /** @var BladeOne&MockObject $bladeMock */
        $bladeMock = $this->createMock(BladeOne::class);

        // Setup session data
        $_SESSION['errors'] = ['email' => 'Invalid email format'];
        $_SESSION['old'] = ['email' => 'wrong@'];

        // We expect run to receive the merged data
        $bladeMock->expects($this->once())
            ->method('run')
            ->with(
                'home.index',
                self::callback(function (mixed $data): bool {
                    if (! is_array($data)) {
                        return false;
                    }

                    return ($data['title'] ?? null) === 'Home'
                        && ($data['errors'] ?? null) === ['email' => 'Invalid email format']
                        && ($data['old'] ?? null) === ['email' => 'wrong@'];
                })
            )
            ->willReturn('<html>Rendered</html>');

        $this->resetBladeInstance($bladeMock);

        $result = View::render('home.index', ['title' => 'Home']);

        self::assertEquals('<html>Rendered</html>', $result);

        // Ensure session data was flushed
        self::assertArrayNotHasKey('errors', $_SESSION);
        self::assertArrayNotHasKey('old', $_SESSION);
    }

    public function test_render_throws_runtime_exception_on_engine_failure(): void
    {
        /** @var BladeOne&MockObject $bladeMock */
        $bladeMock = $this->createMock(BladeOne::class);

        $bladeMock->expects($this->once())
            ->method('run')
            ->willThrowException(new \Exception('Template missing'));

        $this->resetBladeInstance($bladeMock);

        $this->expectException(RuntimeException::class);
        // Translation string fallback or original message in exception

        View::render('missing.view');
    }

    public function test_get_engine_creates_missing_directories(): void
    {
        $this->resetBladeInstance(null);
        $viewsDir = dirname(__DIR__, 3).'/resources/views';
        $cacheDir = dirname(__DIR__, 3).'/storage/views';
        $backupViewsDir = dirname(__DIR__, 3).'/resources/views_backup_test';
        $backupCacheDir = dirname(__DIR__, 3).'/storage/views_backup_test';

        if (file_exists($viewsDir)) {
            rename($viewsDir, $backupViewsDir);
        }
        if (file_exists($cacheDir)) {
            rename($cacheDir, $backupCacheDir);
        }

        try {
            $engine = View::getEngine();
            self::assertInstanceOf(BladeOne::class, $engine);
            self::assertDirectoryExists($viewsDir);
            self::assertDirectoryExists($cacheDir);
        } finally {
            if (file_exists($backupViewsDir)) {
                if (file_exists($viewsDir)) {
                    $files = glob($viewsDir.'/*');
                    if (is_array($files)) {
                        foreach ($files as $f) {
                            if (is_file($f)) {
                                unlink($f);
                            }
                        }
                    }
                    rmdir($viewsDir);
                }
                rename($backupViewsDir, $viewsDir);
            }
            if (file_exists($backupCacheDir)) {
                if (file_exists($cacheDir)) {
                    $files = glob($cacheDir.'/*');
                    if (is_array($files)) {
                        foreach ($files as $f) {
                            if (is_file($f)) {
                                unlink($f);
                            }
                        }
                    }
                    rmdir($cacheDir);
                }
                rename($backupCacheDir, $cacheDir);
            }
        }
    }

    public function test_clear_compiled_views(): void
    {
        $cacheDir = dirname(__DIR__, 3).'/storage/views';
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $dummyFile = $cacheDir.'/test_dummy_view.bladec';
        file_put_contents($dummyFile, '<?php echo "test"; ?>');
        self::assertFileExists($dummyFile);

        View::clearCompiledViews();

        self::assertFileDoesNotExist($dummyFile);
    }

    public function test_render_auto_healing_recompiles_stale_view(): void
    {
        $cacheDir = dirname(__DIR__, 3).'/storage/views';
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $dummyFile = $cacheDir.'/stale_view.bladec';
        file_put_contents($dummyFile, '<?php throw new \Exception("Stale function"); ?>');

        /** @var BladeOne&MockObject $bladeMock */
        $bladeMock = $this->createMock(BladeOne::class);

        $bladeMock->expects($this->exactly(2))
            ->method('run')
            ->willReturnCallback(function () {
                /** @var int $call */
                static $call = 0;
                $call++;
                if ($call === 1) {
                    throw new \Exception('Call to undefined function Safe\date()');
                }

                return '<html>Clean Render</html>';
            });

        $bladeMock->method('getCompiledFile')->willReturn($dummyFile);
        $bladeMock->expects($this->once())->method('compile')->with('stale.view', true)->willReturn(true);

        $this->resetBladeInstance($bladeMock);

        $result = View::render('stale.view');

        self::assertEquals('<html>Clean Render</html>', $result);
        self::assertFileDoesNotExist($dummyFile);
    }

    public function test_render_preserves_page_title_across_child_includes(): void
    {
        View::resetEngine();
        $_SESSION['lang'] = 'en';

        $logsHtml = View::render('admin.logs.index', [
            'title' => 'Logs',
            'logs' => [],
            'apps' => [],
            'levels' => [],
            'filters' => [],
            'page' => 1,
            'totalLogs' => 0,
            'totalPages' => 1,
            'stats' => [],
            'limit' => 10,
            'sortBy' => 'id',
            'sortDir' => 'DESC',
        ]);
        self::assertStringContainsString('<title>Logs - LogMonitor</title>', $logsHtml);

        $appsHtml = View::render('admin.apps.index', [
            'apps' => [],
        ]);
        self::assertStringContainsString('<title>Applications - LogMonitor</title>', $appsHtml);
    }
}
