<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\MySQLWrapper;
use InvalidArgumentException;

/**
 * Utility class for dynamically generating forms from database schemas.
 * Adapted for PHP 8.4 and PDO.
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class FormGenerator
{
    /**
     * Class constructor.
     *
     * @param  MySQLWrapper  $db  Database wrapper instance.
     * @param  string  $table  Table name for inspection.
     * @param  array<int, string>  $formFields  Allowed fields.
     * @param  array<string, string|array<array{value: string, label: string}>>  $enums  Associations between fields and Enum classes/values.
     */
    public function __construct(
        private readonly MySQLWrapper $db,
        private readonly string $table,
        private readonly array $formFields = [],
        private readonly array $enums = []
    ) {}

    /**
     * Builds the form structure based on the database table columns.
     *
     * @return array<int, array{key: string, label: string, type: string, value: string, required: bool, multiple: bool, help_text: string, options: array<array{value: string, label: string}>|null, rows: int}>
     */
    public function buildForm(): array
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $this->table) !== 1) {
            throw new InvalidArgumentException('Numele tabelei contine caractere nepermise.');
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM `{$this->table}`");
        $columns = $stmt->fetchAll();

        $columnMap = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                $column = array_change_key_case($col, CASE_LOWER);
                $rawName = $column['field'] ?? '';
                $name = is_string($rawName) ? $rawName : '';
                if ($name !== '') {
                    $columnMap[$name] = $column;
                }
            }
        }

        $form = [];

        foreach ($this->formFields as $name) {
            $options = null;
            $fieldType = 'text';
            $required = true;
            $multiple = false;

            if (isset($columnMap[$name])) {
                $column = $columnMap[$name];
                $rawType = $column['type'] ?? '';
                $fullType = strtolower(is_string($rawType) ? $rawType : '');
                $typeName = '';
                if (preg_match('/^([a-z]+)/i', $fullType, $matches) === 1) {
                    $typeName = $matches[1];
                }

                $nullable = ($column['null'] ?? 'YES') === 'YES';
                $required = ! $nullable;

                if ($typeName === 'enum') {
                    preg_match("/^enum\((.*)\)$/i", $fullType, $matches);
                    if (isset($matches[1])) {
                        $vals = array_map(
                            fn ($v) => trim($v, " '\""),
                            explode(',', $matches[1])
                        );
                        $options = array_map(fn ($val) => [
                            'value' => $val,
                            'label' => $val,
                        ], $vals);
                    }
                    $fieldType = 'select';
                } else {
                    $fieldType = match ($typeName) {
                        'varchar', 'string', 'char' => 'text',
                        'text', 'mediumtext', 'longtext' => 'textarea',
                        'int', 'integer', 'bigint', 'smallint', 'mediumint', 'decimal', 'float', 'double', 'real', 'numeric' => 'number',
                        'tinyint', 'boolean', 'bool' => 'checkbox',
                        'date' => 'date',
                        'datetime', 'timestamp' => 'datetime-local',
                        default => 'text',
                    };
                }

                if ($fieldType === 'checkbox') {
                    $required = false;
                }
            } else {
                // Virtual field (such as password or uploaded files)
                if ($name === 'password') {
                    $fieldType = 'password';
                    $required = true;
                } elseif ($name === 'content_media_files') {
                    $fieldType = 'file';
                    $required = false;
                    $multiple = true;
                } elseif ($name === 'tags') {
                    $fieldType = 'text';
                    $required = false;
                }
            }

            if ($name === 'description') {
                $fieldType = 'textarea';
                $required = false;
            }

            // If we have a PHP enum or options defined for this field, override the type and options
            if (isset($this->enums[$name])) {
                $enumVal = $this->enums[$name];
                if (is_array($enumVal)) {
                    $fieldType = 'select';
                    $options = $enumVal;
                } elseif (class_exists($enumVal) && is_subclass_of($enumVal, \BackedEnum::class)) {
                    $fieldType = 'select';
                    $options = [];
                    foreach ($enumVal::cases() as $case) {
                        $options[] = [
                            'value' => (string) $case->value,
                            'label' => __($case->name),
                        ];
                    }
                }
            }

            $form[] = [
                'key' => $name,
                'label' => $this->getFieldLabel($name),
                'type' => $fieldType,
                'value' => '',
                'required' => $required,
                'multiple' => $multiple,
                'help_text' => $this->getHelpText($name),
                'options' => $options,
                'rows' => in_array($name, ['description', 'tags'], true) ? 2 : 8,
            ];
        }

        return $form;
    }

    /**
     * Returns a friendly English label for a given field.
     *
     * @param  string  $key  Field key.
     */
    private function getFieldLabel(string $key): string
    {
        return match ($key) {
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'password' => 'Password',
            'type' => 'Content Type',
            'role_id' => 'Role',
            'status' => 'Status',
            'content_category_id' => 'Category',
            'content_media_files' => 'Upload Attachments',
            'description' => 'Meta Description',
            'allow_comments' => 'Allow Comments',
            'allow_reactions' => 'Allow Reactions (Likes)',
            'allow_shares' => 'Allow Social Sharing',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    /**
     * Returns the help/description text for a given field.
     *
     * @param  string  $key  Field key.
     */
    private function getHelpText(string $key): string
    {
        return match ($key) {
            'slug' => 'Allowed characters: letters, numbers, dashes, slashes. Cannot start with "admin".',
            'content_media_files' => 'Allowed types: images, documents, videos, audio. Max 30MB.',
            'tags' => 'Separate tags with commas. Max 10 tags.',
            'description' => 'Brief summary for search engines and social sharing (10 - 180 chars).',
            'type' => 'Select whether this content is a standard Page or a blog Article.',
            'allow_comments' => 'Enable reader comments and discussion on this content.',
            'allow_reactions' => 'Enable reader appreciation (likes) on this content.',
            'allow_shares' => 'Display social sharing buttons (Facebook, X, WhatsApp, etc.).',
            default => '',
        };
    }

    /**
     * Injects the hidden honeypot and timestamp fields.
     *
     * @param  array<int, array{key: string, label: string, type: string, value: string, options: array<array{value: string, label: string}>|null}>  $form
     * @return array<int, array{key: string, label: string, type: string, value: string, options: array<array{value: string, label: string}>|null}>
     */
    final public static function attachAntiBotFields(array $form): array
    {
        $antiBotFields = [
            [
                'key' => 'bd',
                'label' => '',
                'type' => 'hidden',
                'value' => '',
                'options' => null,
            ],
            [
                'key' => 'fst',
                'label' => '',
                'type' => 'hidden',
                'value' => (string) time(),
                'options' => null,
            ],
        ];

        return array_merge($form, $antiBotFields);
    }
}
