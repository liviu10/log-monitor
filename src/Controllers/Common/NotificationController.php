<?php

declare(strict_types=1);

namespace App\Controllers\Common;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Core\Queue;
use App\Enums\LogLevel;
use App\Models\AppSetting;
use App\Utilities\SendNotification;
use Throwable;

/**
 * NotificationController Class
 *
 * Queues or dispatches automated log alerts based on application settings.
 */
class NotificationController extends BaseController
{
    private ?SendNotification $directNotifier;

    public function __construct(?SendNotification $directNotifier = null)
    {
        $this->directNotifier = $directNotifier;
    }

    /**
     * Evaluates alert rules and queues/sends notifications for matching log levels.
     *
     * @param  array<array-key, mixed>  $app
     * @param  array<array-key, mixed>  $logPayload
     * @param  array<array-key, mixed>|null  $settings
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

            $priority = match ($currentLevel) {
                'emergency', 'alert', 'critical', 'error' => 1,
                'warning', 'notice' => 3,
                'info', 'debug' => 5,
                default => 3,
            };

            $contextStr = '';
            $rawContext = $logPayload['context'] ?? null;
            if ($rawContext !== null && $rawContext !== [] && $rawContext !== '') {
                $encoded = json_encode($rawContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $contextStr = is_string($encoded) ? $encoded : '';
            }

            $appNameStr = is_string($app['name'] ?? null) ? $app['name'] : '';
            $levelUpper = strtoupper(is_string($rawLevel) ? $rawLevel : '');
            $messageVal = is_string($logPayload['message'] ?? null) ? $logPayload['message'] : '';
            $appDisplay = defined('APP_NAME') && is_string(APP_NAME) ? APP_NAME : 'LogMonitor';

            $subject = sprintf('[%s] Log Alert %s - %s', $levelUpper, $appNameStr, $appDisplay);
            $loggedAt = date('Y-m-d H:i:s');

            $emailMessage = "A high-level log was recorded:\n\n"
                ."Application: {$appNameStr}\n"
                ."Level: {$levelUpper}\n"
                ."Message: {$messageVal}\n"
                ."Date: {$loggedAt}\n";

            if ($contextStr !== '') {
                $emailMessage .= "Context:\n{$contextStr}\n";
            }

            if (in_array('email', $channels, true)) {
                $emailRecipient = $settings['notification_email'] ?? null;
                if (is_string($emailRecipient) && $emailRecipient !== '') {
                    if ($this->directNotifier !== null) {
                        $this->directNotifier->handle([
                            'to' => $emailRecipient,
                            'message' => $emailMessage,
                            'subject' => $subject,
                            'priority' => $priority,
                        ]);
                    } else {
                        Queue::push('alerts', [
                            'action' => 'email',
                            'payload' => [
                                'template_event' => 'log_alert',
                                'data' => [
                                    'to' => $emailRecipient,
                                    'app_name' => $appNameStr,
                                    'level' => $levelUpper,
                                    'message' => $messageVal,
                                    'logged_at' => $loggedAt,
                                    'context_pretty' => $contextStr,
                                    'priority' => $priority,
                                ],
                            ],
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
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
