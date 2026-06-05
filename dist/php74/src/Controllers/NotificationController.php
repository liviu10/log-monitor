<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppSetting;
use App\Utilities\SendNotification;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * Clasa NotificationController
 *
 * Responsabila pentru gestionarea si trimiterea de alerte automate
 * prin diverse canale alternative configurate din aplicatie (E-mail, Teams etc.).
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.6
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class NotificationController extends BaseController
{
    /**
     * Trimite o alerta pe baza setarilor aplicatiei si a datelor din log.
     * Suporta utilizarea de canale multiple (ex: "email,teams").
     *
     * @param array $app        Datele aplicatiei procesate.
     * @param array $logPayload Datele continutului din logul generat.
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

            $channels = array_map('trim', explode(',', strtolower($settings['notification_channel'] ?? '')));
            $levelsRaw = $settings['notification_levels'] ?? '';
            $levels = array_map('trim', explode(',', strtolower($levelsRaw)));
            $currentLevel = strtolower($logPayload['level']);

            if (!in_array($currentLevel, $levels, true)) {
                return;
            }

            switch ($currentLevel) {
                case 'emergency':
                case 'alert':
                case 'critical':
                case 'error':
                    $priority = 1;
                    break;
                case 'warning':
                case 'notice':
                default:
                    $priority = 3;
                    break;
                case 'info':
                case 'debug':
                    $priority = 5;
                    break;
            }

            $contextStr = '';
            if (!empty($logPayload['context'])) {
                $contextStr = json_encode($logPayload['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $subject = sprintf("[%s] Log Alert %s - %s", strtoupper($logPayload['level']), $app['name'], APP_NAME);
            
            $emailMessage = "A fost inregistrat un log de nivel mare:\n\n"
                . "Aplicatie: " . $app['name'] . "\n"
                . "Nivel: " . strtoupper($logPayload['level']) . "\n"
                . "Mesaj: " . $logPayload['message'] . "\n"
                . "Data: " . date('Y-m-d H:i:s') . "\n";

            if ($contextStr !== '') {
                $emailMessage .= "Context:\n" . $contextStr . "\n";
            }

            // 1. Canal comunicare Email standard
            if (in_array('email', $channels, true)) {
                $emailRecipient = $settings['notification_email'] ?? null;
                if (!empty($emailRecipient)) {
                    $notifier = new SendNotification();
                    $notifier->handle([
                        'to' => $emailRecipient,
                        'message' => $emailMessage,
                        'subject' => $subject,
                        'priority' => $priority
                    ]);
                }
            }

            // 2. Canal comunicare Microsoft Teams direct pe adresa canalului dedicat
            if (in_array('teams', $channels, true)) {
                $teamsEmail = $settings['notification_teams_email'] ?? null;
                if (!empty($teamsEmail)) {
                    $notifier = new SendNotification();
                    $notifier->handle([
                        'to' => $teamsEmail,
                        'message' => $emailMessage,
                        'subject' => $subject,
                        'priority' => $priority
                    ]);
                }
            }
        } catch (\Throwable $throwable) {
            LogViaStream::send(LogLevel::ERROR, 'Eroare la procesarea/trimiterea notificarii automate: ' . $throwable->getMessage(), [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                'exception_trace' => $throwable->getTraceAsString(),
                'app_id' => $app['id'] ?? null,
                'log_level' => $logPayload['level'] ?? null
            ]);
        }
    }
}