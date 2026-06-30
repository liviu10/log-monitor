<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use App\Utilities\Validation;

class ValidationTest extends TestCase
{
    private Validation $validation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validation = new Validation();
    }

    public function testRequiredRuleFailsWhenFieldIsMissing(): void
    {
        $rules = [
            'name' => ['required'],
        ];
        $payload = [];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayHasKey('name', $errors);
        $this->assertContains('required', $errors['name']);
    }

    public function testRequiredRulePassesWhenFieldIsPresent(): void
    {
        $rules = [
            'name' => ['required'],
        ];
        $payload = ['name' => 'John Doe'];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayNotHasKey('name', $errors);
    }

    public function testEmailRuleValidatesIncorrectEmail(): void
    {
        $rules = [
            'email' => ['required', 'email'],
        ];
        $payload = ['email' => 'invalid-email'];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayHasKey('email', $errors);
        $this->assertContains('email', $errors['email']);
    }

    public function testEmailRuleValidatesCorrectEmail(): void
    {
        $rules = [
            'email' => ['required', 'email'],
        ];
        $payload = ['email' => 'test@example.com'];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayNotHasKey('email', $errors);
    }

    public function testTypeChecks(): void
    {
        $rules = [
            'age' => ['int'],
            'username' => ['string'],
        ];

        // Valid payload
        $errors = $this->validation->validate($rules, [
            'age' => 25,
            'username' => 'john_doe',
        ]);
        $this->assertEmpty($errors);

        // Invalid payload
        $errors = $this->validation->validate($rules, [
            'age' => 'not-a-number',
            'username' => ['not-a-string'],
        ]);
        $this->assertArrayHasKey('age', $errors);
        $this->assertArrayHasKey('username', $errors);
    }

    public function testMinAndMaxRules(): void
    {
        $rules = [
            'password' => ['min:6', 'max:12'],
        ];

        // Too short
        $errors = $this->validation->validate($rules, ['password' => 'abcde']);
        $this->assertArrayHasKey('password', $errors);
        $this->assertContains('min:6', $errors['password']);

        // Too long
        $errors = $this->validation->validate($rules, ['password' => 'abcdefghijklm']);
        $this->assertArrayHasKey('password', $errors);
        $this->assertContains('max:12', $errors['password']);

        // Correct size
        $errors = $this->validation->validate($rules, ['password' => 'abcdefg']);
        $this->assertArrayNotHasKey('password', $errors);
    }

    public function testInRule(): void
    {
        $rules = [
            'role' => ['in:admin,user'],
        ];

        // Valid
        $errors = $this->validation->validate($rules, ['role' => 'admin']);
        $this->assertEmpty($errors);

        // Invalid
        $errors = $this->validation->validate($rules, ['role' => 'superadmin']);
        $this->assertArrayHasKey('role', $errors);
        $this->assertContains('in:admin,user', $errors['role']);
    }

    public function testRegexRule(): void
    {
        $rules = [
            'code' => ['regex:/^[A-Z]{3}$/'],
        ];

        // Valid
        $errors = $this->validation->validate($rules, ['code' => 'ABC']);
        $this->assertEmpty($errors);

        // Invalid
        $errors = $this->validation->validate($rules, ['code' => 'ab1']);
        $this->assertArrayHasKey('code', $errors);
        $this->assertContains('regex:/^[A-Z]{3}$/', $errors['code']);
    }

    public function testDateAndArrayRules(): void
    {
        $rules = [
            'created_at' => ['date'],
            'tags' => ['array'],
        ];

        // Valid
        $errors = $this->validation->validate($rules, [
            'created_at' => '2026-05-30',
            'tags' => ['tech', 'news'],
        ]);
        $this->assertEmpty($errors);

        // Invalid
        $errors = $this->validation->validate($rules, [
            'created_at' => 'not-a-date',
            'tags' => 'not-an-array',
        ]);
        $this->assertArrayHasKey('created_at', $errors);
        $this->assertArrayHasKey('tags', $errors);
    }

    public function testMessagesMethodReturnsTranslatedKeys(): void
    {
        $validator = new Validation(['username' => 'User Name']);
        $msg = $validator->messages('username', 'required');
        
        $this->assertStringContainsString('User Name', $msg);
    }
}
