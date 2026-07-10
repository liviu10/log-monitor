<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Models\AppSetting;
use App\Utilities\LogViaStream;
use App\Utilities\Validation;

/**
 * AppSettingController Class
 *
 * Manages application settings in the administration panel.
 * Allows listing, creating, updating, and deleting settings (individually or in bulk).
 *
 * @category Controller
 *
 * @version  1.3
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AppSettingController extends BaseController
{
    /**
     * Returns all settings for a specific application as a JSON response.
     *
     * @param  array<string, mixed>  $getData
     */
    public function index(array $getData): void
    {
        $this->checkAuth();

        $rawAppId = $getData['app_id'] ?? null;
        $appId = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting;
        $settings = $appSettingModel->getSettingsForApp($appId);

        $this->jsonResponse([
            'success' => true,
            'settings' => $settings,
        ]);
    }

    /**
     * Adds a new setting or multiple settings simultaneously for an application.
     *
     * @param  array<string, mixed>  $postData
     */
    public function store(array $postData): void
    {
        $this->checkAuth();

        $rawAppId = $postData['app_id'] ?? null;
        $appId = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting;

        if (isset($postData['settings']) && is_array($postData['settings'])) {
            $saved = 0;
            $errors = [];

            foreach ($postData['settings'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rawKey = $item['key'] ?? null;
                $rawValue = $item['value'] ?? null;
                $key = is_string($rawKey) ? trim($rawKey) : '';
                $value = is_string($rawValue) ? trim($rawValue) : '';

                if (empty($key)) {
                    $errors[] = __('Setting #:index: The key is required.', ['index' => $index]);

                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                    $errors[] = __("Setting ':key': The key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)", ['key' => $key]);

                    continue;
                }

                try {
                    $appSettingModel->saveSetting($appId, $key, $value);
                    $saved++;
                } catch (\Throwable $e) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Bulk setting storage exception', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'exception_trace' => $e->getTraceAsString(),
                        'app_id' => $appId,
                        'setting_key' => $key,
                        'identifier' => 'AppSettingController_BulkStore_Exception',
                    ]);
                    $errors[] = __("Setting ':key': Critical error occurred.", ['key' => $key]);
                }
            }

            if (! empty($errors)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Saving completed with some errors: ').implode(' ', $errors),
                    'saved_count' => $saved,
                ], 422);
            }

            $this->jsonResponse([
                'success' => true,
                'message' => __(':count settings were saved successfully.', ['count' => $saved]),
            ]);
        }

        $validator = new Validation([
            'app_id' => __('Application ID'),
            'key' => __('Setting key'),
            'value' => __('Setting value'),
        ]);

        $errors = $validator->validate([
            'app_id' => ['required', 'int'],
            'key' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'value' => ['required', 'string'],
        ], $postData);

        if (! empty($errors)) {
            $messages = [];
            foreach ($errors as $field => $errs) {
                foreach ($errs as $err) {
                    $messages[] = $validator->messages((string) $field, $err);
                }
            }
            $this->jsonResponse([
                'success' => false,
                'message' => implode(' ', $messages),
            ], 422);
        }

        $rawKey = $postData['key'] ?? null;
        $rawValue = $postData['value'] ?? null;
        $key = is_string($rawKey) ? trim($rawKey) : '';
        $value = is_string($rawValue) ? trim($rawValue) : '';

        if ($appSettingModel->getSetting($appId, $key)) {
            $this->jsonResponse([
                'success' => false,
                'message' => __('A setting with this key already exists.'),
            ], 422);
        }

        try {
            $appSettingModel->saveSetting($appId, $key, $value);
            $this->jsonResponse([
                'success' => true,
                'message' => __('Setting saved successfully.'),
            ]);
        } catch (\Throwable $e) {
            if (str_ends_with(get_class($e), 'HttpResponseException')) {
                throw $e;
            }
            LogViaStream::send(LogLevel::ERROR->value, 'Single setting storage exception', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'app_id' => $appId,
                'setting_key' => $key,
                'identifier' => 'AppSettingController_Store_Exception',
            ]);
            $this->jsonResponse(['success' => false, 'message' => __('Error saving setting in the database.')], 500);
        }
    }

    /**
     * Updates an existing setting or multiple settings simultaneously.
     *
     * @param  array<string, mixed>  $postData
     */
    public function update(array $postData): void
    {
        $this->checkAuth();

        $rawAppId = $postData['app_id'] ?? null;
        $appId = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting;

        if (isset($postData['settings']) && is_array($postData['settings'])) {
            $updated = 0;
            $errors = [];

            foreach ($postData['settings'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $rawKey = $item['key'] ?? null;
                $rawValue = $item['value'] ?? null;
                $rawOldKey = $item['old_key'] ?? null;

                $key = is_string($rawKey) ? trim($rawKey) : '';
                $value = is_string($rawValue) ? trim($rawValue) : '';
                $oldKey = is_string($rawOldKey) ? trim($rawOldKey) : $key;

                if (empty($key)) {
                    $errors[] = __('Setting #:index: The key is required.', ['index' => $index]);

                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                    $errors[] = __("Setting ':key': The key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)", ['key' => $key]);

                    continue;
                }

                try {
                    $appSettingModel->updateSetting($appId, $oldKey, [
                        'key' => $key,
                        'value' => $value,
                    ]);
                    $updated++;
                } catch (\Throwable $e) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Bulk setting update exception', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'exception_trace' => $e->getTraceAsString(),
                        'app_id' => $appId,
                        'setting_key' => $key,
                        'identifier' => 'AppSettingController_BulkUpdate_Exception',
                    ]);
                    $errors[] = __("Setting ':key': Critical error occurred during update.", ['key' => $key]);
                }
            }

            if (! empty($errors)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Updating completed with some errors: ').implode(' ', $errors),
                    'updated_count' => $updated,
                ], 422);
            }

            $this->jsonResponse([
                'success' => true,
                'message' => __(':count settings were updated successfully.', ['count' => $updated]),
            ]);
        }

        $validator = new Validation([
            'app_id' => __('Application ID'),
            'key' => __('Setting key'),
            'value' => __('Setting value'),
            'old_key' => __('Original setting key'),
        ]);

        $errors = $validator->validate([
            'app_id' => ['required', 'int'],
            'key' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'value' => ['required', 'string'],
            'old_key' => ['required', 'string'],
        ], $postData);

        if (! empty($errors)) {
            $messages = [];
            foreach ($errors as $field => $errs) {
                foreach ($errs as $err) {
                    $messages[] = $validator->messages((string) $field, $err);
                }
            }
            $this->jsonResponse([
                'success' => false,
                'message' => implode(' ', $messages),
            ], 422);
        }

        $rawKey = $postData['key'] ?? null;
        $rawValue = $postData['value'] ?? null;
        $rawOldKey = $postData['old_key'] ?? null;

        $key = is_string($rawKey) ? trim($rawKey) : '';
        $value = is_string($rawValue) ? trim($rawValue) : '';
        $oldKey = is_string($rawOldKey) ? trim($rawOldKey) : '';

        try {
            $appSettingModel->updateSetting($appId, $oldKey, [
                'key' => $key,
                'value' => $value,
            ]);

            $this->jsonResponse([
                'success' => true,
                'message' => __('Setting updated successfully.'),
            ]);
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Single setting update exception', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'app_id' => $appId,
                'setting_key' => $key,
                'identifier' => 'AppSettingController_Update_Exception',
            ]);
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Deletes a setting of an application.
     *
     * @param  array<string, mixed>  $postData
     */
    public function delete(array $postData): void
    {
        $this->checkAuth();

        $rawAppId = $postData['app_id'] ?? null;
        $appId = (is_int($rawAppId) || is_string($rawAppId)) ? (int) $rawAppId : 0;
        $rawKey = $postData['key'] ?? null;
        $key = is_string($rawKey) ? trim($rawKey) : '';

        if ($appId <= 0 || empty($key)) {
            $this->jsonResponse([
                'success' => false,
                'message' => __('Invalid parameters for deleting setting.'),
            ], 400);
        }

        try {
            $appSettingModel = new AppSetting;
            $appSettingModel->deleteSetting($appId, $key);

            $this->jsonResponse([
                'success' => true,
                'message' => __('Setting deleted successfully.'),
            ]);
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Setting deletion exception', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'app_id' => $appId,
                'setting_key' => $key,
                'identifier' => 'AppSettingController_Delete_Exception',
            ]);
            $this->jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
