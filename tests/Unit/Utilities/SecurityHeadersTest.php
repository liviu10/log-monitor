<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\SecurityHeaders;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Utilities\Support\WebServer;

final class SecurityHeadersTest extends TestCase
{
    private const array HTTPS = ['HTTPS' => 'on'];

    public function test_defaults(): void
    {
        $nonce = SecurityHeaders::nonce();
        $headers = SecurityHeaders::headers([], self::HTTPS);

        $this->assertSame([
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; img-src 'self' data:; "
                ."font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ], $headers);
    }

    public function test_nonce_is_stable_per_request_and_url_safe(): void
    {
        $nonce = SecurityHeaders::nonce();

        $this->assertSame($nonce, SecurityHeaders::nonce());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $nonce);
    }

    public function test_hsts_only_on_https(): void
    {
        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers([], []));
        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers([], ['HTTPS' => 'off']));
        $this->assertArrayHasKey('Strict-Transport-Security', SecurityHeaders::headers([], ['SERVER_PORT' => 443]));

        $forwarded = ['HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers([], $forwarded));
        $this->assertArrayHasKey('Strict-Transport-Security', SecurityHeaders::headers(['trustForwarded' => true], $forwarded));
    }

    public function test_hsts_options(): void
    {
        $headers = SecurityHeaders::headers(['hsts' => 600, 'hstsSubdomains' => false, 'hstsPreload' => true], self::HTTPS);
        $this->assertSame('max-age=600; preload', $headers['Strict-Transport-Security']);

        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers(['hsts' => false], self::HTTPS));
    }

    public function test_csp_overrides_and_removes_directives(): void
    {
        $csp = SecurityHeaders::headers(['csp' => [
            'img-src' => "'self' data: https://cdn.example.com",
            'style-src' => "'self' 'unsafe-inline'",
            'script-src' => "'self' 'nonce-{nonce}' 'unsafe-eval'",
            'frame-ancestors' => null,
            'upgrade-insecure-requests' => '',
        ]], [])['Content-Security-Policy'];

        $this->assertStringContainsString("img-src 'self' data: https://cdn.example.com", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("script-src 'self' 'nonce-".SecurityHeaders::nonce()."' 'unsafe-eval'", $csp);
        $this->assertStringNotContainsString('frame-ancestors', $csp);
        $this->assertStringEndsWith('; upgrade-insecure-requests', $csp);
    }

    public function test_csp_report_only_and_disabled(): void
    {
        $headers = SecurityHeaders::headers(['cspReportOnly' => true], []);
        $this->assertArrayHasKey('Content-Security-Policy-Report-Only', $headers);
        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);

        $headers = SecurityHeaders::headers(['csp' => false], []);
        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
        $this->assertArrayNotHasKey('Content-Security-Policy-Report-Only', $headers);
    }

    public function test_other_options(): void
    {
        $headers = SecurityHeaders::headers([
            'frameOptions' => 'SAMEORIGIN',
            'referrerPolicy' => 'no-referrer',
            'crossOriginOpenerPolicy' => false,
            'permissionsPolicy' => ['geolocation' => '(self)', 'fullscreen' => '(self "https://video.example.com")', 'usb' => null],
        ], []);

        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertArrayNotHasKey('Cross-Origin-Opener-Policy', $headers);
        $this->assertSame(
            'camera=(), microphone=(), geolocation=(self), payment=(), magnetometer=(), gyroscope=(), fullscreen=(self "https://video.example.com")',
            $headers['Permissions-Policy'],
        );

        $headers = SecurityHeaders::headers(['frameOptions' => false, 'referrerPolicy' => false, 'permissionsPolicy' => false], []);
        $this->assertSame(['X-Content-Type-Options', 'Content-Security-Policy', 'Cross-Origin-Opener-Policy'], array_keys($headers));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptionsProvider(): iterable
    {
        yield 'optiune necunoscuta' => [['cps' => []], "unknown option 'cps'"];
        yield 'csp nu e array' => [['csp' => "default-src 'self'"], "option 'csp' must be an array of directives or false"];
        yield 'csp cu ;' => [['csp' => ['img-src' => "'self'; script-src *"]], "invalid value for CSP directive 'img-src'"];
        yield 'csp cu linie noua' => [['csp' => ['img-src' => "'self'\r\nX-A: b"]], "invalid value for CSP directive 'img-src'"];
        yield 'csp nume invalid' => [['csp' => ['Img Src' => "'self'"]], "invalid CSP directive name 'Img Src'"];
        yield 'csp valoare int' => [['csp' => ['img-src' => 5]], "invalid value for CSP directive 'img-src'"];
        yield 'hsts negativ' => [['hsts' => -1], "option 'hsts' must be a number of seconds"];
        yield 'hsts string' => [['hsts' => '1 an'], "option 'hsts' must be a number of seconds"];
        yield 'frame litere mici' => [['frameOptions' => 'deny'], "option 'frameOptions' must be one of 'DENY', 'SAMEORIGIN' or false"];
        yield 'referrer gresit' => [['referrerPolicy' => 'never'], "option 'referrerPolicy' must be one of"];
        yield 'coop gresit' => [['crossOriginOpenerPolicy' => true], "option 'crossOriginOpenerPolicy' must be one of"];
        yield 'bool gresit' => [['cspReportOnly' => 'da'], "option 'cspReportOnly' must be bool, got string"];
        yield 'permissions lista gresita' => [['permissionsPolicy' => ['camera' => 'none']], "invalid Permissions-Policy allowlist for 'camera'"];
        yield 'permissions nume gresit' => [['permissionsPolicy' => ['Camera' => '()']], "invalid Permissions-Policy feature 'Camera'"];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('invalidOptionsProvider')]
    public function test_invalid_options(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        SecurityHeaders::headers($options, self::HTTPS);
    }

    public function test_send(): void
    {
        $response = WebServer::request('GET', '/security-headers/send');
        $headers = $response['headers'];

        $this->assertSame(['nosniff'], $headers['x-content-type-options'] ?? null);
        $this->assertSame(['SAMEORIGIN'], $headers['x-frame-options'] ?? null);
        $this->assertArrayNotHasKey('x-powered-by', $headers);
        $this->assertArrayNotHasKey('strict-transport-security', $headers);

        $csp = $headers['content-security-policy'][0] ?? '';
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9_-]{24})'/", $csp, $match));
        $this->assertSame('<script nonce="'.($match[1] ?? '').'"></script>', $response['body']);
    }

    public function test_send_after_output(): void
    {
        $body = WebServer::request('GET', '/security-headers/headers-sent')['body'];

        $this->assertMatchesRegularExpression('/^output\|LogicException: SecurityHeaders::send\(\): headers already sent \(output started at .+web-router\.php:\d+\)\.$/', $body);
    }
}
