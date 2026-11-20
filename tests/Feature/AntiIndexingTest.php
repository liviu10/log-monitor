<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Router;
use App\Exceptions\RedirectException;
use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Anti-Indexing & SEO/AI Crawler Protection Verification
 *
 * Ensures LogMonitor exposes zero SEO/AI indexing surfaces and emits strict noindex headers/meta.
 */
class AntiIndexingTest extends TestCase
{
    private Router $router;

    /** @var array<int|string, mixed> */
    private array $originalSession;

    /** @var array<int|string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSession = $_SESSION ?? [];
        $this->originalServer = $_SERVER;
        $_SESSION = [];

        $this->router = new Router;
        /** @var callable(Router): void $routes */
        $routes = require dirname(__DIR__, 2).'/routes/web.php';
        $routes($this->router);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function test_robots_txt_blocks_all_crawlers_and_has_no_sitemap(): void
    {
        $robots = file_get_contents(dirname(__DIR__, 2).'/public/robots.txt');
        self::assertIsString($robots);
        self::assertStringContainsString('User-agent: *', $robots);
        self::assertStringContainsString('Disallow: /', $robots);
        self::assertStringNotContainsString('Sitemap:', $robots);
    }

    public function test_ai_txt_blocks_all_ai_crawlers(): void
    {
        $aiTxt = file_get_contents(dirname(__DIR__, 2).'/public/ai.txt');
        self::assertIsString($aiTxt);
        self::assertStringContainsString('AI.TXT', $aiTxt);
        self::assertStringContainsString('User-agent: *', $aiTxt);
        self::assertStringContainsString('Disallow: /', $aiTxt);
    }

    public function test_head_component_contains_strict_noindex_noai_meta_tags(): void
    {
        $head = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/head.blade.php');
        self::assertIsString($head);
        self::assertStringContainsString('noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai', $head);
        self::assertStringContainsString('name="googlebot"', $head);
        self::assertStringContainsString('name="bingbot"', $head);
    }

    public function test_caddyfile_emits_global_x_robots_tag_and_blocks_seo_paths(): void
    {
        $caddy = file_get_contents(dirname(__DIR__, 2).'/docker/Caddyfile');
        self::assertIsString($caddy);
        self::assertStringContainsString('X-Robots-Tag "noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai"', $caddy);
        self::assertStringContainsString('/sitemap.xml', $caddy);
        self::assertStringContainsString('/llms.txt', $caddy);
    }

    public function test_security_headers_middleware_source_emits_x_robots_tag(): void
    {
        $middleware = file_get_contents(dirname(__DIR__, 2).'/src/Middleware/SecurityHeadersMiddleware.php');
        self::assertIsString($middleware);
        self::assertStringContainsString('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai', $middleware);
        self::assertTrue(class_exists(SecurityHeadersMiddleware::class));
    }

    public function test_sitemap_and_llms_routes_are_not_registered(): void
    {
        foreach (['/sitemap.xml', '/llms.txt', '/llms-full.txt'] as $path) {
            $threw404 = false;
            try {
                $this->router->dispatch('GET', $path);
            } catch (\RuntimeException $e) {
                $threw404 = ($e->getMessage() === 'Page not found.');
            }
            self::assertTrue($threw404, "Expected 404 RuntimeException for {$path}");
        }
    }

    public function test_root_redirects_guest_to_login_and_auth_to_admin(): void
    {
        try {
            $this->router->dispatch('GET', '/');
            self::fail('Expected redirect to /login');
        } catch (RedirectException $e) {
            self::assertSame('/login', $e->getUrl());
        }

        $_SESSION['auth.user'] = ['id' => 1, 'role_name' => 'ADMIN'];

        try {
            $this->router->dispatch('GET', '/');
            self::fail('Expected redirect to /admin');
        } catch (RedirectException $e) {
            self::assertSame('/admin', $e->getUrl());
        }
    }
}
