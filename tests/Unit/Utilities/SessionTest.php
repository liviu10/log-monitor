<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Session;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utilities\Support\WebServer;

/**
 * Sesiunile nu pot porni in PHPUnit (output-ul a inceput deja), deci comportamentul se testeaza
 * prin serverul integrat PHP (tests/fixtures/web-router.php), cu cookie-urile reale.
 */
final class SessionTest extends TestCase
{
    public function test_not_started_in_cli_process(): void
    {
        $this->assertFalse(Session::isStarted());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Session::get(): the session is not started; call Session::start() first.');

        Session::get('x');
    }

    public function test_cookie_has_secure_defaults(): void
    {
        $response = WebServer::request('GET', '/session/set');
        $cookie = $response['headers']['set-cookie'][0] ?? '';

        $this->assertMatchesRegularExpression('/^TESTSESSID=[A-Za-z0-9,-]+; path=\/; HttpOnly; SameSite=Lax$/', $cookie);
        $this->assertContains('no-store, no-cache, must-revalidate', $response['headers']['cache-control'] ?? []);
    }

    public function test_cookie_options(): void
    {
        $cookie = WebServer::request('GET', '/session/options')['headers']['set-cookie'][0] ?? '';

        $this->assertMatchesRegularExpression('/^OPTSESSID=[A-Za-z0-9,-]+; expires=.+; Max-Age=3600; path=\/app; HttpOnly; SameSite=Strict$/', $cookie);
    }

    public function test_secure_cookie(): void
    {
        $cookie = WebServer::request('GET', '/session/secure')['headers']['set-cookie'][0] ?? '';

        $this->assertMatchesRegularExpression('/^SECSESSID=[A-Za-z0-9,-]+; path=\/; secure; HttpOnly; SameSite=None$/', $cookie);
    }

    public function test_values_persist_between_requests(): void
    {
        $cookie = $this->startSession();

        $data = $this->json(WebServer::request('GET', '/session/get', ['Cookie' => $cookie])['body']);

        $this->assertSame(['user_id' => 5, 'lista' => ['a' => 1]], $data['all']);
        $this->assertTrue($data['has']);
        $this->assertSame('implicit', $data['default']);
        $this->assertFalse($data['expired']);
    }

    public function test_get_missing_key_without_default(): void
    {
        $cookie = $this->startSession();

        $body = WebServer::request('GET', '/session/missing', ['Cookie' => $cookie])['body'];

        $this->assertSame("OutOfBoundsException: Session::get(): key 'lipsa' does not exist.", $body);
    }

    public function test_pull(): void
    {
        $cookie = $this->startSession();

        $data = $this->json(WebServer::request('GET', '/session/pull', ['Cookie' => $cookie])['body']);

        $this->assertSame(['pulled' => 5, 'after' => false, 'default' => 0], $data);
    }

    public function test_clear_removes_application_data(): void
    {
        $cookie = $this->startSession();

        $data = $this->json(WebServer::request('GET', '/session/clear', ['Cookie' => $cookie])['body']);
        $this->assertSame([], $data['all']);

        // Datele interne (flash) raman.
        $next = $this->json(WebServer::request('GET', '/session/get', ['Cookie' => $cookie])['body']);
        $this->assertSame('pastrat', $next['flashed']);
    }

    public function test_strict_mode_rejects_unknown_session_id(): void
    {
        $response = WebServer::request('GET', '/session/set', ['Cookie' => 'TESTSESSID=idinventatdeatacator123']);

        $data = $this->json($response['body']);
        $this->assertNotSame('idinventatdeatacator123', $this->text($data['id']));
        $this->assertStringStartsWith('TESTSESSID='.$this->text($data['id']).';', $response['headers']['set-cookie'][0] ?? '');
    }

    public function test_regenerate_changes_id_and_keeps_data(): void
    {
        $cookie = $this->startSession();

        $response = WebServer::request('GET', '/session/regenerate', ['Cookie' => $cookie]);
        $data = $this->json($response['body']);

        $this->assertNotSame($data['old'], $data['new']);
        $this->assertSame(5, $data['user_id']);
        $this->assertStringStartsWith('TESTSESSID='.$this->text($data['new']).';', $response['headers']['set-cookie'][0] ?? '');

        // Vechiul ID nu mai are date.
        $old = $this->json(WebServer::request('GET', '/session/get', ['Cookie' => 'TESTSESSID='.$this->text($data['old'])])['body']);
        $this->assertSame([], $old['all']);
    }

    public function test_destroy_deletes_data_and_cookie(): void
    {
        $cookie = $this->startSession();

        $response = WebServer::request('GET', '/session/destroy', ['Cookie' => $cookie]);
        $this->assertSame('destroyed', $response['body']);
        $this->assertStringStartsWith('TESTSESSID=deleted; expires=Thu, 01 Jan 1970', $response['headers']['set-cookie'][0] ?? '');

        $after = $this->json(WebServer::request('GET', '/session/get', ['Cookie' => $cookie])['body']);
        $this->assertSame([], $after['all']);
    }

    public function test_flash_is_available_only_in_the_next_request(): void
    {
        $cookie = $this->startSession();
        WebServer::request('GET', '/session/flash', ['Cookie' => $cookie]);

        $next = $this->json(WebServer::request('GET', '/session/get', ['Cookie' => $cookie])['body']);
        $this->assertSame('Salvat', $next['flashed']);
        $this->assertSame('Ion', $next['old']);
        $this->assertIsArray($next['all']);
        $this->assertArrayNotHasKey('__session', $next['all']);

        $later = $this->json(WebServer::request('GET', '/session/get', ['Cookie' => $cookie])['body']);
        $this->assertNull($later['flashed']);
        $this->assertSame('', $later['old']);
    }

    public function test_idle_timeout_clears_session(): void
    {
        $cookie = $this->startSession();
        WebServer::request('GET', '/session/age', ['Cookie' => $cookie]);

        $response = WebServer::request('GET', '/session/get', ['Cookie' => $cookie]);
        $data = $this->json($response['body']);

        $this->assertTrue($data['expired']);
        $this->assertSame([], $data['all']);
        // ID nou dupa expirare.
        $this->assertStringStartsWith('TESTSESSID='.$this->text($data['id']).';', $response['headers']['set-cookie'][0] ?? '');
        $this->assertStringNotContainsString(substr($cookie, strlen('TESTSESSID=')), $this->text($data['id']));
    }

    public function test_invalid_options(): void
    {
        $errors = $this->json(WebServer::request('GET', '/session/invalid')['body']);

        $this->assertSame('LogicException: Session::get(): the session is not started; call Session::start() first.', $errors['not_started']);
        $this->assertSame("InvalidArgumentException: Session::start(): unknown option 'nume'.", $errors['unknown_option']);
        $this->assertSame("InvalidArgumentException: Session::start(): sameSite must be Lax, Strict or None, got 'lax'.", $errors['samesite']);
        $this->assertStringStartsWith("InvalidArgumentException: Session::start(): invalid session name '1abc'", $this->text($errors['name']));
        $this->assertSame('InvalidArgumentException: Session::start(): idleTimeout must be at least 1 second.', $errors['idle']);
        $this->assertStringStartsWith('RuntimeException: Session::start(): save path', $this->text($errors['save_path']));
        $this->assertSame("InvalidArgumentException: Session::start(): option 'lifetime' must be int, got string.", $errors['type']);
    }

    public function test_unstorable_values_are_rejected(): void
    {
        $cookie = $this->startSession();

        $errors = $this->json(WebServer::request('GET', '/session/unstorable', ['Cookie' => $cookie])['body']);

        $this->assertSame("InvalidArgumentException: Session::set(): the value for 'f' cannot be stored in the session (Closure).", $errors['closure']);
        $this->assertSame("InvalidArgumentException: Session::set(): the value for 'f' cannot be stored in the session (resource (stream)).", $errors['nested']);
        $this->assertSame("InvalidArgumentException: Session::set(): the key '__session' is empty or reserved.", $errors['reserved']);
        $this->assertFalse($errors['stored']);
    }

    public function test_start_after_output_reports_where_output_started(): void
    {
        $body = WebServer::request('GET', '/session/headers-sent')['body'];

        $this->assertMatchesRegularExpression('/^output\|LogicException: Session::start\(\): headers already sent \(output started at .+web-router\.php:\d+\)\.$/', $body);
    }

    /**
     * Porneste o sesiune noua cu date si returneaza header-ul Cookie pentru cererile urmatoare.
     */
    private function startSession(): string
    {
        $setCookie = WebServer::request('GET', '/session/set')['headers']['set-cookie'][0] ?? '';
        $this->assertNotSame('', $setCookie);

        return explode(';', $setCookie, 2)[0];
    }

    private function text(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        $data = json_decode($body, true);
        $this->assertIsArray($data, $body);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
