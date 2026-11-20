<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Exceptions\RedirectException;
use App\Utilities\Breadcrumbs;
use PHPUnit\Framework\TestCase;

/**
 * Class HelpersTest
 *
 * @category Tests
 */
class HelpersTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $originalSession;

    private ?string $originalAppEnv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSession = $_SESSION ?? [];
        $originalAppEnv = $_ENV['APP_ENV'] ?? null;
        $this->originalAppEnv = is_string($originalAppEnv) ? $originalAppEnv : null;
        $_SESSION = [];

        // Reset config cache to ensure clean state
        if (function_exists('config')) {
            \config('::reset');
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        if ($this->originalAppEnv !== null) {
            $_ENV['APP_ENV'] = $this->originalAppEnv;
            $_SERVER['APP_ENV'] = $this->originalAppEnv;
            $_SERVER['APP_ENV'] = $this->originalAppEnv;
        } else {
            unset($_ENV['APP_ENV']);
            unset($_SERVER['APP_ENV']);

        }
        parent::tearDown();
    }

    public function test_construct_url_returns_valid_string(): void
    {
        $url = constructUrl();
        // It should start with http:// or https://
        self::assertMatchesRegularExpression('/^https?:\/\//', $url);
    }

    public function test_check_auth_user_returns_true_if_user_exists(): void
    {
        $_SESSION['auth.user'] = ['id' => 123];
        self::assertTrue(checkAuthUser());
    }

    public function test_check_auth_user_redirects_if_missing(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('/login');

        checkAuthUser();
    }

    public function test_get_current_auth_user(): void
    {
        self::assertNull(getCurrentAuthUser());

        $user = ['id' => 1, 'name' => 'Aegis'];
        $_SESSION['auth.user'] = $user;

        self::assertEquals($user, getCurrentAuthUser());
    }

    public function test_format_date(): void
    {
        self::assertEquals('15.10.2023', formatDate('2023-10-15'));

        // Invalid date string will fallback to current time, so we check it doesn't return empty string
        self::assertNotSame('', formatDate('invalid-date'));
    }

    public function test_has_permission_admin_bypass(): void
    {
        // Admin bypasses permission checks
        $_SESSION['auth.user'] = ['role_name' => 'ADMIN'];
        self::assertTrue(hasPermission('any.random.permission'));
        self::assertTrue(hasAnyPermission(['perm1', 'perm2']));
    }

    public function test_has_permission_no_role(): void
    {
        // No role ID means false before DB check
        $_SESSION['auth.user'] = ['role_id' => 0];
        self::assertFalse(hasPermission('some.perm'));
        self::assertFalse(hasAnyPermission(['perm1']));
    }

    public function test_has_permission_returns_false_when_user_is_not_logged_in(): void
    {
        $_SESSION['auth.user'] = null;
        self::assertFalse(hasPermission('admin.dashboard'));
    }

    public function test_csrf_helpers(): void
    {
        $token = csrf_token();
        self::assertNotSame('', $token);

        // generateCsrfToken should return the same token if one exists in session
        self::assertEquals($token, generateCsrfToken());

        // test csrf_field generation
        $field = csrf_field();
        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="csrf_token"', $field);
        self::assertStringContainsString($token, $field);

        // verify
        self::assertTrue(verifyCsrfToken($token));
        self::assertFalse(verifyCsrfToken('wrong-token'));
    }

    public function test_get_flash_returns_null_initially(): void
    {
        self::assertNull(getFlash());
    }

    public function test_flash_messages(): void
    {
        setFlash('success', 'Title', 'My Message', 5000, '/dashboard');

        $flash = getFlash();
        self::assertIsArray($flash);
        self::assertEquals('success', $flash['type']);
        self::assertEquals('Title', $flash['title']);
        self::assertEquals('My Message', $flash['message']);
        self::assertEquals(5000, $flash['toastDelay']);
        self::assertEquals('/dashboard', $flash['redirectUrl']);
    }

    public function test_get_flash_unsets_after_reading(): void
    {
        setFlash('success', 'Title', 'My Message', 5000, '/dashboard');
        getFlash();
        self::assertNull(getFlash());
    }

    public function test_get_lang(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // Default is 'en'
        self::assertEquals('en', getLang());

        // Test custom lang
        $_SESSION['app_lang'] = 'ro';
        self::assertEquals('ro', getLang());
    }

    public function test_supported_locales_helper(): void
    {
        $locales = supportedLocales();
        self::assertNotEmpty($locales);
        self::assertArrayHasKey('en', $locales);
        self::assertArrayHasKey('ro', $locales);

        // Custom override
        $custom = supportedLocales(['fr' => 'Français', 'de' => 'Deutsch']);
        self::assertEquals(['fr' => 'Français', 'de' => 'Deutsch'], $custom);

        // Fallback to app.locale when empty
        $fallback = supportedLocales([]);
        self::assertArrayHasKey('en', $fallback);
        self::assertEquals('EN', $fallback['en']);
    }

    public function test_translation_helper(): void
    {
        \__('::reset');
        // Test placeholder replacements
        self::assertEquals('Hello Liviu', \__('Hello :name', ['name' => 'Liviu']));

        // Test XSS prevention in translation replacements
        self::assertEquals(
            'Value: &lt;script&gt;alert(1)&lt;/script&gt;',
            \__('Value: :val', ['val' => '<script>alert(1)</script>'])
        );
    }

    public function test_redirect(): void
    {
        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('/admin');

        redirect('/admin');
    }

    public function test_old_helper(): void
    {
        // Missing key
        self::assertNull(old('email'));

        // Default value
        self::assertEquals('default@test.com', old('email', 'default@test.com'));

        // Existing key
        $_SESSION['old'] = ['email' => 'admin@aegis.local'];
        self::assertEquals('admin@aegis.local', old('email'));
    }

    public function test_storage_path(): void
    {
        $path = storage_path('logs/app.log');

        // Normalizes slashes for cross-platform checking
        $normalizedPath = str_replace('\\', '/', $path);

        self::assertStringContainsString('storage/logs/app.log', $normalizedPath);
    }

    public function test_config_helper(): void
    {
        // config cache was reset in setUp
        self::assertNull(\config('non_existent.key'));
        self::assertEquals('default_value', \config('non_existent.key', 'default_value'));
    }

    public function test_debug_production_throws_runtime_exception(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        // Prevent LogViaStream from attempting a network request which can freeze the test suite
        $_ENV['LOG_SERVER_URL'] = '';

        if (class_exists(LogViaStream::class)) {
            $reflection = new \ReflectionClass(LogViaStream::class);
            $urlProperty = $reflection->getProperty('url');
            $urlProperty->setValue(null, '');

            $apiKeyProperty = $reflection->getProperty('apiKey');
            $apiKeyProperty->setValue(null, '');
        }

        \config('::reset');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Internal Server Error');
        $this->expectExceptionCode(500);

        debug('test');
    }

    public function test_construct_url_with_invalid_host(): void
    {
        $originalHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'invalid_domain!@#';
        \config('::reset');

        $url = constructUrl();
        self::assertStringContainsString('localhost', $url);

        if ($originalHost !== null) {
            $_SERVER['HTTP_HOST'] = $originalHost;
        } else {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    public function test_has_permission_with_wildcards(): void
    {
        $_SESSION['auth.user'] = ['role_id' => 999, 'role_name' => 'USER'];

        $mockStatement = $this->createMock(\PDOStatement::class);

        $mockStatement->expects($this->exactly(3))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                [['permission_key' => 'admin.settings.users.index']],
                [['permission_key' => 'admin.settings.*']],
                [['permission_key' => '*']]
            );

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        $mockWrapper->method('query')->willReturn($mockStatement);

        $reflection = new \ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        // Call 1: Test wildcard suffix (user has 'admin.settings.users.index', we ask for 'admin.settings.*')
        self::assertTrue(hasPermission('admin.settings.*'));

        // Call 2: Test explicit wildcard (user has 'admin.settings.*', we ask for 'admin.settings.users.index')
        self::assertTrue(hasPermission('admin.settings.users.index'));

        // Call 3: Test global wildcard (user has '*', we ask for any permission)
        self::assertTrue(hasPermission('random.permission'));

        // Cleanup
        $instanceProp->setValue(null, null);
    }

    public function test_get_lang_no_session(): void
    {
        $status = session_status();
        if ($status === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        self::assertEquals('en', getLang());

        @session_start();
    }

    public function test_translation_helper_missing_file(): void
    {
        $_SESSION['app_lang'] = 'non_existent_lang';
        \__('::reset');

        // This should hit line 386 because lang/non_existent_lang.json doesn't exist
        self::assertEquals('test_key', __('test_key'));
    }

    public function test_translation_helper_invalid_json(): void
    {
        $invalidLangFile = dirname(__DIR__, 3).'/lang/invalid_test_lang.json';
        file_put_contents($invalidLangFile, 'invalid json content {');

        $_SESSION['app_lang'] = 'invalid_test_lang';
        \__('::reset');

        try {
            self::assertEquals('key', __('key'));
        } finally {
            if (file_exists($invalidLangFile)) {
                unlink($invalidLangFile);
            }
            \__('::reset');
        }
    }

    public function test_translation_helper_empty_string_falls_back_to_key(): void
    {
        $emptyLangFile = dirname(__DIR__, 3).'/lang/empty_test_lang.json';
        file_put_contents($emptyLangFile, (string) json_encode(['untranslated_key' => '']));

        $_SESSION['app_lang'] = 'empty_test_lang';
        \__('::reset');

        try {
            self::assertEquals('untranslated_key', __('untranslated_key'));
        } finally {
            if (file_exists($emptyLangFile)) {
                unlink($emptyLangFile);
            }
            \__('::reset');
        }
    }

    public function test_view_helper(): void
    {
        $this->expectException(\RuntimeException::class);
        // Assuming 'non_existent_view' view does not exist and engine throws exception
        view('non_existent_view');
    }

    public function test_breadcrumbs_helper(): void
    {
        Breadcrumbs::add('Home', '/');
        $html = breadcrumbs();
        self::assertStringContainsString('Home', $html);
    }

    public function test_has_permission_exact_match(): void
    {
        $_SESSION['auth.user'] = ['role_id' => 10, 'role_name' => 'EDITOR'];

        $mockStatement = $this->createMock(\PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            ['permission_key' => 'articles.edit'],
        ]);

        $mockWrapper = $this->getMockBuilder(MySQLWrapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();
        $mockWrapper->method('query')->willReturn($mockStatement);

        $reflection = new \ReflectionClass(MySQLWrapper::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $mockWrapper);

        self::assertTrue(hasPermission('articles.edit'));
        self::assertFalse(hasPermission('articles.delete'));

        $instanceProp->setValue(null, null);
    }

    public function test_helpers_file_re_inclusion_checks(): void
    {
        require dirname(__DIR__, 3).'/src/Utilities/helpers.php';
        self::assertTrue(function_exists('constructUrl'));
    }
}
