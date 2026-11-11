<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\Cache;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

final class CacheTest extends TestCase
{
    private string $dir;

    private Cache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-cache-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->cache = new Cache($this->dir);
    }

    protected function tearDown(): void
    {
        self::deleteDir($this->dir);
    }

    /**
     * Fisierul de cache al unei chei (pentru teste care il modifica direct).
     */
    private function file(string $key): string
    {
        return $this->dir.'/'.hash('sha256', $key).'.cache.json';
    }

    public function test_set_and_get_json_values(): void
    {
        $values = ['text' => "Ia\u{0219}i", 'int' => 42, 'float' => 1.0, 'bool' => false, 'list' => [1, [2, 3]], 'map' => ['a' => null]];

        foreach ($values as $key => $value) {
            $this->cache->set($key, $value);
            $this->assertSame($value, $this->cache->get($key), $key);
        }
    }

    public function test_get_default_and_has(): void
    {
        $this->assertNull($this->cache->get('lipsa'));
        $this->assertSame('implicit', $this->cache->get('lipsa', 'implicit'));
        $this->assertFalse($this->cache->has('lipsa'));

        $this->cache->set('null', null);
        $this->assertTrue($this->cache->has('null'), 'un null stocat este un hit');
        $this->assertNull($this->cache->get('null', 'implicit'));
    }

    public function test_expiration(): void
    {
        $this->cache->set('a', 'valoare', ttl: 60);
        $this->assertSame('valoare', $this->cache->get('a'));

        // Simuleaza trecerea timpului: expira acum o secunda.
        $entry = json_decode((string) file_get_contents($this->file('a')), true);
        $this->assertIsArray($entry);
        $entry['expires'] = time() - 1;
        file_put_contents($this->file('a'), json_encode($entry));

        $this->assertFalse($this->cache->has('a'));
        $this->assertFileDoesNotExist($this->file('a'), 'intrarea expirata este stearsa la citire');
    }

    public function test_remember_calls_callback_only_on_miss(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): array {
            $calls++;

            return ['curs' => 4.97];
        };

        $this->assertSame(['curs' => 4.97], $this->cache->remember('bnr', 60, $callback));
        $this->assertSame(['curs' => 4.97], $this->cache->remember('bnr', 60, $callback));
        $this->assertSame(1, $calls);
    }

    public function test_remember_caches_null(): void
    {
        $calls = 0;
        $callback = static function () use (&$calls): ?string {
            $calls++;

            return null;
        };

        $this->cache->remember('x', null, $callback);
        $this->cache->remember('x', null, $callback);

        $this->assertSame(1, $calls);
    }

    public function test_forget_and_clear(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        file_put_contents($this->dir.'/alt-fisier.txt', 'nu este cache');

        $this->assertTrue($this->cache->forget('a'));
        $this->assertFalse($this->cache->forget('a'));
        $this->assertSame(1, $this->cache->clear());
        $this->assertFalse($this->cache->has('b'));
        $this->assertFileExists($this->dir.'/alt-fisier.txt', 'clear() sterge doar fisierele de cache');
    }

    public function test_prune_removes_only_expired_and_corrupted(): void
    {
        $this->cache->set('valid', 1, ttl: 3600);
        $this->cache->set('etern', 2);
        $this->cache->set('expirat', 3, ttl: 60);
        $entry = json_decode((string) file_get_contents($this->file('expirat')), true);
        $this->assertIsArray($entry);
        $entry['expires'] = time() - 10;
        file_put_contents($this->file('expirat'), json_encode($entry));
        file_put_contents($this->dir.'/corupt.cache.json', '{gresit');

        $this->assertSame(2, $this->cache->prune());
        $this->assertTrue($this->cache->has('valid'));
        $this->assertTrue($this->cache->has('etern'));
    }

    public function test_corrupted_file_throws(): void
    {
        $this->cache->set('a', 1);
        file_put_contents($this->file('a'), '{"value":1}');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('corrupted cache file');

        $this->cache->get('a');
    }

    public function test_objects_and_resources_cannot_be_cached(): void
    {
        foreach ([new stdClass, ['a' => ['b' => fopen('php://memory', 'r')]], NAN] as $value) {
            try {
                $this->cache->set('x', $value);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('only scalars, null and arrays can be cached', $e->getMessage());
            }
        }

        $this->assertFalse($this->cache->has('x'));
    }

    public function test_invalid_ttl_and_key(): void
    {
        try {
            $this->cache->set('a', 1, ttl: 0);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('$ttl must be at least 1', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);

        $this->cache->get('');
    }

    public function test_keys_with_any_characters(): void
    {
        $this->cache->set('../../etc/passwd', 'sigur');
        $this->cache->set('user:15/profil?x=1', 'ok');

        $this->assertSame('sigur', $this->cache->get('../../etc/passwd'));
        $this->assertSame('ok', $this->cache->get('user:15/profil?x=1'));
        $this->assertCount(2, self::files($this->dir), 'fisierele raman in directorul cache-ului');
    }

    public function test_no_temporary_files_left(): void
    {
        $this->cache->set('a', str_repeat('x', 10000));
        $this->cache->set('a', 'nou');

        $this->assertSame([basename($this->file('a'))], array_map(basename(...), self::files($this->dir)));
    }

    public function test_directory_must_exist(): void
    {
        $this->expectException(RuntimeException::class);

        new Cache($this->dir.'/lipsa');
    }

    private static function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? self::deleteDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        return array_values(array_filter(glob($dir.'/*') ?: [], 'is_file'));
    }
}
