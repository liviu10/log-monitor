<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Csrf;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utilities\Support\WebServer;

/**
 * Tokenul sta in sesiune, deci testele ruleaza prin serverul integrat PHP (tests/fixtures/web-router.php).
 */
final class CsrfTest extends TestCase
{
    public function test_requires_session_in_cli_process(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Csrf::token(): the session is not started; start it before using CSRF tokens.');

        Csrf::token();
    }

    public function test_token_and_field(): void
    {
        $data = $this->json(WebServer::request('GET', '/csrf/token')['body']);
        $token = $this->text($data['token']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $data['again']);
        $this->assertSame('<input type="hidden" name="_token" value="'.$token.'">', $data['field']);
        $this->assertSame('<input type="hidden" name="csrf" value="'.$token.'">', $data['custom']);
    }

    public function test_token_is_stable_per_session_and_different_between_sessions(): void
    {
        [$cookie, $token] = $this->session();

        $again = $this->json(WebServer::request('GET', '/csrf/token', ['Cookie' => $cookie])['body']);
        $this->assertSame($token, $again['token']);

        [, $other] = $this->session();
        $this->assertNotSame($token, $other);
    }

    public function test_check_accepts_form_field(): void
    {
        [$cookie, $token] = $this->session();

        $response = WebServer::request('POST', '/csrf/check', [
            'Cookie' => $cookie,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], '_token='.$token);

        $this->assertSame('ok', $response['body']);
    }

    public function test_check_accepts_header(): void
    {
        [$cookie, $token] = $this->session();

        $response = WebServer::request('DELETE', '/csrf/check', ['Cookie' => $cookie, 'X-CSRF-TOKEN' => $token]);

        $this->assertSame('ok', $response['body']);
    }

    public function test_check_with_custom_field(): void
    {
        [$cookie, $token] = $this->session();

        $response = WebServer::request('POST', '/csrf/check-field', [
            'Cookie' => $cookie,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], 'csrf='.$token);

        $this->assertSame('ok', $response['body']);
    }

    public function test_safe_methods_are_not_checked(): void
    {
        [$cookie] = $this->session();

        $this->assertSame('ok', WebServer::request('GET', '/csrf/check', ['Cookie' => $cookie])['body']);
        $this->assertSame('ok', WebServer::request('OPTIONS', '/csrf/check', ['Cookie' => $cookie])['body']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'lipsa' => ['', '419: Csrf::check(): the CSRF token is missing.'];
        yield 'gol' => ['_token=', '419: Csrf::check(): the CSRF token is missing.'];
        yield 'gresit' => ['_token='.str_repeat('a', 64), '419: Csrf::check(): the CSRF token is invalid or expired.'];
        yield 'array' => ['_token[]=x', '419: Csrf::check(): the CSRF token is invalid or expired.'];
    }

    #[DataProvider('rejectedProvider')]
    public function test_check_rejects(string $body, string $expected): void
    {
        [$cookie] = $this->session();

        $response = WebServer::request('POST', '/csrf/check', [
            'Cookie' => $cookie,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], $body);

        $this->assertSame($expected, $response['body']);
    }

    public function test_token_from_another_session_is_rejected(): void
    {
        [$cookie] = $this->session();
        [, $foreignToken] = $this->session();

        $response = WebServer::request('PUT', '/csrf/check', ['Cookie' => $cookie, 'X-CSRF-TOKEN' => $foreignToken]);

        $this->assertSame('419: Csrf::check(): the CSRF token is invalid or expired.', $response['body']);
    }

    public function test_post_without_session_token_is_rejected(): void
    {
        // Sesiune noua, fara token generat: orice token trimis este invalid.
        $response = WebServer::request('POST', '/csrf/check', ['X-CSRF-TOKEN' => str_repeat('0', 64)]);

        $this->assertSame('419: Csrf::check(): the CSRF token is invalid or expired.', $response['body']);
    }

    public function test_regenerate(): void
    {
        [$cookie] = $this->session();

        $data = $this->json(WebServer::request('GET', '/csrf/regenerate', ['Cookie' => $cookie])['body']);

        $this->assertNotSame($data['old'], $data['new']);
        $this->assertFalse($data['old_valid']);
    }

    public function test_invalid_usage(): void
    {
        $data = $this->json(WebServer::request('GET', '/csrf/invalid')['body']);

        $this->assertSame('LogicException: Csrf::token(): the session is not started; start it before using CSRF tokens.', $data['no_session']);
        $this->assertSame('InvalidArgumentException: Csrf::field(): invalid field name \'a"b\'.', $data['field_name']);
        $this->assertFalse($data['verify_array']);
        $this->assertFalse($data['verify_empty']);
    }

    /**
     * Sesiune noua cu token: [header-ul Cookie, tokenul].
     *
     * @return array{string, string}
     */
    private function session(): array
    {
        $response = WebServer::request('GET', '/csrf/token');
        $cookie = explode(';', $response['headers']['set-cookie'][0] ?? '', 2)[0];
        $this->assertNotSame('', $cookie);

        return [$cookie, $this->text($this->json($response['body'])['token'])];
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
