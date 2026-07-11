<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Enums\LogLevel;
use App\Models\App;
use App\Utilities\LogViaStream;
use App\Utilities\Validation;

/**
 * AppController Class
 *
 * Manages the administration interface for registered applications.
 * Allows listing, creating, updating, and deleting applications, ensuring
 * the generation of unique API keys and strict validation of input data.
 *
 * @category Controller
 *
 * @version  1.2
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AppController extends BaseController
{
    /**
     * Displays a list of all registered applications.
     */
    public function index(): void
    {
        $this->checkAuth();

        $appModel = new App;
        $apps = $appModel->getAll();

        $this->render('apps/index', [
            'apps' => $apps,
        ]);
    }

    /**
     * Processes adding a new application to the system.
     * Automatically generates a secure 64-character API key.
     *
     * @param  array<string, mixed>  $postData
     */
    public function store(array $postData): never
    {
        $this->checkAuth();

        $payload = $postData;
        $validator = new Validation(['name' => __('Application name')]);

        $errors = $validator->validate([
            'name' => ['required', 'string', 'min:3'],
        ], $payload);

        if (! empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('apps.php');
        }

        try {
            $appModel = new App;
            $apiKey = bin2hex(random_bytes(32));

            $nameVal = $payload['name'] ?? '';
            $appModel->create(trim(is_string($nameVal) ? $nameVal : ''), $apiKey);

            setFlash('success', __('Success'), __('Application created successfully.'));
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to create application record', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'payload' => $payload,
                    'identifier' => 'AppController_Store_Failure',
                ]);
            }

            setFlash('danger', __('Error'), __('Failed to create application.'));
        }

        $this->redirect('apps.php');
    }

    /**
     * Processes updating an application name or regenerating the API key.
     *
     * @param  array<string, mixed>  $postData
     * @param  array<string, mixed>  $getData
     */
    public function update(array $postData, array $getData = []): never
    {
        $this->checkAuth();

        $idVal = $postData['id'] ?? null;
        if (! $idVal || (! is_int($idVal) && ! is_string($idVal))) {
            setFlash('danger', __('Error'), __('Missing or invalid application ID.'));
            $this->redirect('apps.php');
        }

        $appModel = new App;
        $appId = (int) $idVal;

        try {
            if (isset($getData['sub_action']) && $getData['sub_action'] === 'regenerate-key') {
                $newApiKey = bin2hex(random_bytes(32));
                $appModel->update($appId, ['api_key' => $newApiKey]);
                setFlash('success', __('Success'), __('API key regenerated successfully.'));
                $this->redirect('apps.php');
            }

            $payload = $postData;
            $validator = new Validation(['name' => __('Application name')]);

            $errors = $validator->validate([
                'name' => ['required', 'string', 'min:3'],
            ], $payload);

            if (! empty($errors)) {
                $_SESSION['errors'] = $errors;
                $this->redirect('apps.php');
            }

            $nameVal = $payload['name'] ?? '';
            $appModel->update($appId, [
                'name' => trim(is_string($nameVal) ? $nameVal : ''),
            ]);

            setFlash('success', __('Success'), __('Application updated successfully.'));
        } catch (\Throwable $e) {
            if (class_exists('App\Utilities\LogViaStream')) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to update application data', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'app_id' => $appId,
                    'identifier' => 'AppController_Update_Failure',
                ]);
            }

            setFlash('danger', __('Error'), __('Failed to update application.'));
        }

        $this->redirect('apps.php');
    }

    /**
     * Deletes an application from the system based on the ID provided via POST.
     *
     * @param  array<string, mixed>  $postData
     */
    public function delete(array $postData): never
    {
        $this->checkAuth();

        $idVal = $postData['id'] ?? null;
        if ($idVal && (is_int($idVal) || is_string($idVal))) {
            try {
                $appModel = new App;
                $appModel->delete((int) $idVal);
                setFlash('success', __('Success'), __('Application deleted successfully.'));
            } catch (\Throwable $e) {
                if (class_exists('App\Utilities\LogViaStream')) {
                    LogViaStream::send(LogLevel::ERROR->value, 'Failed to delete application', [
                        'location' => __METHOD__,
                        'line' => __LINE__,
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'exception_trace' => $e->getTraceAsString(),
                        'app_id' => $idVal,
                        'identifier' => 'AppController_Delete_Failure',
                    ]);
                }

                setFlash('danger', __('Error'), __('Failed to delete application.'));
            }
        }

        $this->redirect('apps.php');
    }
}
