<?php

declare(strict_types=1);

namespace App\Core;

use App\Middleware\SecurityHeadersMiddleware;
use RuntimeException;

/**
 * Router Class
 *
 * Implements HTTP request registration and routing system.
 */
class Router
{
    public static ?self $instance = null;

    /** @var array<int, array{method: string, path: string, handler: string|callable, middleware: array<int, string>, params?: array<int, string>}> */
    private array $routes = [];

    /** @var array{prefix?: string, middleware?: string|array<int, string>} */
    private array $currentGroupOptions = [];

    public function __construct()
    {
        self::$instance = $this;
    }

    /**
     * Returns all registered routes.
     *
     * @return array<int, array{method: string, path: string, handler: string|callable, middleware: array<int, string>, params?: array<int, string>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function get(string $path, string|callable $handler): self
    {
        $this->addRoute('GET', $path, $handler);

        return $this;
    }

    public function post(string $path, string|callable $handler): self
    {
        $this->addRoute('POST', $path, $handler);

        return $this;
    }

    public function put(string $path, string|callable $handler): self
    {
        $this->addRoute('PUT', $path, $handler);

        return $this;
    }

    public function delete(string $path, string|callable $handler): self
    {
        $this->addRoute('DELETE', $path, $handler);

        return $this;
    }

    /**
     * Defines a route group with common properties.
     *
     * @param  array{prefix?: string, middleware?: string|array<int, string>}  $options
     */
    public function group(array $options, callable $callback): void
    {
        $previousGroupOptions = $this->currentGroupOptions;

        $mergedOptions = array_merge($previousGroupOptions, $options);
        if (isset($previousGroupOptions['prefix']) && isset($options['prefix'])) {
            $mergedOptions['prefix'] = '/'.trim($previousGroupOptions['prefix'].'/'.trim($options['prefix'], '/'), '/');
        }

        if (isset($previousGroupOptions['middleware']) && isset($options['middleware'])) {
            $prevMw = is_array($previousGroupOptions['middleware']) ? $previousGroupOptions['middleware'] : [$previousGroupOptions['middleware']];
            $newMw = is_array($options['middleware']) ? $options['middleware'] : [$options['middleware']];
            /** @var array<int, string> $prevMw */
            /** @var array<int, string> $newMw */
            $mergedOptions['middleware'] = array_merge($prevMw, $newMw);
        }

        $this->currentGroupOptions = $mergedOptions;
        $callback($this);
        $this->currentGroupOptions = $previousGroupOptions;
    }

    private function addRoute(string $method, string $path, string|callable $handler): void
    {
        $prefix = $this->currentGroupOptions['prefix'] ?? '';
        $path = '/'.trim($prefix.'/'.trim($path, '/'), '/');

        $middleware = $this->currentGroupOptions['middleware'] ?? [];
        $middlewareList = is_string($middleware) ? [$middleware] : $middleware;

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middlewareList,
        ];
    }

    /**
     * Dispatches the current request, determining the appropriate route.
     */
    public function dispatch(string $method, string $uri): mixed
    {
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        if ($method === 'POST' && isset($_POST['_method'])) {
            $rawMethod = $_POST['_method'];
            $method = strtoupper(is_string($rawMethod) ? $rawMethod : '');
        }

        $parsedUrl = parse_url($uri);
        $path = '/';
        if (is_array($parsedUrl) && isset($parsedUrl['path'])) {
            $path = $parsedUrl['path'];
        }
        $path = '/'.trim($path, '/');

        $sensitivePaths = [
            '/analiza', '/bin', '/config', '/db', '/dist', '/docker', '/examples', '/lang',
            '/logmonitor', '/resources', '/routes', '/scripts', '/secrets', '/src', '/tests',
            '/vendor', '/storage/logs', '/storage/framework', '/storage/views', '/storage/commands',
            '/storage/backups', '/storage/reports', '/var/log', '/tmp/logs', '/venv', '/node_modules',
        ];
        $sensitiveFiles = [
            '.dockerignore', '.env', '.gitignore', 'composer.json', 'composer.lock',
            'phpunit.xml', 'phpstan.neon', 'phpstan-security.neon', 'phpstan-report.json',
            'package.json', 'package-lock.json', 'logmonitor', 'ops', 'docker-compose.yml',
            'compose.dev.yml', '.htaccess', 'web.config',
        ];

        foreach ($sensitivePaths as $sp) {
            if (str_starts_with($path, $sp.'/') || $path === $sp) {
                throw new RuntimeException(__('Page not found.'), 404);
            }
        }

        $basename = basename($path);
        if (in_array($basename, $sensitiveFiles, true) || str_starts_with($basename, '.env')) {
            throw new RuntimeException(__('Page not found.'), 404);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('/\{[a-zA-Z0-9_]+:([^\}]+)\}/', '($1)', $route['path']);
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', is_string($pattern) ? $pattern : $route['path']);
            $patternStr = '#^'.$pattern.'$#';

            if (preg_match($patternStr, $path, $matches) === 1) {
                /** @var array<int, string> $params */
                $params = array_slice($matches, 1);
                $route['params'] = $params;

                return $this->resolve($route);
            }
        }

        throw new RuntimeException(__('Page not found.'), 404);
    }

    /**
     * Resolves and executes a found route by running the middleware pipeline.
     *
     * @param  array{method: string, path: string, handler: string|callable, middleware: array<int, string>, params?: array<int, string>}  $route
     */
    private function resolve(array $route): mixed
    {
        $middleware = $route['middleware'];
        array_unshift($middleware, SecurityHeadersMiddleware::class);

        $pipeline = new Pipeline;

        return $pipeline->send($route)
            ->through($middleware)
            ->then(function (array $route) {
                $handler = $route['handler'] ?? null;
                $params = $route['params'] ?? [];

                if (is_callable($handler)) {
                    /** @var array<int|string, mixed> $params */
                    return call_user_func_array($handler, $params);
                }

                if (is_string($handler)) {
                    [$controllerClass, $method] = explode('@', $handler);
                    if (str_starts_with($controllerClass, 'App\\')) {
                        $fullControllerClass = $controllerClass;
                    } else {
                        $fullControllerClass = "App\\Controllers\\{$controllerClass}";
                    }

                    if (! class_exists($fullControllerClass)) {
                        throw new RuntimeException(__('Controller :class does not exist.', ['class' => $fullControllerClass]), 500);
                    }

                    $controller = new $fullControllerClass;
                    if (! method_exists($controller, $method)) {
                        throw new RuntimeException(__('Method :method does not exist in controller :class.', ['method' => $method, 'class' => $fullControllerClass]), 500);
                    }

                    $payload = $_POST;
                    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
                    $contentTypeStr = is_string($contentType) ? $contentType : '';
                    if (str_contains($contentTypeStr, 'application/json')) {
                        // @codeCoverageIgnoreStart
                        $rawInput = file_get_contents('php://input');
                        if (is_string($rawInput) && $rawInput !== '') {
                            try {
                                $json = json_decode($rawInput, true);
                                if (is_array($json)) {
                                    $payload = array_merge($payload, $json);
                                }
                            } catch (\Throwable) {
                                // Ignore invalid JSON when payload is malformed
                            }
                        }
                        // @codeCoverageIgnoreEnd
                    } else {
                        $reqMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
                        $reqMethodStr = is_string($reqMethod) ? $reqMethod : 'GET';
                        if ($reqMethodStr === 'POST' && isset($_POST['_method'])) {
                            $rawMethod = $_POST['_method'];
                            $reqMethodStr = strtoupper(is_string($rawMethod) ? $rawMethod : '');
                        }
                        if ($reqMethodStr !== 'GET' && $reqMethodStr !== 'POST') {
                            // @codeCoverageIgnoreStart
                            $rawInput = file_get_contents('php://input');
                            if (is_string($rawInput) && $rawInput !== '') {
                                parse_str($rawInput, $putData);
                                $payload = array_merge($payload, $putData);
                            }
                            // @codeCoverageIgnoreEnd
                        }
                    }

                    $methodLower = strtolower($method);
                    if ($methodLower === 'store' || str_ends_with($methodLower, 'store')) {
                        /** @var array<int|string, mixed> $params */
                        $args = array_merge([$payload], $params);
                    } elseif ($methodLower === 'update' || str_ends_with($methodLower, 'update')) {
                        /** @var array<int|string, mixed> $params */
                        $args = array_merge([$payload], $params);
                    } else {
                        /** @var array<int|string, mixed> $params */
                        $args = $params;
                    }

                    $callback = [$controller, $method];
                    if (is_callable($callback)) {
                        return call_user_func_array($callback, $args);
                    }

                    throw new RuntimeException(__('Method :method is not callable in controller :class.', ['method' => $method, 'class' => $fullControllerClass]), 500);
                }

                throw new RuntimeException(__('Invalid route handler.'), 500);
            });
    }
}
