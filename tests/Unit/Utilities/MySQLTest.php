<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Utilities\MySQL;
use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use UnexpectedValueException;

/**
 * Testele fara server ruleaza mereu. Testele de integrare ruleaza doar daca sunt setate
 * MYSQL_TEST_HOST, MYSQL_TEST_DATABASE, MYSQL_TEST_USERNAME, MYSQL_TEST_PASSWORD (si optional
 * MYSQL_TEST_PORT); ele creeaza si sterg tabela utilities_test_users.
 */
final class MySQLTest extends TestCase
{
    private const string TABLE = 'utilities_test_users';

    // fara server

    /**
     * @return iterable<string, array{array<string, string|int>, string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'host gol' => [['host' => ''], 'host must not be empty'];
        yield 'injectie in DSN' => [['database' => 'app;unix_socket=/tmp/x'], "database must not be empty or contain ';'"];
        yield 'utilizator gol' => [['username' => ''], 'username must not be empty'];
        yield 'port invalid' => [['port' => 0], 'invalid port (0)'];
    }

    /**
     * @param  array<string, string|int>  $override
     */
    #[DataProvider('invalidConfigProvider')]
    public function test_constructor_validation(array $override, string $message): void
    {
        $config = [...['host' => 'localhost', 'database' => 'app', 'username' => 'user', 'password' => 'secret', 'port' => 3306], ...$override];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new MySQL(
            host: (string) $config['host'],
            database: (string) $config['database'],
            username: (string) $config['username'],
            password: (string) $config['password'],
            port: (int) $config['port'],
        );
    }

    public function test_connection_is_lazy_and_validation_needs_no_server(): void
    {
        $db = $this->unreachable();

        $this->assertSame('`users`', $db->quoteIdentifier('users'));
        $this->assertSame('`app`.`users`', $db->quoteIdentifier('app.users'));
        $this->assertSame(['`id` IN (?, ?)', [1, 2]], $db->whereIn('id', [1, 2]));
        $this->assertFalse($db->inTransaction());

        foreach ([
            static function () use ($db): void {
                $db->insert('users; DROP TABLE users', ['name' => 'x']);
            },
            static fn (): int => $db->update('users', ['name' => 'x'], []),
            static fn (): int => $db->delete('users', []),
            static fn (): string => $db->quoteIdentifier(str_repeat('a', 65)),
            static fn (): array => $db->fetchAll('SELECT ?', [[1]]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('MySQL::', $e->getMessage());
            }
        }
    }

    public function test_connection_failure_message_has_no_password(): void
    {
        $db = $this->unreachable();

        try {
            $db->connect();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith("MySQL::connect(): cannot connect to '127.0.0.1:1/app':", $e->getMessage());
            $this->assertStringNotContainsString('parola-secreta', $e->getMessage());
            $this->assertStringNotContainsString('parola-secreta', $e->getTraceAsString());
        }
    }

    public function test_values_are_masked_in_driver_messages(): void
    {
        $failed = new ReflectionMethod(MySQL::class, 'queryFailed');

        $duplicate = new PDOException('SQLSTATE[23000]');
        $duplicate->errorInfo = ['23000', 1062, "Duplicate entry '1960101123456' for key 'users.cnp'"];
        $message = $failed->invoke($this->unreachable(), 'insert', 'INSERT INTO `users` (`cnp`) VALUES (?)', $duplicate);

        $this->assertInstanceOf(RuntimeException::class, $message);
        $this->assertSame(
            "MySQL::insert(): query failed [SQLSTATE 23000, code 1062]: Duplicate entry '?' for key 'users.cnp' SQL: INSERT INTO `users` (`cnp`) VALUES (?)",
            $message->getMessage(),
        );

        $incorrect = new PDOException('SQLSTATE[HY000]');
        $incorrect->errorInfo = ['HY000', 1366, "Incorrect integer value: 'ion@x.ro' for column 'age' at row 1"];
        $message = $failed->invoke($this->unreachable(), 'update', 'UPDATE x', $incorrect);

        $this->assertInstanceOf(RuntimeException::class, $message);
        $this->assertStringContainsString("Incorrect integer value: '?' for column 'age' at row 1", $message->getMessage());
    }

    public function test_insert_many_validates_before_connecting(): void
    {
        $db = $this->unreachable();

        foreach ([
            [[['a' => 1], ['b' => 2]], 'row #1 does not have the same columns as the first row'],
            [[['a' => 1]], "invalid identifier 'users;'", 'users;'],
        ] as $case) {
            try {
                $db->insertMany($case[2] ?? 'users', $case[0]);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($case[1], $e->getMessage());
            }
        }

        $this->assertSame(0, $db->insertMany('users', []));
    }

    public function test_cursor_uses_its_own_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MySQL::cursor(): cannot connect to '127.0.0.1:1/app'");

        $this->unreachable()->cursor('SELECT 1');
    }

    // integrare (server real)

    public function test_integration_crud_types_and_errors(): void
    {
        $db = $this->server();
        $table = self::TABLE;

        $id = $db->insertGetId($table, ['name' => 'Ion', 'email' => 'ion@x.ro', 'score' => '12.50', 'created_at' => new DateTimeImmutable('2026-03-15 10:20:30'), 'active' => true]);
        $db->insert($table, ['name' => 'Ana', 'email' => 'ana@x.ro', 'active' => false]);

        $this->assertIsInt($id);
        $this->assertSame(
            ['id' => $id, 'name' => 'Ion', 'score' => '12.50', 'created_at' => '2026-03-15 10:20:30', 'active' => 1],
            $db->fetchOneOrFail("SELECT id, name, score, created_at, active FROM {$table} WHERE id = :id", ['id' => $id]),
            'INT nativ, DECIMAL string',
        );
        $this->assertSame([$id => 'Ion'], $db->fetchPairs("SELECT id, name FROM {$table} WHERE active = ?", [true]));
        $this->assertSame(1, $db->update($table, ['active' => 0], ['id' => $id]));
        $this->assertSame(2, $db->fetchValue("SELECT COUNT(*) FROM {$table} WHERE active = 0"));
        $this->assertSame(1, $db->delete($table, ['email' => ['ana@x.ro', 'lipsa@x.ro']]));

        try {
            $db->insert($table, ['name' => 'Alt Ion', 'email' => 'ion@x.ro']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("[SQLSTATE 23000, code 1062]: Duplicate entry '?' for key", $e->getMessage());
            $this->assertStringNotContainsString('ion@x.ro', $e->getMessage());
        }

        $this->expectException(OutOfBoundsException::class);
        $db->fetchOneOrFail("SELECT * FROM {$table} WHERE id = ?", [-1]);
    }

    public function test_integration_multi_statements_are_rejected(): void
    {
        $db = $this->server();

        $this->expectException(RuntimeException::class);

        $db->fetchAll('SELECT 1; SELECT 2');
    }

    public function test_integration_transaction_and_paginate(): void
    {
        $db = $this->server();
        $table = self::TABLE;

        try {
            $db->transaction(static function (MySQL $db) use ($table): void {
                $db->insert($table, ['name' => 'Eva']);
                $db->insert($table, ['name' => 'Eva', 'email' => null, 'score' => 'nu-e-numar']);
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $db->fetchValue("SELECT COUNT(*) FROM {$table}"), 'rollback');

        for ($i = 1; $i <= 25; $i++) {
            $db->insert($table, ['name' => 'U'.$i]);
        }
        $page = $db->paginate("SELECT name FROM {$table} ORDER BY id", [], 3, 10);

        $this->assertSame(25, $page['total']);
        $this->assertSame(3, $page['pages']);
        $this->assertSame(['U21', 'U22', 'U23', 'U24', 'U25'], array_column($page['items'], 'name'));

        $this->expectException(UnexpectedValueException::class);
        $db->fetchOne("SELECT name FROM {$table}");
    }

    public function test_integration_insert_many_and_cursor(): void
    {
        $db = $this->server();
        $table = self::TABLE;

        $rows = array_map(static fn (int $i): array => ['name' => "U{$i}", 'email' => "u{$i}@x.ro", 'score' => '1.50'], range(1, 2500));
        $this->assertSame(2500, $db->insertMany($table, $rows));

        // Cursorul are conexiunea lui: conexiunea principala poate scrie in timpul buclei.
        $count = 0;
        foreach ($db->cursor("SELECT id, name FROM {$table} ORDER BY id") as $row) {
            if ($count === 0) {
                $db->update($table, ['active' => 0], ['id' => $row['id']]);
            }
            $count++;
        }

        $this->assertSame(2500, $count);
        $this->assertSame(1, $db->fetchValue("SELECT COUNT(*) FROM {$table} WHERE active = 0"));

        $rows[2000]['email'] = 'u1@x.ro';
        try {
            $db->insertMany($table, $rows);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }
        $this->assertSame(2500, $db->fetchValue("SELECT COUNT(*) FROM {$table}"), 'tot sau nimic');
    }

    private function unreachable(): MySQL
    {
        return new MySQL(host: '127.0.0.1', database: 'app', username: 'user', password: 'parola-secreta', port: 1, timeout: 2);
    }

    /**
     * Conexiunea la serverul de test, cu tabela de test recreata; testul este sarit fara server.
     */
    private function server(): MySQL
    {
        $host = getenv('MYSQL_TEST_HOST');
        $database = getenv('MYSQL_TEST_DATABASE');
        $username = getenv('MYSQL_TEST_USERNAME');
        $password = getenv('MYSQL_TEST_PASSWORD');
        $port = getenv('MYSQL_TEST_PORT');

        if (! is_string($host) || ! is_string($database) || ! is_string($username) || ! is_string($password)) {
            self::markTestSkipped('MySQL server not configured (MYSQL_TEST_* environment variables).');
        }

        $db = new MySQL($host, $database, $username, $password, is_string($port) ? (int) $port : 3306);
        $table = self::TABLE;
        $db->execute("DROP TABLE IF EXISTS {$table}");
        $db->execute("CREATE TABLE {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            email VARCHAR(100) NULL UNIQUE,
            score DECIMAL(10, 2) NULL,
            created_at DATETIME NULL,
            active TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB");

        return $db;
    }
}
