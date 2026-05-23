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
        $errors = $this->validation->validate($rules, ['password' => '12345']);
        $this->assertArrayHasKey('password', $errors);
        $this->assertContains('min:6', $errors['password']);

        // Too long
        $errors = $this->validation->validate($rules, ['password' => '1234567890123']);
        $this->assertArrayHasKey('password', $errors);
        $this->assertContains('max:12', $errors['password']);

        // Correct size
        $errors = $this->validation->validate($rules, ['password' => '1234567']);
        $this->assertArrayNotHasKey('password', $errors);
    }
}
