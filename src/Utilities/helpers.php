<?php

declare(strict_types=1);

/**
 * Global Helper Functions File
 *
 * Contains global utility functions and basic configuration for sessions and security.
 * All translation keys use English for consistency.
 */

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Core\View;
use App\Enums\LogLevel;
use App\Exceptions\RedirectException;
use App\Utilities\Breadcrumbs;
use App\Utilities\TokenManager;
use Symfony\Component\VarDumper\VarDumper;

if (! function_exists('debug')) {
    function debug(mixed ...$args): never
    {
        $appEnv = config('app.env') ?? 'development';
        if ($appEnv === 'production') {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send('CRITICAL', 'Leftover debug() call reached in production environment', [
                    'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
                ]);
            }
            throw new RuntimeException('Internal Server Error', 500);
        }

        // @codeCoverageIgnoreStart
        foreach ($args as $x) {
            VarDumper::dump($x);
        }
        exit(1);
        // @codeCoverageIgnoreEnd
    }
}

if (! function_exists('constructUrl')) {
    function constructUrl(): string
    {
        $rawHost = config('server.http_host') ?? 'localhost';
        $host = filter_var($rawHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
        if ($host === false) {
            $host = 'localhost';
        }

        $scriptName = config('server.script_name') ?? '';
        $scriptNameStr = is_string($scriptName) ? $scriptName : '';
        $scriptDir = str_replace('\\', '/', dirname($scriptNameStr));
        $basePath = rtrim($scriptDir, '/\\');

        $serverHttps = config('server.https');
        $isHttps = ($serverHttps !== null && $serverHttps !== '' && $serverHttps !== 'off') ||
                   ((config('server.server_port') ?? '') === 443) ||
                   ((config('server.http_x_forwarded_proto') ?? '') === 'https');

        $protocol = $isHttps ? 'https' : 'http';

        return "{$protocol}://{$host}{$basePath}";
    }
}

if (! function_exists('checkAuthUser')) {
    function checkAuthUser(): bool
    {
        if (isset($_SESSION['auth.user'])) {
            return true;
        }

        redirect('/login');
    }
}

if (! function_exists('getCurrentAuthUser')) {
    /**
     * @return array<string, mixed>|null
     */
    function getCurrentAuthUser(): ?array
    {
        $user = $_SESSION['auth.user'] ?? null;

        if (is_array($user)) {
            /** @var array<string, mixed> $user */
            return $user;
        }

        return null;
    }
}

if (! function_exists('formatDate')) {
    function formatDate(string $dateString): string
    {
        try {
            $timestamp = strtotime($dateString);
            $timestamp = is_int($timestamp) ? $timestamp : time();
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            $timestamp = time();
        }
        // @codeCoverageIgnoreEnd

        return date('d.m.Y', $timestamp);
    }
}

if (! function_exists('hasPermission')) {
    function hasPermission(string $permissionKey): bool
    {
        $user = $_SESSION['auth.user'] ?? null;
        if (! is_array($user)) {
            return false;
        }

        $roleNameVal = $user['role_name'] ?? '';
        $roleName = strtoupper(is_scalar($roleNameVal) ? (string) $roleNameVal : '');
        if ($roleName === 'ADMIN') {
            return true;
        }

        $roleIdVal = $user['role_id'] ?? 0;
        $roleId = is_numeric($roleIdVal) ? (int) $roleIdVal : 0;
        if ($roleId === 0) {
            return false;
        }

        /** @var array<string, bool>|null $permissionsCache */
        static $permissionsCache = null;

        if ($permissionsCache === null || defined('PHPUNIT_RUNNING')) {
            $db = MySQLWrapper::getInstance();
            $stmt = $db->query(
                'SELECT permission_key FROM role_permissions WHERE role_id = ? AND value = 1',
                [$roleId]
            );
            $rows = $stmt->fetchAll();
            $permissionsCache = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $k = $row['permission_key'] ?? '';
                    if (is_string($k) && $k !== '') {
                        $permissionsCache[strtolower($k)] = true;
                    }
                }
            }
        }

        $requiredKey = strtolower($permissionKey);

        if (isset($permissionsCache[$requiredKey])) {
            return true;
        }

        if (str_ends_with($requiredKey, '.*')) {
            $prefix = substr($requiredKey, 0, -1);
            foreach ($permissionsCache as $cachedKey => $val) {
                if (str_starts_with($cachedKey, $prefix)) {
                    return true;
                }
            }
        }

        $parts = explode('.', $requiredKey);
        $currentPath = '';
        foreach ($parts as $part) {
            $currentPath .= ($currentPath === '' ? '' : '.').$part;
            if (isset($permissionsCache[$currentPath.'.*'])) {
                return true;
            }
        }

        if (isset($permissionsCache['*'])) {
            return true;
        }

        return false;
    }
}

if (! function_exists('hasAnyPermission')) {
    /**
     * @param  array<int, string>  $keys
     */
    function hasAnyPermission(array $keys): bool
    {
        foreach ($keys as $key) {
            if (hasPermission($key)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('can')) {
    function can(string $permissionKey): bool
    {
        return hasPermission($permissionKey);
    }
}

if (! function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string
    {
        return TokenManager::generateCsrf();
    }
}

if (! function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return generateCsrfToken();
    }
}

if (! function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8').'">';
    }
}

if (! function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool
    {
        return TokenManager::verifyCsrf($token);
    }
}

if (! function_exists('setFlash')) {
    function setFlash(string $type, string $title, string $message, int $toastDelay = 10000, string $redirectUrl = ''): void
    {
        $_SESSION['app_flash'] = [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'toastDelay' => $toastDelay,
            'redirectUrl' => $redirectUrl,
        ];
    }
}

if (! function_exists('getFlash')) {
    /**
     * @return array{type: string, title: string, message: string, toastDelay: int, redirectUrl: string}|null
     */
    function getFlash(): ?array
    {
        if (isset($_SESSION['app_flash'])) {
            $flash = $_SESSION['app_flash'];
            unset($_SESSION['app_flash']);

            if (is_array($flash)) {
                /** @var array{type: string, title: string, message: string, toastDelay: int, redirectUrl: string} $flash */
                return $flash;
            }
        }

        return null;
    }
}

if (! function_exists('supportedLocales')) {
    /**
     * @param  array<string, string>|null  $customLocales
     * @return array<string, string>
     */
    function supportedLocales(?array $customLocales = null): array
    {
        $raw = $customLocales ?? (function_exists('config') ? config('app.supported_locales', [
            'en' => 'English',
            'ro' => 'Romana',
        ]) : [
            'en' => 'English',
            'ro' => 'Romana',
        ]);

        if (is_array($raw)) {
            /** @var array<string, string> $languages */
            $languages = [];
            foreach ($raw as $key => $val) {
                if (is_string($key) && is_string($val)) {
                    $languages[$key] = $val;
                }
            }
            if ($languages !== []) {
                return $languages;
            }
        }

        $fallback = function_exists('config') ? config('app.locale', 'en') : 'en';
        $fbCode = is_string($fallback) && $fallback !== '' ? $fallback : 'en';

        return [$fbCode => strtoupper($fbCode)];
    }
}

if (! function_exists('getLang')) {
    function getLang(): string
    {
        $default = function_exists('config') ? config('app.locale', 'en') : 'en';
        $fallback = is_string($default) && trim($default) !== '' ? $default : 'en';

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $fallback;
        }
        $appLang = $_SESSION['app_lang'] ?? $fallback;

        return is_string($appLang) && trim($appLang) !== '' ? $appLang : $fallback;
    }
}

if (! function_exists('__')) {
    /**
     * @param  array<string, mixed>  $replacements
     */
    function __(string $key, array $replacements = []): string
    {
        static $translations = null;

        if ($key === '::reset') {
            $translations = null;

            return '';
        }

        if ($translations === null) {
            $lang = getLang();
            $path = __DIR__."/../../lang/{$lang}.json";

            if (file_exists($path)) {
                try {
                    $content = file_get_contents($path);
                    if (is_string($content) && json_validate($content)) {
                        $decoded = json_decode($content, true);
                        $translations = is_array($decoded) ? $decoded : [];
                    } else {
                        $translations = [];
                    }
                    // @codeCoverageIgnoreStart
                } catch (Throwable) {
                    $translations = [];
                }
                // @codeCoverageIgnoreEnd
            } else {
                $translations = [];
            }
        }

        $translationsList = is_array($translations) ? $translations : [];
        $textVal = $translationsList[$key] ?? $key;
        $text = (is_string($textVal) && $textVal !== '') ? $textVal : $key;

        foreach ($replacements as $placeholder => $value) {
            $safeValue = htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES, 'UTF-8');
            $text = str_replace(':'.$placeholder, $safeValue, $text);
        }

        return $text;
    }
}

if (! function_exists('view')) {
    /**
     * @param  array<string, mixed>  $data
     */
    function view(string $view, array $data = []): string
    {
        return View::render($view, $data);
    }
}

if (! function_exists('redirect')) {
    function redirect(string $url): never
    {
        if (defined('PHPUNIT_RUNNING')) {
            throw new RedirectException($url);
        }
        // @codeCoverageIgnoreStart
        http_response_code(302);
        header("Location: {$url}");
        exit;
        // @codeCoverageIgnoreEnd
    }
}

if (! function_exists('old')) {
    function old(string $key, mixed $default = null): mixed
    {
        $oldData = $_SESSION['old'] ?? null;
        $oldDataList = is_array($oldData) ? $oldData : [];

        return $oldDataList[$key] ?? $default;
    }
}

if (! function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        /** @var array<string, mixed> $configs */
        static $configs = [];

        if ($key === '::reset') {
            $configs = [];

            return null;
        }

        $parts = explode('.', $key);
        $file = $parts[0];

        if (! isset($configs[$file])) {
            $filePath = dirname(__DIR__, 2)."/config/{$file}.php";
            if (file_exists($filePath)) {
                $loaded = require $filePath;
                $configs[$file] = is_array($loaded) ? $loaded : [];
            } else {
                $configs[$file] = [];
            }
        }

        $value = $configs[$file];
        $totalParts = count($parts);
        for ($i = 1; $i < $totalParts; $i++) {
            if (is_array($value) && array_key_exists($parts[$i], $value)) {
                $value = $value[$parts[$i]];
            } else {
                return $default;
            }
        }

        return $value;
    }
}

if (! function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        try {
            $base = realpath(__DIR__.'/../../storage');
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            $base = __DIR__.'/../../storage';
        }
        // @codeCoverageIgnoreEnd

        return $base.($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}

if (! function_exists('breadcrumbs')) {
    function breadcrumbs(): string
    {
        return Breadcrumbs::getInstance()->render();
    }
}
