<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\MySQLWrapper;

/**
 * Notification Template Model
 *
 * Manages queries and changes in the notification_templates table.
 *
 * @category Model
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class NotificationTemplate
{
    /**
     * Resolves the default fallback language configured in the application.
     */
    public static function getDefaultLanguage(): string
    {
        $configured = config('app.fallback_locale', config('app.locale', 'en'));

        return is_string($configured) && $configured !== '' ? $configured : 'en';
    }

    /**
     * Retrieves an active notification template by event name, channel, and language.
     * Falls back to the application configured default language if the specified language template is not found.
     *
     * @param  string  $eventName  The unique identifier for the event.
     * @param  string  $channel  The delivery channel (e.g., 'email').
     * @param  string|null  $lang  The language code (e.g., 'en', 'ro').
     * @return array<string, mixed>|null The template array if found, null otherwise.
     */
    public static function findActive(string $eventName, string $channel, ?string $lang = null): ?array
    {
        $defaultLang = self::getDefaultLanguage();
        $targetLang = is_string($lang) && $lang !== '' ? $lang : $defaultLang;

        $db = MySQLWrapper::getInstance();
        $templates = $db->read('notification_templates', [
            'event_name' => $eventName,
            'channel' => $channel,
            'language' => $targetLang,
            'is_active' => 1,
        ]);

        if ($templates !== []) {
            /** @var array<string, mixed> $template */
            $template = $templates[0];

            return $template;
        }

        if ($targetLang !== $defaultLang) {
            $fallback = $db->read('notification_templates', [
                'event_name' => $eventName,
                'channel' => $channel,
                'language' => $defaultLang,
                'is_active' => 1,
            ]);

            if ($fallback !== []) {
                /** @var array<string, mixed> $template */
                $template = $fallback[0];

                return $template;
            }
        }

        return null;
    }

    /**
     * Retrieves a notification template by its primary ID.
     *
     * @param  int  $id  Primary key ID.
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT * FROM notification_templates WHERE id = ? LIMIT 1', [$id]);
        $row = $stmt->fetch();

        if (is_array($row)) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        return null;
    }

    /**
     * Retrieves all templates sorted by event name, channel, and language.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $db = MySQLWrapper::getInstance();
        $stmt = $db->query('SELECT * FROM notification_templates ORDER BY event_name ASC, channel ASC, language ASC');
        $rows = $stmt->fetchAll();
        /** @var array<int, array<string, mixed>> $rows */

        return $rows;
    }

    /**
     * Checks if a template exists for a given event name, channel, and language.
     *
     * @param  string  $eventName  Event identifier.
     * @param  string  $channel  Channel (e.g., 'email').
     * @param  string|null  $language  Language code (e.g., 'en', 'ro').
     * @param  int|null  $exceptId  Optional ID to exclude from verification (for edits).
     */
    public static function exists(string $eventName, string $channel, ?string $language = null, ?int $exceptId = null): bool
    {
        $lang = is_string($language) && $language !== '' ? $language : self::getDefaultLanguage();
        $db = MySQLWrapper::getInstance();
        if ($exceptId !== null) {
            $stmt = $db->query(
                'SELECT id FROM notification_templates WHERE event_name = ? AND channel = ? AND language = ? AND id != ? LIMIT 1',
                [$eventName, $channel, $lang, $exceptId]
            );
        } else {
            $stmt = $db->query(
                'SELECT id FROM notification_templates WHERE event_name = ? AND channel = ? AND language = ? LIMIT 1',
                [$eventName, $channel, $lang]
            );
        }

        return (bool) $stmt->fetch();
    }

    /**
     * Creates a new notification template.
     *
     * @param  array<string, mixed>  $data  Template attributes.
     */
    public static function create(array $data): string|int
    {
        $db = MySQLWrapper::getInstance();
        if (! isset($data['language']) || ! is_string($data['language']) || $data['language'] === '') {
            $data['language'] = self::getDefaultLanguage();
        }
        if (! isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (! isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        return $db->create('notification_templates', $data);
    }

    /**
     * Updates an existing notification template.
     *
     * @param  int  $id  Primary key ID.
     * @param  array<string, mixed>  $data  Attributes to update.
     */
    public static function update(int $id, array $data): bool
    {
        $db = MySQLWrapper::getInstance();
        $data['updated_at'] = date('Y-m-d H:i:s');
        $rows = $db->update('notification_templates', $data, ['id' => $id]);

        return $rows >= 0;
    }

    /**
     * Deletes a notification template by ID.
     *
     * @param  int  $id  Primary key ID.
     */
    public static function delete(int $id): bool
    {
        $db = MySQLWrapper::getInstance();
        $rows = $db->delete('notification_templates', ['id' => $id]);

        return $rows > 0;
    }
}
