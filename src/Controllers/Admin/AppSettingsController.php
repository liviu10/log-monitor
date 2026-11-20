<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Core\Validation;
use App\Enums\LogLevel;
use App\Models\AppSetting;
use Throwable;

/**
 * App Settings Controller
 *
 * Manages per-application alert configuration settings via JSON endpoints.
 */
class AppSettingsController extends BaseController
{
    public function index(string $id): never
    {
        $appId = (int) $id;
        if ($appId <= 0) {
            $this->json(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting;
        $settings = $appSettingModel->getSettingsForApp($appId);

        $this->json([
            'success' => true,
            'settings' => $settings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(array $payload, string $id): never
    {
        $appId = (int) $id;
        if ($appId <= 0) {
            $this->json(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting;

        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $saved = 0;
            $errors = [];

            foreach ($payload['settings'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rawKey = $item['key'] ?? null;
                $rawValue = $item['value'] ?? null;
                $key = is_string($rawKey) ? trim($rawKey) : '';
                $value = is_string($rawValue) ? trim($rawValue) : '';

                if ($key === '') {
                    $errors[] = __('Setting #:index: The key is required.', ['index' => (string) $index]);

                    continue;
                }
                if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key) !== 1) {
                    $errors[] = __("Setting ':key': The key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)", ['key' => $key]);

                    continue;
                }

                try {
                    $appSettingModel->saveSetting($appId, $key, $value);
                    $saved++;
                } catch (Throwable $e) {
                    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                        LogViaStream::send(LogLevel::ERROR->value, 'Bulk setting storage exception', [
                            'location' => __METHOD__,
                            'line' => __LINE__,
                            'exception_message' => $e->getMessage(),
                            'app_id' => $appId,
                            'setting_key' => $key,
                            'identifier' => 'AppSettingsController_BulkStore_Exception',
                        ]);
                    }
                    $errors[] = __("Setting ':key': Critical error occurred.", ['key' => $key]);
                }
            }

            if ($errors !== []) {
                $this->json([
                    'success' => false,
                    'message' => __('Saving completed with some errors: ').implode(' ', $errors),
                    'saved_count' => $saved,
                ], 422);
            }

            $this->json([
                'success' => true,
                'message' => __(':count settings were saved successfully.', ['count' => (string) $saved]),
            ]);
        }

        $validator = new Validation([
            'key' => 'Setting key',
            'value' => 'Setting value',
        ]);
        $rawErrors = $validator->validate([
            'key' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'value' => ['required', 'string'],
        ], $payload);

        if ($rawErrors !== []) {
            $msgs = [];
            foreach ($rawErrors as $field => $rules) {
                foreach ($rules as $rule) {
                    $msgs[] = $validator->messages($field, $rule);
                }
            }
            $this->json(['success' => false, 'message' => implode(' ', $msgs)], 422);
        }

        $key = is_string($payload['key'] ?? null) ? trim($payload['key']) : '';
        $value = is_string($payload['value'] ?? null) ? trim($payload['value']) : '';

        if ($appSettingModel->getSetting($appId, $key) !== null) {
            $this->json(['success' => false, 'message' => __('A setting with this key already exists.')], 422);
        }

        try {
            $appSettingModel->saveSetting($appId, $key, $value);
            $this->json(['success' => true, 'message' => __('Setting saved successfully.')]);
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'JSON_RESPONSE:')) {
                throw $e;
            }
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Single setting storage exception', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'app_id' => $appId,
                    'setting_key' => $key,
                    'identifier' => 'AppSettingsController_Store_Exception',
                ]);
            }
            $this->json(['success' => false, 'message' => __('Error saving setting in the database.')], 500);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(array $payload, string $id): never
    {
        $appId = (int) $id;
        if ($appId <= 0) {
            $this->json(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting;

        if (isset($payload['settings']) && is_array($payload['settings'])) {
            $updated = 0;
            $errors = [];

            foreach ($payload['settings'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rawKey = $item['key'] ?? null;
                $rawValue = $item['value'] ?? null;
                $rawOldKey = $item['old_key'] ?? null;

                $key = is_string($rawKey) ? trim($rawKey) : '';
                $value = is_string($rawValue) ? trim($rawValue) : '';
                $oldKey = is_string($rawOldKey) ? trim($rawOldKey) : $key;

                if ($key === '') {
                    $errors[] = __('Setting #:index: The key is required.', ['index' => (string) $index]);

                    continue;
                }
                if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key) !== 1) {
                    $errors[] = __("Setting ':key': The key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)", ['key' => $key]);

                    continue;
                }

                try {
                    $appSettingModel->updateSetting($appId, $oldKey, ['key' => $key, 'value' => $value]);
                    $updated++;
                } catch (Throwable $e) {
                    if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                        LogViaStream::send(LogLevel::ERROR->value, 'Bulk setting update exception', [
                            'location' => __METHOD__,
                            'line' => __LINE__,
                            'exception_message' => $e->getMessage(),
                            'app_id' => $appId,
                            'setting_key' => $key,
                            'identifier' => 'AppSettingsController_BulkUpdate_Exception',
                        ]);
                    }
                    $errors[] = __("Setting ':key': Critical error occurred during update.", ['key' => $key]);
                }
            }

            if ($errors !== []) {
                $this->json([
                    'success' => false,
                    'message' => __('Updating completed with some errors: ').implode(' ', $errors),
                    'updated_count' => $updated,
                ], 422);
            }

            $this->json([
                'success' => true,
                'message' => __(':count settings were updated successfully.', ['count' => (string) $updated]),
            ]);
        }

        $validator = new Validation([
            'key' => 'Setting key',
            'value' => 'Setting value',
            'old_key' => 'Original setting key',
        ]);
        $rawErrors = $validator->validate([
            'key' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'value' => ['required', 'string'],
            'old_key' => ['required', 'string'],
        ], $payload);

        if ($rawErrors !== []) {
            $msgs = [];
            foreach ($rawErrors as $field => $rules) {
                foreach ($rules as $rule) {
                    $msgs[] = $validator->messages($field, $rule);
                }
            }
            $this->json(['success' => false, 'message' => implode(' ', $msgs)], 422);
        }

        $key = is_string($payload['key'] ?? null) ? trim($payload['key']) : '';
        $value = is_string($payload['value'] ?? null) ? trim($payload['value']) : '';
        $oldKey = is_string($payload['old_key'] ?? null) ? trim($payload['old_key']) : '';

        try {
            $appSettingModel->updateSetting($appId, $oldKey, ['key' => $key, 'value' => $value]);
            $this->json(['success' => true, 'message' => __('Setting updated successfully.')]);
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'JSON_RESPONSE:')) {
                throw $e;
            }
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Single setting update exception', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'app_id' => $appId,
                    'setting_key' => $key,
                    'identifier' => 'AppSettingsController_Update_Exception',
                ]);
            }
            $this->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function delete(string $id): never
    {
        $appId = (int) $id;
        $payload = $_POST;
        $rawInput = file_get_contents('php://input');
        if (is_string($rawInput) && $rawInput !== '' && json_validate($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $payload = array_merge($payload, $decoded);
            }
        }

        $rawKey = $payload['key'] ?? null;
        $key = is_string($rawKey) ? trim($rawKey) : '';

        if ($appId <= 0 || $key === '') {
            $this->json(['success' => false, 'message' => __('Invalid parameters for deleting setting.')], 400);
        }

        try {
            $appSettingModel = new AppSetting;
            $appSettingModel->deleteSetting($appId, $key);
            $this->json(['success' => true, 'message' => __('Setting deleted successfully.')]);
        } catch (Throwable $e) {
            if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'JSON_RESPONSE:')) {
                throw $e;
            }
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Setting deletion exception', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'app_id' => $appId,
                    'setting_key' => $key,
                    'identifier' => 'AppSettingsController_Delete_Exception',
                ]);
            }
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
