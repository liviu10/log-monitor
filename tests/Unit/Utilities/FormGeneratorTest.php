<?php

declare(strict_types=1);

namespace Tests\Unit\Utilities;

use App\Core\MySQLWrapper;
use App\Utilities\FormGenerator;
use InvalidArgumentException;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Dummy BackedEnum for testing enum override functionality.
 */
enum TestStatusEnum: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}

/**
 * Unit tests for the FormGenerator class.
 */
class FormGeneratorTest extends TestCase
{
    /**
     * Creates a mock for the MySQLWrapper class.
     *
     * @return MockObject&MySQLWrapper
     */
    private function getMockDb()
    {
        return $this->createMock(MySQLWrapper::class);
    }

    /**
     * Creates a mock for the PDOStatement class.
     *
     * @return MockObject&PDOStatement
     */
    private function getMockStmt()
    {
        return $this->createMock(PDOStatement::class);
    }

    /**
     * Tests that buildForm correctly maps standard database types to form types.
     */
    public function test_build_form_with_standard_columns(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();

        // Mocking SHOW COLUMNS result
        $columns = [
            ['Field' => 'id', 'Type' => 'int', 'Null' => 'NO'],
            ['Field' => 'username', 'Type' => 'varchar(50)', 'Null' => 'NO'],
            ['Field' => 'bio', 'Type' => 'text', 'Null' => 'YES'],
            ['Field' => 'is_active', 'Type' => 'tinyint', 'Null' => 'NO'],
            ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'YES'],
            ['Field' => 'birthdate', 'Type' => 'date', 'Null' => 'YES'],
            ['Field' => 'custom_data', 'Type' => 'json', 'Null' => 'YES'],
        ];

        $stmt->method('fetchAll')->willReturn($columns);
        $db->method('query')->willReturn($stmt);

        $generator = new FormGenerator($db, 'users', ['id', 'username', 'bio', 'is_active', 'created_at', 'birthdate', 'custom_data']);
        $form = $generator->buildForm();

        self::assertCount(7, $form);

        // Assertions for specific type mapping
        self::assertEquals('number', $form[0]['type']); // int
        self::assertEquals('text', $form[1]['type']);  // varchar
        self::assertEquals('textarea', $form[2]['type']); // text
        self::assertEquals('checkbox', $form[3]['type']); // tinyint
        self::assertEquals('datetime-local', $form[4]['type']); // datetime
        self::assertEquals('date', $form[5]['type']); // date
        self::assertEquals('text', $form[6]['type']); // default (json)

        // Assertions for requirement (Null => NO -> required: true)
        self::assertTrue($form[0]['required']);
        self::assertFalse($form[2]['required']);
    }

    /**
     * Tests that buildForm correctly handles MySQL ENUM columns.
     */
    public function test_build_form_with_enum_column(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();

        $columns = [
            ['Field' => 'status', 'Type' => 'enum(\'pending\', \'approved\', \'rejected\')', 'Null' => 'NO'],
        ];

        $stmt->method('fetchAll')->willReturn($columns);
        $db->method('query')->willReturn($stmt);

        $generator = new FormGenerator($db, 'orders', ['status']);
        $form = $generator->buildForm();

        self::assertEquals('select', $form[0]['type']);
        self::assertIsArray($form[0]['options']);
        self::assertCount(3, $form[0]['options']);
        self::assertEquals('pending', $form[0]['options'][0]['value']);
    }

    /**
     * Tests that PHP BackedEnums passed via the $enums parameter override the form field.
     */
    public function test_build_form_with_php_enum_override(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();

        // Database might just have a varchar, but we want to use our Enum
        $columns = [
            ['Field' => 'status', 'Type' => 'varchar(20)', 'Null' => 'NO'],
        ];

        $stmt->method('fetchAll')->willReturn($columns);
        $db->method('query')->willReturn($stmt);

        $generator = new FormGenerator(
            $db,
            'orders',
            ['status'],
            ['status' => TestStatusEnum::class]
        );

        $form = $generator->buildForm();

        self::assertEquals('select', $form[0]['type']);
        self::assertIsArray($form[0]['options']);
        self::assertCount(2, $form[0]['options']);
        self::assertEquals('active', $form[0]['options'][0]['value']);
        self::assertEquals('inactive', $form[0]['options'][1]['value']);
    }

    /**
     * Tests that virtual fields (password, files, tags) are correctly identified.
     */
    public function test_build_form_with_virtual_fields(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();

        // Empty columns because virtual fields aren't in the DB
        $stmt->method('fetchAll')->willReturn([]);
        $db->method('query')->willReturn($stmt);

        $generator = new FormGenerator($db, 'users', ['password', 'content_media_files', 'tags']);
        $form = $generator->buildForm();

        self::assertEquals('password', $form[0]['type']);
        self::assertTrue($form[0]['required']);

        self::assertEquals('file', $form[1]['type']);
        self::assertTrue($form[1]['multiple']);
        self::assertFalse($form[1]['required']);

        self::assertEquals('text', $form[2]['type']);
        self::assertFalse($form[2]['required']);
    }

    /**
     * Tests that an exception is thrown if the table name contains invalid characters.
     */
    public function test_build_form_throws_exception_for_invalid_table_name(): void
    {
        $db = $this->getMockDb();
        $generator = new FormGenerator($db, 'invalid-table-name!');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Numele tabelei contine caractere nepermise.');

        $generator->buildForm();
    }

    /**
     * Tests that getFieldLabel and getHelpText return expected human-readable values.
     */
    public function test_build_form_returns_correct_labels_and_help_text(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();

        $columns = [
            ['Field' => 'email', 'Type' => 'varchar(255)', 'Null' => 'NO'],
            ['Field' => 'slug', 'Type' => 'varchar(100)', 'Null' => 'NO'],
            ['Field' => 'content_media_files', 'Type' => 'varchar(255)', 'Null' => 'YES'],
            ['Field' => 'first_name', 'Type' => 'varchar(100)', 'Null' => 'NO'],
            ['Field' => 'last_name', 'Type' => 'varchar(100)', 'Null' => 'NO'],
            ['Field' => 'content_category_id', 'Type' => 'int', 'Null' => 'NO'],
        ];

        $stmt->method('fetchAll')->willReturn($columns);
        $db->method('query')->willReturn($stmt);

        $generator = new FormGenerator($db, 'posts', ['email', 'slug', 'content_media_files', 'first_name', 'last_name', 'content_category_id']);
        $form = $generator->buildForm();

        // Test Label
        self::assertEquals('Email', $form[0]['label']);
        self::assertEquals('First Name', $form[3]['label']);
        self::assertEquals('Last Name', $form[4]['label']);
        self::assertEquals('Category', $form[5]['label']);

        // Test Help Text (slug)
        self::assertStringContainsString('Allowed characters', $form[1]['help_text']);

        // Test Help Text (media files)
        self::assertStringContainsString('Max 30MB', $form[2]['help_text']);
    }

    public function test_build_form_with_array_options_enum(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();
        $columns = [
            ['Field' => 'role', 'Type' => 'varchar(20)', 'Null' => 'NO'],
        ];
        $stmt->method('fetchAll')->willReturn($columns);
        $db->method('query')->willReturn($stmt);

        $customOptions = [
            ['value' => 'admin', 'label' => 'Administrator'],
            ['value' => 'user', 'label' => 'Standard User'],
        ];

        $generator = new FormGenerator(
            $db,
            'users',
            ['role'],
            ['role' => $customOptions]
        );

        $form = $generator->buildForm();

        self::assertEquals('select', $form[0]['type']);
        self::assertSame($customOptions, $form[0]['options']);
    }

    /**
     * Tests the static method that attaches anti-bot fields.
     */
    public function test_attach_anti_bot_fields_appends_hidden_fields(): void
    {
        $initialForm = [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => '', 'required' => true, 'multiple' => false, 'help_text' => '', 'options' => null],
        ];

        $enrichedForm = FormGenerator::attachAntiBotFields($initialForm);

        self::assertCount(3, $enrichedForm);
        self::assertEquals('bd', $enrichedForm[1]['key']);
        self::assertEquals('fst', $enrichedForm[2]['key']);
        self::assertEquals('hidden', $enrichedForm[1]['type']);
        self::assertEquals('hidden', $enrichedForm[2]['type']);
    }

    /**
     * Tests that tags and description receive 2 rows while generic textareas receive 8 rows.
     */
    public function test_build_form_assigns_compact_rows_for_tags_and_description(): void
    {
        $db = $this->getMockDb();
        $stmt = $this->getMockStmt();

        $columns = [
            ['Field' => 'description', 'Type' => 'text', 'Null' => 'NO'],
            ['Field' => 'tags', 'Type' => 'text', 'Null' => 'YES'],
            ['Field' => 'notes', 'Type' => 'text', 'Null' => 'YES'],
        ];

        $stmt->method('fetchAll')->willReturn($columns);
        $db->method('query')->willReturn($stmt);

        $generator = new FormGenerator($db, 'contents', ['description', 'tags', 'notes']);
        $form = $generator->buildForm();

        self::assertSame(2, $form[0]['rows']);
        self::assertSame(2, $form[1]['rows']);
        self::assertSame(8, $form[2]['rows']);
    }
}
