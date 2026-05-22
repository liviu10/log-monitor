<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\App;
use App\Utilities\Validation;

/**
 * AppController Class
 *
 * Manages the administration interface for registered applications.
 * Allows listing, creating, and deleting applications, ensuring unique API key generation 
 * and validation of user-entered data.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AppController extends BaseController
{
    /**
     * Displays the list of all registered applications.
     */
    public function index(): void
    {
        $this->checkAuth();
        
        $appModel = new App();
        $apps = $appModel->getAll();
        
        $this->render('apps/index', [
            'apps' => $apps,
        ]);
    }

    /**
     * Processes the addition of a new application to the system.
     * Automatically generates a secure 64-character API key.
     */
    public function store(array $postData): never
    {
        $this->checkAuth();
        
        $payload = $postData;
        $validator = new Validation(['name' => 'nume aplicatie']);
        
        $errors = $validator->validate([
            'name' => ['required', 'string', 'min:3'],
        ], $payload);

        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('apps.php');
        }

        $appModel = new App();
        $apiKey = bin2hex(random_bytes(32));
        
        $appModel->create($payload['name'], $apiKey);
        
        setFlash('success', __('Success'), __('Application created successfully.'));
        $this->redirect('apps.php');
    }

    /**
     * Processes the update of an application name or the regeneration of an API key.
     */
    public function update(array $postData, array $getData = []): never
    {
        $this->checkAuth();
        
        $id = $postData['id'] ?? null;
        if (!$id) {
            setFlash('danger', __('Error'), __('Application ID missing.'));
            $this->redirect('apps.php');
        }

        $appModel = new App();

        // Regenerate API key if sub_action=regenerate-key
        if (isset($getData['sub_action']) && $getData['sub_action'] === 'regenerate-key') {
            $newApiKey = bin2hex(random_bytes(32));
            $appModel->update((int)$id, ['api_key' => $newApiKey]);
            setFlash('success', __('Success'), __('API key regenerated successfully.'));
            $this->redirect('apps.php');
        }

        $payload = $postData;
        $validator = new Validation(['name' => __('Application name')]);
        
        $errors = $validator->validate([
            'name' => ['required', 'string', 'min:3'],
        ], $payload);

        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('apps.php');
        }

        $appModel->update((int)$id, [
            'name' => trim($payload['name'])
        ]);
        
        setFlash('success', __('Success'), __('Application updated successfully.'));
        $this->redirect('apps.php');
    }

    /**
     * Deletes an application from the system based on the ID provided via POST.
     */
    public function delete(array $postData): never
    {
        $this->checkAuth();
        
        $id = $postData['id'] ?? null;
        if ($id) {
            $appModel = new App();
            $appModel->delete((int)$id);
            setFlash('success', __('Success'), __('Application deleted successfully.'));
        }
        
        $this->redirect('apps.php');
    }
}
