<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Utilities\Validation;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    private Validation $validation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validation = new Validation;
    }

    public function test_required_rule_fails_when_field_is_missing(): void
    {
        $rules = [
            'name' => ['required'],
        ];
        $payload = [];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayHasKey('name', $errors);
        $this->assertContains('required', $errors['name']);
    }

    public function test_required_rule_passes_when_field_is_present(): void
    {
        $rules = [
            'name' => ['required'],
        ];
        $payload = ['name' => 'John Doe'];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayNotHasKey('name', $errors);
    }

    public function test_email_rule_validates_incorrect_email(): void
    {
        $rules = [
            'email' => ['required', 'email'],
        ];
        $payload = ['email' => 'invalid-email'];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayHasKey('email', $errors);
        $this->assertContains('email', $errors['email']);
    }

    public function test_email_rule_validates_correct_email(): void
    {
        $rules = [
            'email' => ['required', 'email'],
        ];
        $payload = ['email' => 'test@example.com'];

        $errors = $this->validation->validate($rules, $payload);

        $this->assertArrayNotHasKey('email', $errors);
    }

    public function test_type_checks(): void
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

    public function test_min_and_max_rules(): void
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

    public function test_in_rule(): void
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

    public function test_regex_rule(): void
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

    public function test_date_and_array_rules(): void
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

    public function test_messages_method_returns_translated_keys(): void
    {
        $validator = new Validation(['username' => 'User Name']);
        $msg = $validator->messages('username', 'required');

        $this->assertStringContainsString('User Name', $msg);
    }
}
