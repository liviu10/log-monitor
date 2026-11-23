<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Enums\LogLevel;
use App\Models\App;
use Throwable;

/**
 * Apps Controller
 *
 * Manages client applications registered to ingest logs and their API keys.
 */
class AppsController extends BaseController
{
    public function index(): string
    {
        $appModel = new App;
        $apps = $appModel->getAll();

        return $this->render('admin.apps.index', [
            'title' => __('Applications'),
            'apps' => $apps,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(array $payload): never
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
        ], $payload);

        try {
            $appModel = new App;
            $apiKey = bin2hex(random_bytes(32));
            $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
            $appModel->create($name, $apiKey);

            $this->flash('success', __('Success'), __('Application created successfully.'));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to create application record', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'payload' => $payload,
                    'identifier' => 'AppsController_Store_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to create application.'));
        }

        $this->redirect('/admin/apps');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(array $payload, string $id): never
    {
        $appId = (int) $id;
        if ($appId <= 0) {
            $this->redirectWithFlash('/admin/apps', 'error', __('Error'), __('Missing or invalid application ID.'));
        }

        $data = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:100'],
        ], $payload);

        try {
            $appModel = new App;
            $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
            $appModel->update($appId, ['name' => $name]);

            $this->flash('success', __('Success'), __('Application updated successfully.'));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to update application data', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'app_id' => $appId,
                    'identifier' => 'AppsController_Update_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to update application.'));
        }

        $this->redirect('/admin/apps');
    }

    public function regenerateKey(string $id): never
    {
        $appId = (int) $id;
        if ($appId <= 0) {
            $this->redirectWithFlash('/admin/apps', 'error', __('Error'), __('Missing or invalid application ID.'));
        }

        try {
            $appModel = new App;
            $newApiKey = bin2hex(random_bytes(32));
            $appModel->update($appId, ['api_key' => $newApiKey]);
            $this->flash('success', __('Success'), __('API key regenerated successfully.'));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to regenerate API key', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'app_id' => $appId,
                    'identifier' => 'AppsController_RegenerateKey_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to regenerate API key.'));
        }

        $this->redirect('/admin/apps');
    }

    public function delete(string $id): never
    {
        $appId = (int) $id;
        if ($appId <= 0) {
            $this->redirectWithFlash('/admin/apps', 'error', __('Error'), __('Missing or invalid application ID.'));
        }

        try {
            $appModel = new App;
            $appModel->delete($appId);
            $this->flash('success', __('Success'), __('Application deleted successfully.'));
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to delete application', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'app_id' => $appId,
                    'identifier' => 'AppsController_Delete_Failure',
                ]);
            }

            $this->flash('error', __('Error'), __('Failed to delete application.'));
        }

        $this->redirect('/admin/apps');
    }
}
