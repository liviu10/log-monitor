<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\Arr;
use App\Utilities\Cache;
use App\Utilities\Csrf;
use App\Utilities\Date;
use App\Utilities\Json;
use App\Utilities\Paginator;
use App\Utilities\RateLimiter;
use App\Utilities\Retry;
use App\Utilities\SecurityHeaders;
use App\Utilities\Session;
use App\Utilities\Str;
use App\Utilities\Token;
use App\Utilities\Validator;
use Tests\TestCase;

class UtilitiesTest extends TestCase
{
    public function test_arr_utilities(): void
    {
        $data = ['user' => ['profile' => ['name' => 'Liviu', 'role' => 'admin']]];

        $this->assertEquals('Liviu', Arr::get($data, 'user.profile.name'));
        $this->assertTrue(Arr::has($data, 'user.profile.role'));
        $this->assertEquals('guest', Arr::get($data, 'user.profile.missing', 'guest'));

        $flattened = Arr::pluck([
            ['id' => 1, 'email' => 'test1@example.com'],
            ['id' => 2, 'email' => 'test2@example.com'],
        ], 'email');

        $this->assertEquals(['test1@example.com', 'test2@example.com'], $flattened);
    }

    public function test_str_utilities(): void
    {
        $this->assertEquals('situatia-platilor-2026', Str::slug('Situația plăților - 2026'));
        $this->assertEquals('created_at', Str::snake('createdAt'));
        $this->assertEquals('createdAt', Str::camel('created_at'));
        $this->assertEquals('Hello...', Str::limit('Hello World and Everyone', 5));
        $this->assertEquals('************1234', Str::mask('1234567812341234', '*', 0, -4));
        $this->assertEquals('World', Str::between('Hello [World] !', '[', ']'));
        $this->assertEquals('World', Str::after('Hello World', 'Hello '));
        $this->assertEquals('Hello', Str::before('Hello World', ' World'));
    }

    public function test_date_utilities(): void
    {
        $this->assertEquals('15.08.2026', Date::dbToRo('2026-08-15'));
        $this->assertEquals('2026-08-15', Date::roToDb('15.08.2026'));

        $weekend = new \DateTimeImmutable('2026-11-14'); // Saturday
        $this->assertTrue(Date::isWeekend($weekend));
    }

    public function test_token_utilities(): void
    {
        $token = Token::random(32);
        $this->assertNotEmpty($token);

        $hash = Token::hash($token);
        $this->assertEquals(64, strlen($hash));

        $secret = 'super-secret-key-that-is-at-least-32-bytes-long!';
        $jwt = Token::sign(['sub' => 123, 'role' => 'admin'], $secret, ttl: 60);
        $claims = Token::verify($jwt, $secret);

        $this->assertEquals(123, $claims['sub']);
        $this->assertEquals('admin', $claims['role']);
    }

    public function test_csrf_utilities(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $token = Csrf::token();
        $this->assertEquals(64, strlen($token));
        $this->assertTrue(Csrf::verify($token));
        $this->assertFalse(Csrf::verify('invalid-token'));
        $this->assertStringContainsString('name="_token"', Csrf::field());
    }

    public function test_security_headers_utilities(): void
    {
        $headers = SecurityHeaders::headers([
            'csp' => ['script-src' => "'self'"],
            'frameOptions' => 'DENY',
        ]);

        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertEquals('DENY', $headers['X-Frame-Options']);
        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertEquals('nosniff', $headers['X-Content-Type-Options']);
        $this->assertNotEmpty(SecurityHeaders::nonce());
    }

    public function test_paginator_utilities(): void
    {
        $pager = Paginator::fromRequest([
            'page' => '2',
            'per_page' => '25',
            'sort' => 'id',
            'order' => 'desc',
        ], sortable: ['id' => 'id', 'created' => 'created_at'], defaultSort: 'id');

        $this->assertEquals(2, $pager->page);
        $this->assertEquals(25, $pager->perPage);
        $this->assertEquals('id DESC', $pager->orderBy());
    }

    public function test_retry_utilities(): void
    {
        $attempts = 0;
        $result = Retry::run(function (int $attempt) use (&$attempts): string {
            $attempts = $attempt;
            if ($attempt < 2) {
                throw new \RuntimeException('Transient error');
            }

            return 'success';
        }, times: 3, delayMs: 1);

        $this->assertEquals(2, $attempts);
        $this->assertEquals('success', $result);
    }

    public function test_json_utilities(): void
    {
        $encoded = Json::encode(['status' => 'ok', 'count' => 5]);
        $this->assertEquals('{"status":"ok","count":5}', $encoded);

        $this->assertTrue(Json::isValid($encoded));
        $this->assertFalse(Json::isValid('{invalid-json'));

        $decoded = Json::decodeArray($encoded);
        $this->assertEquals(['status' => 'ok', 'count' => 5], $decoded);
    }

    public function test_cache_utilities(): void
    {
        $cacheDir = sys_get_temp_dir().'/logmonitor_test_cache_'.bin2hex(random_bytes(4));
        mkdir($cacheDir, 0777, true);

        try {
            $cache = new Cache($cacheDir);
            $cache->set('my_key', ['score' => 99], ttl: 60);

            $this->assertTrue($cache->has('my_key'));
            $this->assertEquals(['score' => 99], $cache->get('my_key'));

            $computed = $cache->remember('remember_key', 60, fn () => 'computed_value');
            $this->assertEquals('computed_value', $computed);
            $this->assertEquals('computed_value', $cache->get('remember_key'));

            $cache->forget('my_key');
            $this->assertFalse($cache->has('my_key'));
        } finally {
            $files = glob($cacheDir.'/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($cacheDir);
        }
    }

    public function test_rate_limiter_utilities(): void
    {
        $limitDir = sys_get_temp_dir().'/logmonitor_test_limits_'.bin2hex(random_bytes(4));
        mkdir($limitDir, 0777, true);

        try {
            $limiter = new RateLimiter($limitDir);
            $key = 'test_ip:127.0.0.1';

            $this->assertTrue($limiter->attempt($key, maxAttempts: 2, decaySeconds: 60));
            $this->assertTrue($limiter->attempt($key, maxAttempts: 2, decaySeconds: 60));
            $this->assertFalse($limiter->attempt($key, maxAttempts: 2, decaySeconds: 60));

            $this->assertTrue($limiter->tooManyAttempts($key, maxAttempts: 2));
            $limiter->clear($key);
            $this->assertFalse($limiter->tooManyAttempts($key, maxAttempts: 2));
        } finally {
            $files = glob($limitDir.'/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
            @rmdir($limitDir);
        }
    }

    public function test_session_utilities(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        Session::set('auth_mode', 'standard');
        $this->assertTrue(Session::has('auth_mode'));
        $this->assertEquals('standard', Session::get('auth_mode'));

        $pulled = Session::pull('auth_mode');
        $this->assertEquals('standard', $pulled);
        $this->assertFalse(Session::has('auth_mode'));
    }

    public function test_validator_utilities(): void
    {
        $validator = new Validator([
            'email' => 'admin@example.com',
            'age' => '30',
        ], [
            'email' => 'required|email',
            'age' => 'required|int|between:18,99',
        ]);

        $this->assertFalse($validator->fails());
        $this->assertEquals('admin@example.com', $validator->validated()['email']);

        $failing = new Validator([
            'email' => 'not-an-email',
            'age' => '12',
        ], [
            'email' => 'required|email',
            'age' => 'required|int|between:18,99',
        ]);

        $this->assertTrue($failing->fails());
        $this->assertArrayHasKey('email', $failing->errors());
        $this->assertArrayHasKey('age', $failing->errors());
    }
}
