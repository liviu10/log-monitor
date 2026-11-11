<?php

declare(strict_types=1);

namespace App\Utilities;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Token Class
 *
 * Random tokens and signed tokens with expiration (JWT HS256).
 *
 * Random tokens (password reset, email confirmation, API keys):
 *   - random() generates a secure URL-safe token;
 *   - store only hash($token) in the database, never the token itself (a database leak
 *     will not expose valid tokens);
 *   - comparison is performed with equals() in constant time.
 *
 * Signed tokens (webhooks, temporary links, service-to-service authentication):
 *   - sign() produces a standard JWT (HS256), compatible with other systems;
 *   - verify() validates the algorithm (HS256 only: blocks the "alg: none" attack), the signature
 *     in constant time, expiration (exp), and "not before" (nbf);
 *   - the secret must be at least 32 bytes long; there is NO default secret.
 *
 * Usage:
 *   require_once 'Token.php';
 *   use App\Utilities\Token;
 *
 *   $token = Token::random();                              // sent via email
 *   $db->insert('password_resets', ['token_hash' => Token::hash($token), ...]);
 *   // on confirmation: lookup by Token::hash($receivedToken)
 *
 *   $jwt = Token::sign(['sub' => 15, 'scope' => 'webhook'], $secret, ttl: 300);
 *   $claims = Token::verify($jwt, $secret);               // throws an exception if invalid or expired
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Token
{
    /** @var int Minimum secret length in bytes. */
    private const int MIN_SECRET_LENGTH = 32;

    /** @var array{alg: string, typ: string} JWT header. */
    private const array HEADER = ['alg' => 'HS256', 'typ' => 'JWT'];

    private function __construct() {}

    /**
     * Random URL-safe token (base64url without padding).
     *
     * @param  int  $bytes  Entropy in bytes (minimum 16; 32 = 43 characters).
     *
     * @throws InvalidArgumentException If $bytes < 16.
     */
    public static function random(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('Token::random(): at least 16 bytes of entropy are required.');
        }

        return self::base64UrlEncode(random_bytes($bytes));
    }

    /**
     * SHA-256 hash (hex) of a token for database storage.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Compares two tokens in constant time.
     *
     * @param  string  $known  Known value (from session / database).
     * @param  string  $given  Value received from user.
     */
    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /**
     * Creates a signed HS256 JWT. Automatically adds iat (now) and exp (now + $ttl).
     *
     * @param  array<string, mixed>  $claims  Token payload data (e.g., sub, scope); do not store secrets:
     *                                        JWT payload content is merely encoded, not encrypted.
     * @param  string  $secret  Secret key (at least 32 bytes).
     * @param  int  $ttl  Time-to-live in seconds.
     * @param  int|null  $now  Current timestamp; null = current time. Useful in tests.
     *
     * @throws InvalidArgumentException On short secret, $ttl < 1, or claims that cannot be encoded.
     */
    public static function sign(array $claims, string $secret, int $ttl = 3600, ?int $now = null): string
    {
        self::assertSecret('sign', $secret);

        if ($ttl < 1) {
            throw new InvalidArgumentException('Token::sign(): $ttl must be at least 1 second.');
        }

        $now ??= time();
        $claims = ['iat' => $now, 'exp' => $now + $ttl] + $claims;

        try {
            $header = self::base64UrlEncode(json_encode(self::HEADER, JSON_THROW_ON_ERROR));
            $payload = self::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Token::sign(): the claims cannot be encoded: '.$e->getMessage().'.', 0, $e);
        }

        $signature = self::base64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /**
     * Verifies an HS256 JWT and returns its claims.
     *
     * @param  string  $token  Received JWT.
     * @param  string  $secret  Same secret as used in sign().
     * @param  int  $leeway  Tolerance in seconds for unsynchronized clocks between servers.
     * @param  int|null  $now  Current timestamp; null = current time. Useful in tests.
     * @return array<array-key, mixed> Claims, including iat and exp.
     *
     * @throws InvalidArgumentException On short secret or negative $leeway.
     * @throws UnexpectedValueException If the token is malformed, has an unsupported algorithm,
     *                                  an invalid signature, has expired, or is not yet valid.
     */
    public static function verify(string $token, string $secret, int $leeway = 0, ?int $now = null): array
    {
        self::assertSecret('verify', $secret);

        if ($leeway < 0) {
            throw new InvalidArgumentException('Token::verify(): $leeway cannot be negative.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Token::verify(): malformed token.');
        }

        [$header, $payload, $signature] = $parts;

        // Intai algoritmul: orice altceva decat HS256 (inclusiv "none") este respins.
        $decodedHeader = self::decodeJson($header);
        if (($decodedHeader['alg'] ?? null) !== 'HS256') {
            throw new UnexpectedValueException('Token::verify(): unsupported algorithm (only HS256 is accepted).');
        }

        $expected = hash_hmac('sha256', $header.'.'.$payload, $secret, true);
        if (! hash_equals($expected, self::base64UrlDecode($signature))) {
            throw new UnexpectedValueException('Token::verify(): invalid signature.');
        }

        $claims = self::decodeJson($payload);
        $now ??= time();

        if (! isset($claims['exp']) || ! is_int($claims['exp'])) {
            throw new UnexpectedValueException('Token::verify(): the token has no valid expiration (exp).');
        }

        if ($now > $claims['exp'] + $leeway) {
            throw new UnexpectedValueException('Token::verify(): the token has expired.');
        }

        if (isset($claims['nbf']) && (! is_int($claims['nbf']) || $now + $leeway < $claims['nbf'])) {
            throw new UnexpectedValueException('Token::verify(): the token is not valid yet (nbf).');
        }

        return $claims;
    }

    /**
     * @throws InvalidArgumentException If the secret is too short.
     */
    private static function assertSecret(string $method, string $secret): void
    {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw new InvalidArgumentException("Token::{$method}(): the secret must be at least ".self::MIN_SECRET_LENGTH.' bytes long.');
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException If the part is not a valid JSON object.
     */
    private static function decodeJson(string $part): array
    {
        try {
            $decoded = json_decode(self::base64UrlDecode($part), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Token::verify(): malformed token.');
        }

        if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            throw new UnexpectedValueException('Token::verify(): malformed token.');
        }

        return $decoded;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @throws UnexpectedValueException If the string is not valid base64url.
     */
    private static function base64UrlDecode(string $data): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $data) !== 1) {
            throw new UnexpectedValueException('Token::verify(): malformed token.');
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new UnexpectedValueException('Token::verify(): malformed token.');
        }

        return $decoded;
    }
}
