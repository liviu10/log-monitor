<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Core\Router;
use App\Utilities\Breadcrumbs;
use PHPUnit\Framework\TestCase;

class BreadcrumbsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset Breadcrumbs singleton state before each test
        Breadcrumbs::reset();
        // Initialize Router singleton to prevent null pointer issues in routeExists
        Router::$instance = new Router;
    }

    protected function tearDown(): void
    {
        Breadcrumbs::reset();
        Router::$instance = null;
        parent::tearDown();
    }

    /**
     * Tests that getInstance returns the same instance (Singleton pattern).
     */
    public function test_singleton_returns_same_instance(): void
    {
        $instance1 = Breadcrumbs::getInstance();
        $instance2 = Breadcrumbs::getInstance();

        self::assertSame($instance1, $instance2);
    }

    /**
     * Tests that reset() clears all stored segments.
     */
    public function test_reset_clears_segments(): void
    {
        Breadcrumbs::add('Home', '/');
        Breadcrumbs::reset();

        $html = Breadcrumbs::getInstance()->render();
        self::assertEmpty($html);
    }

    /**
     * Tests that add() returns the instance to allow fluent API usage.
     */
    public function test_add_returns_instance_for_fluent_api(): void
    {
        $result = Breadcrumbs::add('Home', '/');
        self::assertInstanceOf(Breadcrumbs::class, $result);
    }

    /**
     * Tests that render() returns an empty string when no segments exist.
     */
    public function test_render_returns_empty_string_when_no_segments(): void
    {
        self::assertEquals('', Breadcrumbs::getInstance()->render());
    }

    /**
     * Tests that the last segment is always rendered as 'active',
     * even if a valid route exists for it.
     */
    public function test_render_renders_last_segment_as_active(): void
    {
        Router::$instance?->get('/test', 'handler');

        Breadcrumbs::reset();
        Breadcrumbs::add('Home', '/home');
        Breadcrumbs::add('Test', '/test'); // Last item

        $html = Breadcrumbs::getInstance()->render();

        // The last item should have the 'active' class and NOT be an <a> tag
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Test</li>', $html);
        self::assertStringNotContainsString('<a href="/test"', $html);
    }

    /**
     * Tests that segments with valid existing routes are rendered as links,
     * except for the last segment which should be active text.
     */
    public function test_render_renders_links_for_existing_routes(): void
    {
        Router::$instance?->get('/products', 'handler');
        Router::$instance?->get('/products/123', 'handler');

        Breadcrumbs::reset();
        Breadcrumbs::add('Home', '/');
        Breadcrumbs::add('Products', '/products');
        Breadcrumbs::add('Product Detail', '/products/123'); // This is the last item

        $html = Breadcrumbs::getInstance()->render();

        // The middle item should be a link
        self::assertStringContainsString('<a href="/products" class="text-primary text-decoration-none">Products</a>', $html);

        // The last item should NOT be a link, but an active list item
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Product Detail</li>', $html);
        self::assertStringNotContainsString('<a href="/products/123"', $html);
    }

    /**
     * Tests that segments with non-existent routes are rendered as active text.
     */
    public function test_render_renders_active_for_non_existing_routes(): void
    {
        Breadcrumbs::reset();
        Breadcrumbs::add('Home', '/');
        Breadcrumbs::add('Unknown', '/unknown-route');

        $html = Breadcrumbs::getInstance()->render();

        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Unknown</li>', $html);
    }

    /**
     * Tests that HTML special characters in labels and URLs are escaped to prevent XSS.
     */
    public function test_render_escapes_html_content(): void
    {
        Breadcrumbs::reset();
        Breadcrumbs::add('<b>Home</b>', '/<script>alert(1)</script>');

        $html = Breadcrumbs::getInstance()->render();

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;b&gt;Home&lt;/b&gt;', $html);
    }

    /**
     * Tests that route matching works correctly with wildcard parameters (e.g., {id}).
     */
    public function test_render_matches_wildcard_routes(): void
    {
        // Register a route with a wildcard
        Router::$instance?->get('/user/{id}/profile', 'handler');

        Breadcrumbs::reset();
        Breadcrumbs::add('Home', '/');
        Breadcrumbs::add('User Profile', '/user/42/profile');

        $html = Breadcrumbs::getInstance()->render();

        // If it matches, 'User Profile' should be a link (since it's not the last item)
        // Note: In this specific test, it is the last item, so we add one more to check link status
        Breadcrumbs::add('Final', '/final');
        $html = Breadcrumbs::getInstance()->render();

        self::assertStringContainsString('<a href="/user/42/profile"', $html);
    }

    /**
     * Tests edge case where a URL is null or empty.
     */
    public function test_render_handles_null_or_empty_urls(): void
    {
        Breadcrumbs::reset();
        Breadcrumbs::add('Empty', '');
        Breadcrumbs::add('Null', null);
        Breadcrumbs::add('Last', '/last');

        $html = Breadcrumbs::getInstance()->render();

        // Empty/Null URLs should result in active items (not links)
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Empty</li>', $html);
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Null</li>', $html);
    }

    /**
     * Tests that routeExists returns false if Router instance is missing.
     * Covers line 119.
     */
    public function test_route_exists_returns_false_when_router_is_null(): void
    {
        Router::$instance = null;

        Breadcrumbs::reset();
        Breadcrumbs::add('Home', '/home');
        Breadcrumbs::add('Last', '/last');

        $html = Breadcrumbs::getInstance()->render();

        // Since router is null, routeExists is false, so Home is rendered as active text instead of link
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Home</li>', $html);
    }

    /**
     * Tests that routeExists skips routes that result in an empty pattern.
     * Covers line 134.
     */
    public function test_route_exists_skips_empty_pattern(): void
    {
        // We must inject an empty path via reflection because Router->get() always prefixes with '/'
        $router = Router::$instance;
        self::assertInstanceOf(Router::class, $router);

        $reflection = new \ReflectionClass($router);
        $property = $reflection->getProperty('routes');

        $routes = $property->getValue($router);
        self::assertTrue(is_array($routes));

        $routes[] = [
            'method' => 'GET',
            'path' => '', // Empty path to trigger $pattern === ''
            'handler' => 'handler',
            'middleware' => [],
        ];
        $property->setValue($router, $routes);

        Breadcrumbs::reset();
        Breadcrumbs::add('Empty Route', '');
        Breadcrumbs::add('Last', '/last');

        $html = Breadcrumbs::getInstance()->render();

        // Because the empty pattern is skipped, routeExists returns false for it, making it active text
        self::assertStringContainsString('<li class="breadcrumb-item active" aria-current="page">Empty Route</li>', $html);
    }
}
