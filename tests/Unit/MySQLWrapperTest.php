<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\MySQLWrapper;
use PDO;
use RuntimeException;
use Tests\TestCase;

class MySQLWrapperTest extends TestCase
{
    private MySQLWrapper $dbWrapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbWrapper = MySQLWrapper::getInstance();

        $this->dbWrapper->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->dbWrapper->getConnection()->exec('TRUNCATE TABLE apps;');
        $this->dbWrapper->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function test_singleton_instance_is_unique(): void
    {
        $instance1 = MySQLWrapper::getInstance();
        $instance2 = MySQLWrapper::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function test_get_connection_returns_pdo_instance(): void
    {
        $conn = $this->dbWrapper->getConnection();
        $this->assertInstanceOf(PDO::class, $conn);
    }

    public function test_crud_operations_on_database(): void
    {
        $table = 'apps';

        // 1. Create
        $insertData = [
            'name' => 'MySQLWrapper Integration App',
            'api_key' => 'wrapper-key-999',
        ];
        $insertResult = $this->dbWrapper->create($table, $insertData);
        $this->assertGreaterThan(0, $insertResult);

        // 2. Read
        $records = $this->dbWrapper->read($table, ['api_key' => 'wrapper-key-999']);
        $this->assertCount(1, $records);
        $this->assertEquals('MySQLWrapper Integration App', $records[0]['name']);

        // 3. Update
        $updateData = ['name' => 'MySQLWrapper Updated App'];
        $updateResult = $this->dbWrapper->update($table, $updateData, ['api_key' => 'wrapper-key-999']);
        $this->assertEquals(1, $updateResult);

        // Verify update
        $recordsAfterUpdate = $this->dbWrapper->read($table, ['api_key' => 'wrapper-key-999']);
        $this->assertEquals('MySQLWrapper Updated App', $recordsAfterUpdate[0]['name']);

        // 4. Delete
        $deleteResult = $this->dbWrapper->delete($table, ['api_key' => 'wrapper-key-999']);
        $this->assertEquals(1, $deleteResult);

        // Verify delete
        $recordsAfterDelete = $this->dbWrapper->read($table, ['api_key' => 'wrapper-key-999']);
        $this->assertEmpty($recordsAfterDelete);
    }

    public function test_delete_without_conditions_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->dbWrapper->delete('apps', []);
    }
}
