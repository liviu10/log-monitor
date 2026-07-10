<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppSetting;
use App\Utilities\Validation;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * AppSettingController Class
 *
 * Manages application settings in the administration panel.
 * Allows listing, creating, updating, and deleting settings (individually or in bulk).
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.3
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AppSettingController extends BaseController
{
    /**
     * Returns all settings for a specific application as a JSON response.
     */
    public function index(array $getData): void
    {
        $this->checkAuth();

        $appId = isset($getData['app_id']) ? (int)$getData['app_id'] : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting();
        $settings = $appSettingModel->getSettingsForApp($appId);

        $this->jsonResponse([
            'success' => true,
            'settings' => $settings
        ]);
    }

    /**
     * Adds a new setting or multiple settings simultaneously for an application.
     */
    public function store(array $postData): void
    {
        $this->checkAuth();

        $appId = isset($postData['app_id']) ? (int)$postData['app_id'] : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting();

        if (isset($postData['settings']) && is_array($postData['settings'])) {
            $saved = 0;
            $errors = [];
            
            foreach ($postData['settings'] as $index => $item) {
                $key = isset($item['key']) ? trim($item['key']) : '';
                $value = isset($item['value']) ? trim($item['value']) : '';

                if (empty($key)) {
                    $errors[] = __("Setting #:index: The key is required.", ['index' => $index]);
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                    $errors[] = __("Setting ':key': The key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)", ['key' => $key]);
                    continue;
                }

                try {
                    $success = $appSettingModel->saveSetting($appId, $key, $value);
                    if ($success) {
                        $saved++;
                    } else {
                        $errors[] = __("Setting ':key': Error saving.", ['key' => $key]);
                    }
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
                        'identifier' => 'AppSettingController_BulkStore_Exception'
                    ]);
                    $errors[] = __("Setting ':key': Critical error occurred.", ['key' => $key]);
                }
            }

            if (!empty($errors)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Saving completed with some errors: ') . implode(' ', $errors),
                    'saved_count' => $saved
                ], 422);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'message' => __(':count settings were saved successfully.', ['count' => $saved])
            ]);
            return;
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

        if (!empty($errors)) {
            $messages = [];
            foreach ($errors as $field => $errs) {
                foreach ($errs as $err) {
                    $messages[] = $validator->messages($field, $err);
                }
            }
            $this->jsonResponse([
                'success' => false,
                'message' => implode(' ', $messages)
            ], 422);
            return;
        }

        $key = trim($postData['key']);
        $value = trim($postData['value']);

        if ($appSettingModel->getSetting($appId, $key)) {
            $this->jsonResponse([
                'success' => false,
                'message' => __('A setting with this key already exists.')
            ], 422);
            return;
        }

        try {
            $success = $appSettingModel->saveSetting($appId, $key, $value);
            if ($success) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => __('Setting saved successfully.')
                ]);
            } else {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Error saving setting in the database.')
                ], 500);
            }
        } catch (\Throwable $e) {
            LogViaStream::send(LogLevel::ERROR->value, 'Single setting storage exception', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'app_id' => $appId,
                'setting_key' => $key,
                'identifier' => 'AppSettingController_Store_Exception'
            ]);
            $this->jsonResponse(['success' => false, 'message' => __('Internal server error.')], 500);
        }
    }

    /**
     * Updates an existing setting or multiple settings simultaneously.
     */
    public function update(array $postData): void
    {
        $this->checkAuth();

        $appId = isset($postData['app_id']) ? (int)$postData['app_id'] : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => __('Invalid application ID.')], 400);
        }

        $appSettingModel = new AppSetting();

        if (isset($postData['settings']) && is_array($postData['settings'])) {
            $updated = 0;
            $errors = [];

            foreach ($postData['settings'] as $index => $item) {
                $key = isset($item['key']) ? trim($item['key']) : '';
                $value = isset($item['value']) ? trim($item['value']) : '';
                $oldKey = isset($item['old_key']) ? trim($item['old_key']) : $key;

                if (empty($key)) {
                    $errors[] = __("Setting #:index: The key is required.", ['index' => $index]);
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                    $errors[] = __("Setting ':key': The key can only contain letters, numbers, underscores (_), hyphens (-) and dots (.)", ['key' => $key]);
                    continue;
                }

                try {
                    $success = $appSettingModel->updateSetting($appId, $oldKey, [
                        'key' => $key,
                        'value' => $value
                    ]);
                    if ($success) {
                        $updated++;
                    } else {
                        $errors[] = __("Setting ':key': Error updating (check if the new key is not already in use).", ['key' => $key]);
                    }
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
                        'identifier' => 'AppSettingController_BulkUpdate_Exception'
                    ]);
                    $errors[] = __("Setting ':key': Critical error occurred during update.", ['key' => $key]);
                }
            }

            if (!empty($errors)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Updating completed with some errors: ') . implode(' ', $errors),
                    'updated_count' => $updated
                ], 422);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'message' => __(':count settings were updated successfully.', ['count' => $updated])
            ]);
            return;
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

        if (!empty($errors)) {
            $messages = [];
            foreach ($errors as $field => $errs) {
                foreach ($errs as $err) {
                    $messages[] = $validator->messages($field, $err);
                }
            }
            $this->jsonResponse([
                'success' => false,
                'message' => implode(' ', $messages)
            ], 422);
            return;
        }

        $key = trim($postData['key']);
        $value = trim($postData['value']);
        $oldKey = trim($postData['old_key']);

        try {
            $success = $appSettingModel->updateSetting($appId, $oldKey, [
                'key' => $key,
                'value' => $value
            ]);

            if ($success) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => __('Setting updated successfully.')
                ]);
            } else {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Error updating setting. Verify if the new key does not exist already.')
                ], 400);
            }
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
                'identifier' => 'AppSettingController_Update_Exception'
            ]);
            $this->jsonResponse(['success' => false, 'message' => __('Internal server error.')], 500);
        }
    }

    /**
     * Deletes a setting of an application.
     */
    public function delete(array $postData): void
    {
        $this->checkAuth();

        $appId = isset($postData['app_id']) ? (int)$postData['app_id'] : 0;
        $key = isset($postData['key']) ? trim($postData['key']) : '';

        if ($appId <= 0 || empty($key)) {
            $this->jsonResponse([
                'success' => false,
                'message' => __('Invalid parameters for deleting setting.')
            ], 400);
            return;
        }

        try {
            $appSettingModel = new AppSetting();
            $success = $appSettingModel->deleteSetting($appId, $key);

            if ($success) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => __('Setting deleted successfully.')
                ]);
            } else {
                $this->jsonResponse([
                    'success' => false,
                    'message' => __('Setting could not be deleted.')
                ], 500);
            }
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
                'identifier' => 'AppSettingController_Delete_Exception'
            ]);
            $this->jsonResponse(['success' => false, 'message' => __('Internal server error.')], 500);
        }
    }
}