<?php

declare(strict_types=1);

namespace App\Core;

use App\Enums\LogLevel;
use App\Utilities\DirectoryScanner;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Dashboard Stats Scanner Utility
 *
 * Scans core models using Reflection API for dashboardSummary* and dashboardChart* methods
 * and caches the consolidated JSON result.
 */
class DashboardStatsScanner
{
    /** @var callable|null */
    private $fileScanner;

    /** @var callable|null */
    private $classResolver;

    public function __construct(?callable $fileScanner = null, ?callable $classResolver = null)
    {
        $this->fileScanner = $fileScanner;
        $this->classResolver = $classResolver;
    }

    /**
     * Retrieves aggregated dashboard statistics from cache or rebuilds cache if expired or forced.
     *
     * @return array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, cached_at: int}
     */
    public function getStats(bool $forceRefresh = false): array
    {
        $cacheDir = \storage_path('cache');
        $this->ensureDirectoryExists($cacheDir);

        $cachePath = $cacheDir.'/dashboard_stats.json';
        $ttlRaw = \config('app.dashboard_stats_cache_ttl', 15);
        $ttl = is_numeric($ttlRaw) ? (int) $ttlRaw : 15;

        if (! $forceRefresh) {
            $cached = $this->loadFromCache($cachePath, $ttl);
            if ($cached !== null) {
                return $this->translateStats($cached);
            }
        }

        $this->removeOldCache($cachePath);

        $previousLang = $_SESSION['app_lang'] ?? null;
        $defaultLang = function_exists('config') ? config('app.fallback_locale', config('app.locale', 'en')) : 'en';
        $_SESSION['app_lang'] = is_string($defaultLang) && $defaultLang !== '' ? $defaultLang : 'en';
        __('::reset');

        try {
            $stats = $this->scan();
        } finally {
            if ($previousLang !== null) {
                $_SESSION['app_lang'] = $previousLang;
            } else {
                unset($_SESSION['app_lang']);
            }
            __('::reset');
        }

        $this->writeToCache($cachePath, $stats);

        return $this->translateStats($stats);
    }

    /**
     * Dynamically translates titles, labels, period labels, breakdown keys, and dataset names.
     *
     * @param  array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, cached_at: int}  $stats
     * @return array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, cached_at: int}
     */
    private function translateStats(array $stats): array
    {
        foreach ($stats['summaries'] as &$summary) {
            if (isset($summary['title']) && is_string($summary['title'])) {
                $summary['title'] = __($summary['title']);
            }
            if (isset($summary['breakdown']) && is_array($summary['breakdown'])) {
                $translatedBreakdown = [];
                foreach ($summary['breakdown'] as $bKey => $bVal) {
                    $translatedKey = is_string($bKey) ? __($bKey) : (string) $bKey;
                    $translatedBreakdown[$translatedKey] = $bVal;
                }
                $summary['breakdown'] = $translatedBreakdown;
            }
        }
        unset($summary);

        foreach ($stats['charts'] as &$chart) {
            if (isset($chart['title']) && is_string($chart['title'])) {
                $chart['title'] = __($chart['title']);
            }
            if (isset($chart['labels']) && is_array($chart['labels'])) {
                $chart['labels'] = array_map(static fn (mixed $label): string => is_string($label) ? __($label) : (is_scalar($label) ? (string) $label : ''), $chart['labels']);
            }
            if (isset($chart['periods']) && is_array($chart['periods'])) {
                foreach ($chart['periods'] as &$period) {
                    if (is_array($period)) {
                        if (isset($period['label']) && is_string($period['label'])) {
                            $period['label'] = __($period['label']);
                        }
                        if (isset($period['labels']) && is_array($period['labels'])) {
                            $period['labels'] = array_map(static fn (mixed $label): string => is_string($label) ? __($label) : (is_scalar($label) ? (string) $label : ''), $period['labels']);
                        }
                    }
                }
                unset($period);
            }
            if (isset($chart['datasets']) && is_array($chart['datasets'])) {
                foreach ($chart['datasets'] as &$dataset) {
                    if (is_array($dataset) && isset($dataset['label']) && is_string($dataset['label'])) {
                        $dataset['label'] = __($dataset['label']);
                    }
                }
                unset($dataset);
            }
        }
        unset($chart);

        return $stats;
    }

    /**
     * Scans core model directories via Reflection and aggregates metrics into buckets.
     *
     * @return array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, cached_at: int}
     */
    public function scan(): array
    {
        /** @var array<int, array<string, mixed>> $summaries */
        $summaries = [];
        /** @var array<int, array<string, mixed>> $charts */
        $charts = [];

        $directories = $this->getTargetDirectories();

        foreach ($directories as $dirPath) {
            $this->scanDirectory($dirPath, $summaries, $charts);
        }

        return [
            'summaries' => $summaries,
            'charts' => $charts,
            'cached_at' => time(),
        ];
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if (! is_dir($dir)) {
            try {
                mkdir($dir, 0755, true);
            } catch (Throwable $e) {
                $this->logException($e, LogLevel::ERROR->value, 'An exception occurred while creating dashboard statistics cache directory.', 'DashboardStats_Directory_Create_Failure', __METHOD__, __LINE__, [
                    'directory' => $dir,
                ]);
            }
        }
    }

    /**
     * @return array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, cached_at: int}|null
     */
    private function loadFromCache(string $path, int $ttl): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        try {
            $fileTimestamp = filemtime($path);
            if ($fileTimestamp === false || (time() - $fileTimestamp) >= $ttl) {
                return null;
            }

            $content = file_get_contents($path);
            if (! is_string($content) || ! json_validate($content)) {
                return null;
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded) || ! isset($decoded['summaries'], $decoded['charts'], $decoded['cached_at'])) {
                return null;
            }

            if (! is_array($decoded['summaries']) || ! is_array($decoded['charts'])) {
                return null;
            }

            /** @var array<int, array<string, mixed>> $summaries */
            $summaries = $decoded['summaries'];
            /** @var array<int, array<string, mixed>> $charts */
            $charts = $decoded['charts'];
            $cachedAtRaw = $decoded['cached_at'];
            $cachedAt = is_numeric($cachedAtRaw) ? (int) $cachedAtRaw : $fileTimestamp;

            return [
                'summaries' => $summaries,
                'charts' => $charts,
                'cached_at' => $cachedAt,
            ];
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::WARNING->value, 'An exception occurred while reading or parsing the dashboard statistics cache file.', 'DashboardStats_Cache_Read_Failure', __METHOD__, __LINE__, [
                'cache_path' => $path,
                'ttl' => $ttl,
            ]);

            return null;
        }
    }

    private function removeOldCache(string $path): void
    {
        if (file_exists($path)) {
            try {
                unlink($path);
            } catch (Throwable $e) {
                $this->logException($e, LogLevel::WARNING->value, 'An exception occurred while removing the outdated dashboard statistics cache file.', 'DashboardStats_Cache_Delete_Failure', __METHOD__, __LINE__, [
                    'cache_path' => $path,
                ]);
            }
        }
    }

    /**
     * @param  array{summaries: array<int, array<string, mixed>>, charts: array<int, array<string, mixed>>, cached_at: int}  $stats
     */
    private function writeToCache(string $path, array $stats): void
    {
        try {
            $jsonContent = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents($path, $jsonContent);
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::ERROR->value, 'An exception occurred while saving dashboard statistics JSON content to disk.', 'DashboardStats_Cache_Write_Failure', __METHOD__, __LINE__, [
                'cache_path' => $path,
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function getTargetDirectories(): array
    {
        $directories = [];
        $projectRoot = dirname(__DIR__, 2);

        try {
            $modelsDir = realpath($projectRoot.'/src/Models');
            if (is_string($modelsDir) && is_dir($modelsDir)) {
                $directories[] = $modelsDir;
            }
            // @codeCoverageIgnoreStart
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::WARNING->value, 'An exception occurred while resolving the core Models realpath.', 'DashboardStats_CoreModels_Resolve_Failure', __METHOD__, __LINE__, [
                'project_root' => $projectRoot,
            ]);
            if (is_dir($projectRoot.'/src/Models')) {
                $directories[] = $projectRoot.'/src/Models';
            }
        }
        // @codeCoverageIgnoreEnd

        return $directories;
    }

    /**
     * @param  array<int, array<string, mixed>>  &$summaries
     * @param  array<int, array<string, mixed>>  &$charts
     */
    private function scanDirectory(string $dirPath, array &$summaries, array &$charts): void
    {
        if (! is_dir($dirPath)) {
            return;
        }

        try {
            $files = $this->fileScanner !== null
                ? ($this->fileScanner)($dirPath)
                : DirectoryScanner::scan($dirPath, 'php');
            $files = is_array($files) ? $files : [];
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::WARNING->value, 'An exception occurred while instantiating recursive directory iterator for statistics scan.', 'DashboardStats_Directory_Iterator_Failure', __METHOD__, __LINE__, [
                'directory_path' => $dirPath,
            ]);

            return;
        }

        foreach ($files as $filePath) {
            if (! is_string($filePath)) {
                continue;
            }
            $className = $this->getClassNameFromFile($filePath);
            if ($className !== null && $className !== '' && class_exists($className)) {
                $this->scanClassMethods($className, $summaries, $charts);
            }
        }
    }

    /**
     * @param  class-string  $className
     * @param  array<int, array<string, mixed>>  &$summaries
     * @param  array<int, array<string, mixed>>  &$charts
     */
    private function scanClassMethods(string $className, array &$summaries, array &$charts): void
    {
        try {
            $reflection = new ReflectionClass($className);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() !== $className) {
                    continue;
                }

                $methodName = $method->getName();

                if (str_starts_with($methodName, 'dashboardSummary') || str_starts_with($methodName, 'getDashboardSummary')) {
                    $this->executeMethod($method, $summaries, true, $className);
                }

                if (str_starts_with($methodName, 'dashboardChart') || str_starts_with($methodName, 'getDashboardChart')) {
                    $this->executeMethod($method, $charts, false, $className);
                }
            }
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::DEBUG->value, 'An exception occurred while reflecting class structure during statistical methods discovery.', 'DashboardStats_Reflection_Failure', __METHOD__, __LINE__, [
                'class_name' => $className,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  &$bucket
     */
    private function executeMethod(ReflectionMethod $method, array &$bucket, bool $isSummary, string $className): void
    {
        try {
            $result = $method->invoke(null);
            if (! is_array($result)) {
                return;
            }

            $isSingleItem = $isSummary
                ? (isset($result['title']) || isset($result['value']))
                : (isset($result['type']) || isset($result['datasets']));

            if ($isSingleItem) {
                /** @var array<string, mixed> $result */
                $bucket[] = $result;
            } else {
                foreach ($result as $item) {
                    if (is_array($item)) {
                        /** @var array<string, mixed> $item */
                        $bucket[] = $item;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::ERROR->value, 'An exception occurred while executing static statistical reporting method on model.', 'DashboardStats_Method_Invoke_Failure', __METHOD__, __LINE__, [
                'class_name' => $className,
                'method_name' => $method->getName(),
            ]);
        }
    }

    private function getClassNameFromFile(string $filePath): ?string
    {
        if ($this->classResolver !== null) {
            $resolved = ($this->classResolver)($filePath);
            if ($resolved !== null) {
                return is_string($resolved) ? $resolved : null;
            }
        }

        try {
            $realFilePath = realpath($filePath);
            // @codeCoverageIgnoreStart
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::WARNING->value, 'An exception occurred while computing realpath of PHP file during class name extraction.', 'DashboardStats_FilePath_Resolve_Failure', __METHOD__, __LINE__, [
                'file_path' => $filePath,
            ]);
            $realFilePath = $filePath;
        }
        // @codeCoverageIgnoreEnd

        if (! is_string($realFilePath) || $realFilePath === '') {
            return null;
        }

        $projectRoot = dirname(__DIR__, 2);
        try {
            $realRoot = realpath($projectRoot);
            // @codeCoverageIgnoreStart
        } catch (Throwable $e) {
            $this->logException($e, LogLevel::WARNING->value, 'An exception occurred while computing realpath of project root during class name extraction.', 'DashboardStats_RootPath_Resolve_Failure', __METHOD__, __LINE__, [
                'project_root' => $projectRoot,
            ]);
            $realRoot = $projectRoot;
        }
        // @codeCoverageIgnoreEnd
        $rootPath = is_string($realRoot) ? $realRoot : $projectRoot;

        $srcDir = $rootPath.'/src';
        if (str_starts_with($realFilePath, $srcDir)) {
            $relative = substr($realFilePath, strlen($srcDir));
            $relative = ltrim($relative, DIRECTORY_SEPARATOR);

            return 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logException(
        Throwable $exception,
        string $level,
        string $message,
        string $identifier,
        string $method,
        int $line,
        array $context = []
    ): void {
        if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
            $logData = array_merge([
                'location' => $method,
                'line' => $line,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception_trace' => $exception->getTraceAsString(),
                'identifier' => $identifier,
            ], $context);

            LogViaStream::send($level, $message, $logData);
        }
    }
}
