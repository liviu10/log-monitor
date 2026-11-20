<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\Router;

/**
 * Utility class for Breadcrumbs navigation management.
 * Offers fluent API for simple construction.
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Breadcrumbs
{
    private static ?self $instance = null;

    /**
     * @var array<array{label: string, url: string|null}>
     */
    private array $segments = [];

    /**
     * Returns the singleton instance of the class.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Resets stored segments.
     */
    public static function reset(): void
    {
        $inst = self::getInstance();
        $inst->segments = [];
    }

    /**
     * Fluently adds a new segment to the breadcrumbs.
     */
    public static function add(string $label, ?string $url = null): self
    {
        $inst = self::getInstance();
        $inst->segments[] = [
            'label' => $label,
            'url' => $url,
        ];

        return $inst;
    }

    /**
     * Renders breadcrumbs navigation as HTML code matching Bootstrap 5 layout.
     * Applies htmlspecialchars on labels for XSS protection.
     */
    public function render(): string
    {
        if (count($this->segments) === 0) {
            return '';
        }

        $html = '<nav aria-label="breadcrumb" class="mb-0"><ol class="breadcrumb mb-0 bg-transparent p-0">';
        $total = count($this->segments);

        for ($i = 0; $i < $total; $i++) {
            $seg = $this->segments[$i];
            $label = htmlspecialchars($seg['label'], ENT_QUOTES, 'UTF-8');
            $isActive = ($i === $total - 1);
            $hasRoute = isset($seg['url']) && $seg['url'] !== '' && $this->routeExists($seg['url']);

            if ($isActive || ! $hasRoute) {
                $html .= sprintf(
                    '<li class="breadcrumb-item active" aria-current="page">%s</li>',
                    $label
                );
            } else {
                $html .= sprintf(
                    '<li class="breadcrumb-item"><a href="%s" class="text-primary text-decoration-none">%s</a></li>',
                    htmlspecialchars($seg['url'], ENT_QUOTES, 'UTF-8'),
                    $label
                );
            }
        }

        $html .= '</ol></nav>';

        return $html;
    }

    /**
     * Checks if a URL path corresponds to a registered GET route.
     */
    private function routeExists(string $url): bool
    {
        $router = Router::$instance;
        if ($router === null) {
            return false;
        }

        $parsed = parse_url($url, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : '/';
        $path = '/'.trim($path, '/');

        foreach ($router->getRoutes() as $route) {
            if ($route['method'] !== 'GET') {
                continue;
            }

            // Convert wildcards like {id} or {role_id} to regex ([^/]+)
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', $route['path']);
            if ($pattern === '') {
                continue;
            }
            $pattern = '#^'.$pattern.'$#';

            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
