<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use InvalidArgumentException;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    private User $userModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userModel = new User;

        // Ensure database state is reset for testing
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0;');
        $this->db()->getConnection()->exec('TRUNCATE TABLE users;');
        $this->db()->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function test_can_create_and_find_user_by_username(): void
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

    public function test_cannot_create_user_with_empty_username_or_password(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->userModel->create('', 'password');
    }

    public function test_cannot_find_non_existent_user(): void
    {
        $user = $this->userModel->findByUsername('non_existent_username');
        $this->assertNull($user);
    }
}
