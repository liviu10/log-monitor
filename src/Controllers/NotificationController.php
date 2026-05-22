<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppSetting;
use App\Utilities\SendNotification;
use App\Utilities\LogViaCurl;

/**
 * NotificationController Class
 *
 * Responsible for managing and sending automated alerts 
 * through various channels (email, Teams via email, etc.) based on application settings.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.5
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class NotificationController extends BaseController
{
    /**
     * Sends an alert based on the application's settings and the log details.
     * Supports multiple channels (e.g. "email,teams" or single channel).
     *
     * @param array $app        Application data (id, name, etc.).
     * @param array $logPayload Current log data (level, message, context).
     * @return void
     */
    public function sendAlert(array $app, array $logPayload): void
    {
        try {
            $appSettingModel = new AppSetting();
            $settingsRaw = $appSettingModel->getSettingsForApp((int)$app['id']);
            
            $settings = [];
            foreach ($settingsRaw as $row) {
                $settings[$row['key']] = $row['value'];
            }

            // Reading active channels (supports comma-separated, e.g., "email,teams")
            $channels = array_map('trim', explode(',', strtolower($settings['notification_channel'] ?? '')));
            $levelsRaw = $settings['notification_levels'] ?? '';
            $levels = array_map('trim', explode(',', strtolower($levelsRaw)));
            $currentLevel = strtolower($logPayload['level']);

            // If the current level is not in the notification list, stop
            if (!in_array($currentLevel, $levels, true)) {
                return;
            }

            // Mapping Outlook priorities (1 = High, 3 = Normal, 5 = Low) based on log level
            $priority = match ($currentLevel) {
                'emergency', 'alert', 'critical', 'error' => 1,
                'warning', 'notice' => 3,
                'info', 'debug' => 5,
                default => 3
            };

            $contextStr = '';
            if (!empty($logPayload['context'])) {
                $contextStr = json_encode($logPayload['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            // 1. Email channel (alert to monitoring email addresses)
            if (in_array('email', $channels, true)) {
                $emailRecipient = $settings['notification_email'] ?? null;
                if (!empty($emailRecipient)) {
                    $notifier = new SendNotification();
                    $subject = sprintf("[%s] Log Alert %s - %s", strtoupper($logPayload['level']), $app['name'], APP_NAME);

                    $emailMessage = "A fost inregistrat un log de nivel mare:\n\n"
                        . "Aplicatie: " . $app['name'] . "\n"
                        . "Nivel: " . strtoupper($logPayload['level']) . "\n"
                        . "Mesaj: " . $logPayload['message'] . "\n"
                        . "Data: " . date('Y-m-d H:i:s') . "\n";

                    if ($contextStr !== '') {
                        $emailMessage .= "Context:\n" . $contextStr . "\n";
                    }

                    $notifier->handle([
                        'to' => $emailRecipient,
                        'message' => $emailMessage,
                        'subject' => $subject,
                        'priority' => $priority
                    ]);
                }
            }

            // 2. Microsoft Teams channel (by sending a direct email to the Teams channel address)
            if (in_array('teams', $channels, true)) {
                $teamsEmail = $settings['notification_teams_email'] ?? null;
                if (!empty($teamsEmail)) {
                    $notifier = new SendNotification();
                    $subject = sprintf("[%s] Log Alert %s - %s", strtoupper($logPayload['level']), $app['name'], APP_NAME);

                    $emailMessage = "A fost inregistrat un log de nivel mare:\n\n"
                        . "Aplicatie: " . $app['name'] . "\n"
                        . "Nivel: " . strtoupper($logPayload['level']) . "\n"
                        . "Mesaj: " . $logPayload['message'] . "\n"
                        . "Data: " . date('Y-m-d H:i:s') . "\n";

                    if ($contextStr !== '') {
                        $emailMessage .= "Context:\n" . $contextStr . "\n";
                    }

                    $notifier->handle([
                        'to' => $teamsEmail,
                        'message' => $emailMessage,
                        'subject' => $subject,
                        'priority' => $priority
                    ]);
                }
            }
        } catch (\Throwable $e) {
            LogViaCurl::send('ERROR', 'Eroare la procesarea/trimiterea notificarii automate: ' . $e->getMessage(), [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'app_id' => $app['id'] ?? null,
                'log_level' => $logPayload['level'] ?? null
            ]);
        }
    }
}
