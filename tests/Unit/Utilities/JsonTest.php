<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Json;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

final class JsonTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-json-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    // decode

    public function test_decode_returns_associative_array_by_default(): void
    {
        $this->assertSame(['a' => 1, 'b' => [true, null]], Json::decode('{"a":1,"b":[true,null]}'));
    }

    public function test_decode_can_return_objects(): void
    {
        $result = Json::decode('{"a":1}', associative: false);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame(1, $result->a);
    }

    public function test_decode_returns_scalars_and_null_literal(): void
    {
        $this->assertSame(42, Json::decode('42'));
        $this->assertSame('text', Json::decode('"text"'));
        $this->assertNull(Json::decode('null'));
    }

    public function test_decode_keeps_big_integers_as_strings(): void
    {
        $this->assertSame(['id' => '123456789012345678901234567890'], Json::decode('{"id":123456789012345678901234567890}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonProvider(): iterable
    {
        yield 'syntax error' => ['{"a":}'];
        yield 'trailing comma' => ['[1,2,]'];
        yield 'single quotes' => ["{'a':1}"];
        yield 'empty string' => [''];
        yield 'whitespace only' => ["  \n "];
    }

    #[DataProvider('invalidJsonProvider')]
    public function test_decode_throws_on_invalid_json(string $json): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('Json::decode()');

        Json::decode($json);
    }

    public function test_decode_error_message_does_not_leak_input(): void
    {
        try {
            Json::decode('{"cnp":"1800101123456",}');
            $this->fail('Expected JsonException');
        } catch (JsonException $e) {
            $this->assertStringNotContainsString('1800101123456', $e->getMessage());
            $this->assertStringContainsString('input length: 24 bytes', $e->getMessage());
            $this->assertInstanceOf(JsonException::class, $e->getPrevious());
        }
    }

    public function test_decode_respects_depth(): void
    {
        $this->expectException(JsonException::class);

        Json::decode('[[[1]]]', depth: 2);
    }

    public function test_decode_rejects_invalid_depth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('depth must be at least 1');

        Json::decode('[]', depth: 0);
    }

    // decodeArray

    public function test_decode_array_accepts_objects_and_lists(): void
    {
        $this->assertSame(['a' => 1], Json::decodeArray('{"a":1}'));
        $this->assertSame([1, 2], Json::decodeArray('[1,2]'));
        $this->assertSame([], Json::decodeArray('{}'));
    }

    public function test_decode_array_rejects_scalars(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('got int');

        Json::decodeArray('42');
    }

    public function test_decode_array_rejects_null_literal(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('got null');

        Json::decodeArray('null');
    }

    // encode

    public function test_encode_keeps_unicode_and_slashes_readable(): void
    {
        $this->assertSame("{\"oras\":\"Ia\u{0219}i\",\"url\":\"a/b\"}", Json::encode(['oras' => "Ia\u{0219}i", 'url' => 'a/b']));
    }

    public function test_encode_preserves_zero_fraction(): void
    {
        $this->assertSame('{"suma":10.0}', Json::encode(['suma' => 10.0]));
    }

    public function test_encode_pretty(): void
    {
        $this->assertSame("{\n    \"a\": 1\n}", Json::encode(['a' => 1], pretty: true));
    }

    public function test_encode_accepts_extra_flags(): void
    {
        $this->assertSame('{"0":"a"}', Json::encode(['a'], flags: JSON_FORCE_OBJECT));
    }

    public function test_encode_throws_on_invalid_utf8(): void
    {
        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('value type: array');

        Json::encode(['x' => "\xB1\x31"]);
    }

    public function test_encode_ignores_partial_output_flag(): void
    {
        $this->expectException(JsonException::class);

        Json::encode(['x' => "\xB1\x31"], flags: JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function test_encode_throws_on_nan(): void
    {
        $this->expectException(JsonException::class);

        Json::encode(NAN);
    }

    // isValid

    public function test_is_valid(): void
    {
        $this->assertTrue(Json::isValid('{"a":1}'));
        $this->assertTrue(Json::isValid('null'));
        $this->assertFalse(Json::isValid('{a:1}'));
        $this->assertFalse(Json::isValid(''));
    }

    // readFile / readArray

    public function test_read_file(): void
    {
        file_put_contents($this->dir.'/a.json', '{"a":1}');

        $this->assertSame(['a' => 1], Json::readFile($this->dir.'/a.json'));
    }

    public function test_read_file_ignores_utf8_bom(): void
    {
        file_put_contents($this->dir.'/bom.json', "\xEF\xBB\xBF{\"a\":1}");

        $this->assertSame(['a' => 1], Json::readFile($this->dir.'/bom.json'));
    }

    public function test_read_file_throws_if_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Json::readFile($this->dir.'/missing.json');
    }

    public function test_read_file_throws_on_directory(): void
    {
        $this->expectException(RuntimeException::class);

        Json::readFile($this->dir);
    }

    public function test_read_file_throws_on_invalid_json_with_path(): void
    {
        file_put_contents($this->dir.'/bad.json', '{bad}');

        $this->expectException(JsonException::class);
        $this->expectExceptionMessage('bad.json');

        Json::readFile($this->dir.'/bad.json');
    }

    public function test_read_array_rejects_scalar_file(): void
    {
        file_put_contents($this->dir.'/scalar.json', '"text"');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('got string');

        Json::readArray($this->dir.'/scalar.json');
    }

    // writeFile

    public function test_write_file_round_trip(): void
    {
        $data = ['polita' => 'RCA-123', 'suma' => 150.5, 'activ' => true];

        Json::writeFile($this->dir.'/out.json', $data);

        $this->assertSame($data, Json::readArray($this->dir.'/out.json'));
        $this->assertStringEndsWith("\n", (string) file_get_contents($this->dir.'/out.json'));
    }

    public function test_write_file_overwrites(): void
    {
        Json::writeFile($this->dir.'/out.json', ['a' => 1]);
        Json::writeFile($this->dir.'/out.json', ['b' => 2], pretty: false);

        $this->assertSame("{\"b\":2}\n", file_get_contents($this->dir.'/out.json'));
    }

    public function test_write_file_throws_if_directory_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Json::writeFile($this->dir.'/missing/out.json', []);
    }

    public function test_write_file_does_not_create_file_when_encoding_fails(): void
    {
        try {
            Json::writeFile($this->dir.'/out.json', NAN);
            $this->fail('Expected JsonException');
        } catch (JsonException) {
            $this->assertFileDoesNotExist($this->dir.'/out.json');
        }
    }

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            Json::writeFile($this->dir, ['a' => 1]); // directorul exista, dar nu e fisier
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot write', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
