<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use InvalidArgumentException;

class UserModelTest extends TestCase
{
    private User $userModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userModel = new User();

        // Ensure database state is reset for testing
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE users;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function testCanCreateAndFindUserByUsername(): void
    {
        $username = 'test_admin';
        $password = 'secure_pass_123';

        $id = $this->userModel->create($username, $password);
        $this->assertGreaterThan(0, $id);

        $user = $this->userModel->findByUsername($username);

        $this->assertNotNull($user);
        $this->assertEquals($id, $user['id']);
        $this->assertEquals($username, $user['username']);
        $this->assertTrue(password_verify($password, $user['password_hash']));
    }

    public function testCannotCreateUserWithEmptyUsernameOrPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->userModel->create('', 'password');
    }

    public function testCannotFindNonExistentUser(): void
    {
        $user = $this->userModel->findByUsername('non_existent_username');
        $this->assertNull($user);
    }
}
