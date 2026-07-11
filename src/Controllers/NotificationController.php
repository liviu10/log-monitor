<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Models\AppSetting;
use App\Utilities\LogViaStream;
use App\Utilities\SendNotification;

/**
 * NotificationController Class
 *
 * Responsible for managing and sending automated alerts
 * through various alternative channels configured in the application (Email, Teams, etc.).
 *
 * @category Controller
 *
 * @version  1.6
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class NotificationController extends BaseController
{
    /**
     * Sends an alert based on application settings and log data.
     * Supports the use of multiple channels (e.g., "email,teams").
     *
     * @param  array<array-key, mixed>  $app  The processed application data.
     * @param  array<array-key, mixed>  $logPayload  The content data of the generated log.
     * @param  array<array-key, mixed>|null  $settings  Pre-loaded application settings (optional).
     */
    public function sendAlert(array $app, array $logPayload, ?array $settings = null): void
    {
        try {
            if ($settings === null) {
                $appSettingModel = new AppSetting;
                $rawAppId = $app['id'] ?? null;
                $appId = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
                $settingsRaw = $appSettingModel->getSettingsForApp($appId);

                $settings = [];
                foreach ($settingsRaw as $row) {
                    $rowKey = $row['key'] ?? null;
                    if (is_string($rowKey) || is_int($rowKey)) {
                        $settings[$rowKey] = $row['value'] ?? null;
                    }
                }
            }

            $rawChannel = $settings['notification_channel'] ?? '';
            $channels = array_map('trim', explode(',', strtolower(is_string($rawChannel) ? $rawChannel : '')));
            $levelsRaw = $settings['notification_levels'] ?? '';
            $levels = array_map('trim', explode(',', strtolower(is_string($levelsRaw) ? $levelsRaw : '')));
            $rawLevel = $logPayload['level'] ?? '';
            $currentLevel = strtolower(is_string($rawLevel) ? $rawLevel : '');

            if (! in_array($currentLevel, $levels, true)) {
                return;
            }

            // Exhaustive handling of priorities using the modern PHP 8.x match structure
            $priority = match ($currentLevel) {
                'emergency', 'alert', 'critical', 'error' => 1,
                'warning', 'notice' => 3,
                'info', 'debug' => 5,
                default => 3
            };

            $contextStr = '';
            if (! empty($logPayload['context'])) {
                $contextStr = json_encode($logPayload['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $rawLevelStr = $logPayload['level'] ?? '';
            $appNameStr = $app['name'] ?? '';
            $subject = sprintf(
                '[%s] Log Alert %s - %s',
                strtoupper(is_string($rawLevelStr) ? $rawLevelStr : ''),
                is_string($appNameStr) ? $appNameStr : '',
                APP_NAME
            );

            $appNameVal = $app['name'] ?? '';
            $levelVal = $logPayload['level'] ?? '';
            $messageVal = $logPayload['message'] ?? '';

            $emailMessage = "A high-level log was recorded:\n\n"
                .'Application: '.(is_string($appNameVal) ? $appNameVal : '')."\n"
                .'Level: '.strtoupper(is_string($levelVal) ? $levelVal : '')."\n"
                .'Message: '.(is_string($messageVal) ? $messageVal : '')."\n"
                .'Date: '.date('Y-m-d H:i:s')."\n";

            if ($contextStr !== '') {
                $emailMessage .= "Context:\n".$contextStr."\n";
            }

            // 1. Standard Email communication channel
            if (in_array('email', $channels, true)) {
                $emailRecipient = $settings['notification_email'] ?? null;
                if (is_string($emailRecipient) && $emailRecipient !== '') {
                    $notifier = new SendNotification;
                    $notifier->handle([
                        'to' => $emailRecipient,
                        'message' => $emailMessage,
                        'subject' => $subject,
                        'priority' => $priority,
                    ]);
                }
            }

            // 2. Microsoft Teams communication channel directly to the dedicated channel address
            if (in_array('teams', $channels, true)) {
                $teamsEmail = $settings['notification_teams_email'] ?? null;
                if (is_string($teamsEmail) && $teamsEmail !== '') {
                    $notifier = new SendNotification;
                    $notifier->handle([
                        'to' => $teamsEmail,
                        'message' => $emailMessage,
                        'subject' => $subject,
                        'priority' => $priority,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Error processing/sending automated notification: '.$e->getMessage(), [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'app_id' => $app['id'] ?? null,
                    'log_level' => $logPayload['level'] ?? null,
                ]);
            }
        }
    }
}
