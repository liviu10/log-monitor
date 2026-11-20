<?php

declare(strict_types=1);

namespace App\Controllers {
    class RouterDummyController
    {
        public function index(): string
        {
            return 'index_called';
        }

        public function show(string $id): string
        {
            return "show_id_{$id}";
        }

        /**
         * @param  array<string, mixed>  $payload
         */
        public function store(array $payload): string
        {
            $name = is_string($payload['name'] ?? null) ? $payload['name'] : 'empty';

            return 'store_'.$name;
        }

        /**
         * @param  array<string, mixed>  $payload
         */
        public function update(array $payload, string $id): string
        {
            $name = is_string($payload['name'] ?? null) ? $payload['name'] : 'empty';

            return "update_id_{$id}_name_{$name}";
        }

        /** @phpstan-ignore method.unused */
        private function hidden(): string
        {
            return 'hidden';
        }
    }
}

namespace Tests\Unit\Core {

    use App\Core\Router;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    /**
     * Class RouterTest
     *
     * Unit tests for the Router core component.
     *
     * @category Tests
     */
    class RouterTest extends TestCase
    {
        private Router $router;

        protected function setUp(): void
        {
            parent::setUp();
            $this->router = new Router;

            // Reset superglobals to avoid test contamination
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['CONTENT_TYPE'] = '';
        }

        public function test_router_registers_basic_methods(): void
        {
            $this->router->get('/test-get', 'Controller@get');
            $this->router->post('/test-post', 'Controller@post');
            $this->router->put('/test-put', 'Controller@put');
            $this->router->delete('/test-delete', 'Controller@delete');

            $routes = $this->router->getRoutes();

            self::assertCount(4, $routes);
            self::assertEquals('GET', $routes[0]['method']);
            self::assertEquals('/test-get', $routes[0]['path']);

            self::assertEquals('POST', $routes[1]['method']);
            self::assertEquals('PUT', $routes[2]['method']);
            self::assertEquals('DELETE', $routes[3]['method']);
        }

        public function test_router_group_applies_prefix_and_middleware(): void
        {
            $this->router->group(['prefix' => '/api', 'middleware' => 'AuthMiddleware'], function (Router $router) {
                $router->get('/users', 'Controller@users');

                $router->group(['prefix' => '/admin', 'middleware' => ['AdminMiddleware']], function (Router $router) {
                    $router->post('/delete', 'Controller@delete');
                });
            });

            $routes = $this->router->getRoutes();

            self::assertCount(2, $routes);

            self::assertEquals('/api/users', $routes[0]['path']);
            self::assertEquals(['AuthMiddleware'], $routes[0]['middleware']);

            self::assertEquals('/api/admin/delete', $routes[1]['path']);
            self::assertEquals(['AuthMiddleware', 'AdminMiddleware'], $routes[1]['middleware']);
        }

        public function test_dispatch_calls_closure_handler_successfully(): void
        {
            $this->router->get('/closure', function () {
                return 'closure_result';
            });

            $result = $this->router->dispatch('GET', '/closure');
            self::assertEquals('closure_result', $result);
        }

        public function test_dispatch_calls_controller_method_successfully(): void
        {
            $this->router->get('/dummy', 'RouterDummyController@index');

            $result = $this->router->dispatch('GET', '/dummy');
            self::assertEquals('index_called', $result);
        }

        public function test_dispatch_handles_route_parameters(): void
        {
            $this->router->get('/dummy/{id}', 'RouterDummyController@show');

            $result = $this->router->dispatch('GET', '/dummy/42');
            self::assertEquals('show_id_42', $result);
        }

        public function test_dispatch_throws_exception_if_route_not_found(): void
        {
            $this->expectException(RuntimeException::class);

            $this->router->dispatch('GET', '/non-existent-route');
        }

        public function test_dispatch_throws_exception_if_controller_does_not_exist(): void
        {
            $this->expectException(RuntimeException::class);

            $this->router->get('/bad', 'NonExistentController@index');
            $this->router->dispatch('GET', '/bad');
        }

        public function test_dispatch_injects_payload_for_store_method(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['name' => 'liviu'];

            $this->router->post('/dummy/store', 'RouterDummyController@store');

            $result = $this->router->dispatch('POST', '/dummy/store');
            self::assertEquals('store_liviu', $result);
        }

        public function test_dispatch_injects_payload_and_params_for_update_method(): void
        {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['_method' => 'PUT', 'name' => 'updated_name'];

            $this->router->put('/dummy/{id}/update', 'RouterDummyController@update');

            // Dispatch using POST to trigger method spoofing resolution (since we send _method=PUT)
            $result = $this->router->dispatch('POST', '/dummy/99/update');
            self::assertEquals('update_id_99_name_updated_name', $result);
        }

        public function test_dispatch_throws_exception_if_method_does_not_exist(): void
        {
            $this->expectException(RuntimeException::class);

            $this->router->get('/dummy/missing', 'RouterDummyController@nonExistentMethod');
            $this->router->dispatch('GET', '/dummy/missing');
        }

        public function test_dispatch_throws_exception_if_method_not_callable(): void
        {
            $this->expectException(RuntimeException::class);

            $this->router->get('/dummy/hidden', 'RouterDummyController@hidden');
            $this->router->dispatch('GET', '/dummy/hidden');
        }

        public function test_dispatch_handles_head_request_as_get(): void
        {
            $this->router->get('/dummy/head', function () {
                return 'head_success';
            });

            $result = $this->router->dispatch('HEAD', '/dummy/head');
            self::assertEquals('head_success', $result);
        }

        public function test_dispatch_blocks_sensitive_paths(): void
        {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(404);

            $this->router->dispatch('GET', '/bin/test');
        }

        public function test_dispatch_blocks_sensitive_files(): void
        {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionCode(404);

            $this->router->dispatch('GET', '/.env');
        }

        public function test_dispatch_handles_fqcn_controller_class(): void
        {
            $this->router->get('/dummy/fqcn', 'App\Controllers\RouterDummyController@index');

            $result = $this->router->dispatch('GET', '/dummy/fqcn');
            self::assertEquals('index_called', $result);
        }

        public function test_dispatch_throws_on_invalid_route_handler(): void
        {
            $reflection = new \ReflectionClass(Router::class);
            $routesProp = $reflection->getProperty('routes');

            // Inject route with non-string, non-callable handler
            $routesProp->setValue($this->router, [
                [
                    'method' => 'GET',
                    'path' => '/bad-handler',
                    'handler' => 12345, // invalid handler
                    'middleware' => [],
                ],
            ]);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Invalid route handler.');

            $this->router->dispatch('GET', '/bad-handler');
        }
    }
}
