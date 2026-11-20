<?php

declare(strict_types=1);

namespace App\Core;

use App\Enums\LogLevel;
use eftec\bladeone\BladeOne;
use RuntimeException;
use Throwable;

/**
 * Template Engine Manager (View)
 *
 * Initializes and configures the BladeOne template engine to render Blade views.
 *
 * @category Core
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class View
{
    /** @var BladeOne|null The BladeOne template engine instance. */
    private static ?BladeOne $blade = null;

    /**
     * Returneaza instanta unica a motorului BladeOne.
     */
    public static function getEngine(): BladeOne
    {
        if (self::$blade === null) {
            $viewsDir = dirname(__DIR__, 2).'/resources/views';
            $cacheDir = dirname(__DIR__, 2).'/storage/views';

            if (! is_dir($viewsDir)) {
                mkdir($viewsDir, 0755, true);
            }

            if (! is_dir($cacheDir)) {
                mkdir($cacheDir, 0777, true);
            }

            // Retrieve view paths of active modules
            $viewsPaths = [$viewsDir];

            // @phpstan-ignore-next-line
            self::$blade = new BladeOne($viewsPaths, $cacheDir, BladeOne::MODE_AUTO);
        }

        return self::$blade;
    }

    /**
     * Resets the BladeOne template engine instance.
     */
    public static function resetEngine(): void
    {
        self::$blade = null;
    }

    /**
     * Clears all compiled Blade template views from storage.
     */
    public static function clearCompiledViews(): void
    {
        $cacheDir = dirname(__DIR__, 2).'/storage/views';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir.'/*.bladec');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    /**
     * Renders a Blade template and returns generated content.
     *
     * @param  string  $view  The template name.
     * @param  array<string, mixed>  $data  The parameters passed to the view.
     *
     * @throws RuntimeException If rendering fails.
     */
    public static function render(string $view, array $data = []): string
    {
        try {
            // Transmit errors and old session inputs
            $data['errors'] = $data['errors'] ?? $_SESSION['errors'] ?? [];
            $data['old'] = $data['old'] ?? $_SESSION['old'] ?? [];

            // Single consumption of errors and old session inputs
            unset($_SESSION['errors'], $_SESSION['old']);

            return self::getEngine()->run($view, $data);
        } catch (Throwable $e) {
            // Auto-heal: If rendering fails (e.g. from an outdated compiled cache calling legacy functions),
            // attempt a forced recompilation once by deleting the stale compiled file.
            try {
                $engine = self::getEngine();
                $compiledFile = $engine->getCompiledFile($view);
                if (is_file($compiledFile)) {
                    @unlink($compiledFile);
                    $engine->compile($view, true);

                    return $engine->run($view, $data);
                }
            } catch (Throwable) {
                // Fall through to standard error handling and logging below
            }

            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, "Error rendering view {$view}", [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'View_Render_Failure',
                    'view_name' => $view,
                ]);
            }
            throw new RuntimeException(__('Error rendering view :view: :error', ['view' => $view, 'error' => $e->getMessage()]), 500, $e);
        }
    }
}
