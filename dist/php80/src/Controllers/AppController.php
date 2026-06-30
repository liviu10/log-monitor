<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\App;
use App\Utilities\Validation;
use App\Utilities\LogViaStream;
use App\Enums\LogLevel;

/**
 * Clasa AppController
 *
 * Gestioneaza interfata de administrare pentru aplicatiile inregistrate.
 * Permite listarea, crearea, actualizarea si stergerea aplicatiilor, asigurand
 * generarea de chei API unice si validarea stricta a datelor introduse.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class AppController extends BaseController
{
    /**
     * Afiseaza lista tuturor aplicatiilor inregistrate.
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
     * Proceseaza adaugarea unei noi aplicatii in sistem.
     * Genereaza automat o cheie API securizata de 64 de caractere.
     * @return never
     */
    public function store(array $postData): void
    {
        $this->checkAuth();
        
        $payload = $postData;
        $validator = new Validation(['name' => __('Application name')]);
        
        $errors = $validator->validate([
            'name' => ['required', 'string', 'min:3'],
        ], $payload);

        if ($errors !== []) {
            $_SESSION['errors'] = $errors;
            $this->redirect('apps.php');
        }

        try {
            $appModel = new App();
            $apiKey = bin2hex(random_bytes(32));
            
            $appModel->create(trim($payload['name']), $apiKey);
            
            setFlash('success', __('Success'), __('Application created successfully.'));
        } catch (\Throwable $throwable) {
            LogViaStream::send(LogLevel::ERROR, 'Failed to create application record', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                'exception_trace' => $throwable->getTraceAsString(),
                'payload' => $payload,
                'identifier' => 'AppController_Store_Failure'
            ]);
            setFlash('danger', __('Error'), __('Failed to create application.'));
        }
        
        $this->redirect('apps.php');
    }

    /**
     * Proceseaza actualizarea numelui unei aplicatii sau regenerarea cheii API.
     * @return never
     */
    public function update(array $postData, array $getData = []): void
    {
        $this->checkAuth();
        
        $id = $postData['id'] ?? null;
        if (!$id) {
            setFlash('danger', __('Error'), __('Missing application ID.'));
            $this->redirect('apps.php');
        }

        $appModel = new App();
        $appId = (int)$id;

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

            if ($errors !== []) {
                $_SESSION['errors'] = $errors;
                $this->redirect('apps.php');
            }

            $appModel->update($appId, [
                'name' => trim($payload['name'])
            ]);
            
            setFlash('success', __('Success'), __('Application updated successfully.'));
        } catch (\Throwable $throwable) {
            LogViaStream::send(LogLevel::ERROR, 'Failed to update application data', [
                'location' => __METHOD__,
                'line' => __LINE__,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                'exception_trace' => $throwable->getTraceAsString(),
                'app_id' => $appId,
                'identifier' => 'AppController_Update_Failure'
            ]);
            setFlash('danger', __('Error'), __('Failed to update application.'));
        }

        $this->redirect('apps.php');
    }

    /**
     * Sterge o aplicatie din sistem pe baza ID-ului furnizat prin POST.
     * @return never
     */
    public function delete(array $postData): void
    {
        $this->checkAuth();
        
        $id = $postData['id'] ?? null;
        if ($id) {
            try {
                $appModel = new App();
                $appModel->delete((int)$id);
                setFlash('success', __('Success'), __('Application deleted successfully.'));
            } catch (\Throwable $e) {
                LogViaStream::send(LogLevel::ERROR, 'Failed to delete application', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'app_id' => $id,
                    'identifier' => 'AppController_Delete_Failure'
                ]);
                setFlash('danger', __('Error'), __('Failed to delete application.'));
            }
        }
        
        $this->redirect('apps.php');
    }
}