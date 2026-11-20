<?php

declare(strict_types=1);

namespace App\Controllers\Common;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Core\View;
use App\Enums\LogLevel;
use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Utilities\SendNotification;
use Throwable;

/**
 * Notify Manager Controller
 *
 * Handles routing of queued notification jobs to email delivery.
 */
class NotifyManagerController extends BaseController
{
    protected ?SendNotification $notifier = null;

    public function setNotifier(SendNotification $notifier): void
    {
        $this->notifier = $notifier;
    }

    /**
     * @param  array<string, mixed>  $jobData
     */
    public function handle(array $jobData): bool
    {
        $action = $jobData['action'] ?? null;
        $rawPayload = $jobData['payload'] ?? null;

        if (! is_string($action) || $action === '') {
            LogViaStream::send(LogLevel::ERROR->value, 'Notification action is missing or invalid.', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'identifier' => 'Notify_Action_Missing',
                'job_data' => $jobData,
            ]);

            return false;
        }

        if (! is_array($rawPayload)) {
            LogViaStream::send(LogLevel::ERROR->value, 'Notification payload is invalid (not an array).', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'identifier' => 'Notify_Payload_Invalid',
                'job_data' => $jobData,
            ]);

            return false;
        }

        /** @var array<string, mixed> $payload */
        $payload = $rawPayload;

        return match ($action) {
            'email' => $this->handleEmail($payload),
            default => $this->logUnsupportedAction($action, $jobData),
        };
    }

    /**
     * @param  array<string, mixed>  $jobData
     */
    protected function logUnsupportedAction(string $action, array $jobData): bool
    {
        LogViaStream::send(LogLevel::ERROR->value, "Unsupported notification action: {$action}", [
            'location' => __METHOD__,
            'line' => __LINE__,
            'identifier' => 'Notify_Action_Unsupported',
            'action_name' => $action,
            'job_data' => $jobData,
        ]);

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleEmail(array $payload): bool
    {
        $templateEvent = $payload['template_event'] ?? null;
        $data = $payload['data'] ?? [];

        if (! is_array($data)) {
            LogViaStream::send(LogLevel::ERROR->value, 'Email data is invalid (not an array).', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'identifier' => 'Notify_Email_Data_Invalid',
                'payload' => $payload,
            ]);

            return false;
        }

        $to = $data['to'] ?? null;
        if (! is_string($to) || $to === '') {
            LogViaStream::send(LogLevel::ERROR->value, 'Email recipient "to" is missing.', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'identifier' => 'Notify_Email_Recipient_Missing',
                'payload' => $payload,
            ]);

            return false;
        }

        // Direct raw email fallback (e.g. if no template_event is provided)
        if (! is_string($templateEvent) || $templateEvent === '') {
            $rawMessage = $data['message'] ?? null;
            if (! is_string($rawMessage) || $rawMessage === '') {
                LogViaStream::send(LogLevel::ERROR->value, 'Email template_event and message are both missing.', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'identifier' => 'Notify_Email_Template_Missing',
                    'payload' => $payload,
                ]);

                return false;
            }

            $rawSubject = $data['subject'] ?? 'LogMonitor Notification';
            $rawPriority = $data['priority'] ?? 3;
            $notifier = $this->notifier ?? new SendNotification;
            $notifier->handle([
                'to' => $to,
                'subject' => is_string($rawSubject) ? $rawSubject : 'LogMonitor Notification',
                'message' => $rawMessage,
                'priority' => is_scalar($rawPriority) ? (int) $rawPriority : 3,
                'isHtml' => false,
            ]);

            return true;
        }

        $rawLocale = function_exists('config') ? config('app.locale', 'en') : 'en';
        $defaultLocale = is_string($rawLocale) && $rawLocale !== '' ? $rawLocale : 'en';
        $rawLang = $data['lang'] ?? ($payload['lang'] ?? (function_exists('getLang') ? getLang() : $defaultLocale));
        $lang = is_string($rawLang) && $rawLang !== '' ? $rawLang : $defaultLocale;

        $template = NotificationTemplate::findActive($templateEvent, NotificationChannel::EMAIL->value, $lang);

        if ($template === null) {
            LogViaStream::send(LogLevel::ERROR->value, 'Email template not found or inactive.', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'identifier' => 'Notify_Email_Template_Not_Found',
                'template_event' => $templateEvent,
                'language' => $lang,
            ]);

            return false;
        }

        $subjectTpl = (isset($template['subject']) && is_string($template['subject']) && $template['subject'] !== '') ? $template['subject'] : 'Notification';
        $bodyHtmlTpl = (isset($template['body_html']) && is_string($template['body_html'])) ? $template['body_html'] : '';
        $bodyTextTpl = (isset($template['body_text']) && is_string($template['body_text'])) ? $template['body_text'] : '';

        $blade = View::getEngine();

        try {
            $subject = $blade->runString($subjectTpl, $data);
            $message = $blade->runString($bodyHtmlTpl, $data);
            if ($bodyTextTpl !== '') {
                $altBody = $blade->runString($bodyTextTpl, $data);
            } else {
                $altBody = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $message) ?? $message));
            }
        } catch (Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Failed to render Blade template.', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'identifier' => 'Notify_Email_Render_Failed',
                'template_event' => $templateEvent,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $rawPriority = $data['priority'] ?? 0;
        $rawAttachmentPath = $data['attachmentPath'] ?? null;
        $rawFrom = $data['from'] ?? null;

        /** @var array{to: string, message: string, subject: string, priority?: int, attachmentPath?: array<int, string>|string|null, from?: string|null, isHtml?: bool, altBody?: string} $emailData */
        $emailData = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'altBody' => $altBody,
            'priority' => is_scalar($rawPriority) ? (int) $rawPriority : 0,
            'attachmentPath' => is_string($rawAttachmentPath) || is_array($rawAttachmentPath) ? $rawAttachmentPath : null,
            'from' => is_string($rawFrom) ? $rawFrom : null,
            'isHtml' => true,
        ];

        $notifier = $this->notifier ?? new SendNotification;
        $notifier->handle($emailData);

        return true;
    }
}
