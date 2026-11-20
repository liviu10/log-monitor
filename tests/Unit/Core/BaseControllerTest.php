<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\BaseController;
use App\Core\View;
use App\Exceptions\RedirectException;
use App\Utilities\SessionManager;
use eftec\bladeone\BladeOne;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Concrete class for testing BaseController
 */
class DummyController extends BaseController
{
    /**
     * @param  array<string, array<int, string>>  $rules
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function callValidate(array $rules, array $data = []): array
    {
        return $this->validate($rules, $data);
    }

    public function callRedirect(string $url): never
    {
        $this->redirect($url);
    }

    public function callFlash(string $type, string $title, string $message): void
    {
        $this->flash($type, $title, $message);
    }

    public function callRedirectWithFlash(string $url, string $type, string $title, string $message): never
    {
        $this->redirectWithFlash($url, $type, $title, $message);
    }

    public function callCheckPermission(string $permission): void
    {
        $this->checkPermission($permission);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function callRender(string $view, array $data = []): string
    {
        return $this->render($view, $data);
    }
}

/**
 * Class BaseControllerTest
 *
 * @category Tests
 */
class BaseControllerTest extends TestCase
{
    private DummyController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new DummyController;

        // Ensure session is started for SessionManager
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        SessionManager::clear();
        parent::tearDown();
    }

    public function test_validate_returns_data_on_success(): void
    {
        $rules = [
            'first_name' => ['required', 'alpha'],
        ];
        $data = [
            'first_name' => 'John',
        ];

        $result = $this->controller->callValidate($rules, $data);
        self::assertEquals($data, $result);
    }

    public function test_validate_throws_redirect_exception_on_failure(): void
    {
        $rules = [
            'first_name' => ['required'], // Rule requires value
        ];
        $data = [
            'first_name' => '', // Fails validation
        ];

        try {
            $this->controller->callValidate($rules, $data);
            self::fail('Expected RedirectException was not thrown.');
        } catch (RedirectException $e) {
            // Validation redirects to referer, which defaults to '/' in testing
            self::assertEquals('/', $e->getUrl());
        }

        // Verify session was populated with errors and old input
        self::assertTrue(SessionManager::has('errors'));
        self::assertTrue(SessionManager::has('old'));

        $errors = SessionManager::get('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('first_name', $errors);
    }

    public function test_redirect_throws_redirect_exception(): void
    {
        $this->expectException(RedirectException::class);
        $this->controller->callRedirect('/dashboard');
    }

    public function test_flash_does_not_throw_errors(): void
    {
        self::expectNotToPerformAssertions();
        // Simply assert it can be called (relies on setFlash helper which writes to session)
        $this->controller->callFlash('success', 'Done', 'Action completed.');
    }

    public function test_redirect_with_flash_throws_redirect_exception(): void
    {
        $this->expectException(RedirectException::class);
        $this->controller->callRedirectWithFlash('/home', 'info', 'Notice', 'Something happened');
    }

    public function test_check_permission_redirects_if_permission_missing(): void
    {
        // can() helper might return false or not exist, triggering a redirect to profile.
        try {
            $this->controller->callCheckPermission('super_admin_only_permission_that_does_not_exist');
            self::fail('Expected RedirectException was not thrown.');
        } catch (RedirectException $e) {
            self::assertEquals('/admin/settings/users/profile', $e->getUrl());
        }
    }

    public function test_validate_reads_from_post_when_data_empty(): void
    {
        $_POST = ['first_name' => 'Alice'];
        $result = $this->controller->callValidate(['first_name' => ['required']], []);
        self::assertEquals(['first_name' => 'Alice'], $result);
        $_POST = [];
    }

    public function test_render_returns_view_output(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $bladeProp = $reflection->getProperty('blade');

        /** @var BladeOne&MockObject $bladeMock */
        $bladeMock = $this->createMock(BladeOne::class);
        $bladeMock->expects($this->once())
            ->method('run')
            ->with('dummy.view', self::isArray())
            ->willReturn('<h1>Rendered Dummy</h1>');

        $bladeProp->setValue(null, $bladeMock);

        try {
            $output = $this->controller->callRender('dummy.view', ['param' => 'value']);
            self::assertEquals('<h1>Rendered Dummy</h1>', $output);
        } finally {
            $bladeProp->setValue(null, null);
        }
    }
}
