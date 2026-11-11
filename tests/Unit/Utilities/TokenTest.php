<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Token;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class TokenTest extends TestCase
{
    private const string SECRET = 'secret-de-test-cu-cel-putin-32-de-bytes!';

    private const int NOW = 1_800_000_000;

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // random / hash / equals

    public function test_random(): void
    {
        $token = Token::random();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertNotSame($token, Token::random());
        $this->assertSame(22, strlen(Token::random(16)));
    }

    public function test_random_requires_enough_entropy(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Token::random(8);
    }

    public function test_hash_and_equals(): void
    {
        $token = Token::random();

        $this->assertSame(hash('sha256', $token), Token::hash($token));
        $this->assertSame(64, strlen(Token::hash($token)));
        $this->assertTrue(Token::equals($token, $token));
        $this->assertFalse(Token::equals($token, $token.'x'));
    }

    // sign / verify

    public function test_sign_produces_standard_jwt(): void
    {
        $jwt = Token::sign(['sub' => 15], self::SECRET, ttl: 300, now: self::NOW);
        [$header, $payload, $signature] = explode('.', $jwt);

        $this->assertSame('{"alg":"HS256","typ":"JWT"}', base64_decode(strtr($header, '-_', '+/'), true));
        $this->assertSame('{"iat":1800000000,"exp":1800000300,"sub":15}', base64_decode(strtr($payload, '-_', '+/'), true));
        $this->assertSame(self::b64(hash_hmac('sha256', $header.'.'.$payload, self::SECRET, true)), $signature);
    }

    public function test_round_trip(): void
    {
        $jwt = Token::sign(['sub' => 15, 'scope' => 'webhook', 'oras' => "Ia\u{0219}i"], self::SECRET, ttl: 300, now: self::NOW);

        $claims = Token::verify($jwt, self::SECRET, now: self::NOW + 10);

        $this->assertSame(['iat' => self::NOW, 'exp' => self::NOW + 300, 'sub' => 15, 'scope' => 'webhook', 'oras' => "Ia\u{0219}i"], $claims);
    }

    public function test_expired_token(): void
    {
        $jwt = Token::sign([], self::SECRET, ttl: 60, now: self::NOW);

        $this->assertSame(self::NOW + 60, Token::verify($jwt, self::SECRET, now: self::NOW + 60)['exp'], 'valid exact la expirare');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the token has expired');

        Token::verify($jwt, self::SECRET, now: self::NOW + 61);
    }

    public function test_leeway(): void
    {
        $jwt = Token::sign([], self::SECRET, ttl: 60, now: self::NOW);

        $this->assertSame(self::NOW + 60, Token::verify($jwt, self::SECRET, leeway: 30, now: self::NOW + 90)['exp']);
    }

    public function test_not_before(): void
    {
        $jwt = Token::sign(['nbf' => self::NOW + 100], self::SECRET, ttl: 3600, now: self::NOW);

        try {
            Token::verify($jwt, self::SECRET, now: self::NOW);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString('not valid yet', $e->getMessage());
        }

        $this->assertSame(self::NOW + 100, Token::verify($jwt, self::SECRET, now: self::NOW + 100)['nbf']);
    }

    public function test_wrong_secret(): void
    {
        $jwt = Token::sign([], self::SECRET, now: self::NOW);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid signature');

        Token::verify($jwt, str_repeat('x', 32), now: self::NOW);
    }

    public function test_modified_payload_is_rejected(): void
    {
        [$header, , $signature] = explode('.', Token::sign(['role' => 'user'], self::SECRET, now: self::NOW));
        $forged = self::b64('{"iat":1800000000,"exp":1800003600,"role":"admin"}');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid signature');

        Token::verify("{$header}.{$forged}.{$signature}", self::SECRET, now: self::NOW);
    }

    public function test_alg_none_attack_is_rejected(): void
    {
        $header = self::b64('{"alg":"none","typ":"JWT"}');
        $payload = self::b64('{"exp":1900000000,"role":"admin"}');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unsupported algorithm');

        Token::verify("{$header}.{$payload}.", self::SECRET, now: self::NOW);
    }

    public function test_token_without_exp_is_rejected(): void
    {
        $header = self::b64('{"alg":"HS256","typ":"JWT"}');
        $payload = self::b64('{"sub":1}');
        $signature = self::b64(hash_hmac('sha256', "{$header}.{$payload}", self::SECRET, true));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('no valid expiration');

        Token::verify("{$header}.{$payload}.{$signature}", self::SECRET, now: self::NOW);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'doua parti' => ['a.b'];
        yield 'patru parti' => ['a.b.c.d'];
        yield 'base64 invalid' => ['@@@.b.c'];
        yield 'header nu e json' => [self::b64('text').'.b.c'];
        yield 'header lista' => [self::b64('[1]').'.b.c'];
    }

    #[DataProvider('malformedProvider')]
    public function test_malformed_tokens(string $token): void
    {
        $this->expectException(UnexpectedValueException::class);

        Token::verify($token, self::SECRET, now: self::NOW);
    }

    public function test_secret_validation(): void
    {
        foreach ([
            static fn (): string => Token::sign([], 'scurt'),
            static fn (): array => Token::verify('a.b.c', 'scurt'),
        ] as $case) {
            try {
                $case();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('at least 32 bytes', $e->getMessage());
            }
        }
    }

    public function test_sign_parameter_validation(): void
    {
        try {
            Token::sign([], self::SECRET, ttl: 0);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the claims cannot be encoded');

        Token::sign(['x' => NAN], self::SECRET);
    }
}
