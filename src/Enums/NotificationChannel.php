<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Notification Channel Enum
 *
 * Defines the supported delivery channels for notifications.
 */
enum NotificationChannel: string
{
    case EMAIL = 'email';
    // case SMS = 'sms';
    // case WHATSAPP = 'whatsapp';
}
