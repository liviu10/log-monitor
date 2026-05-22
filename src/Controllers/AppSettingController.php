<?php

namespace App\Controllers;

use App\Models\AppSetting;
use App\Utilities\Validation;

/**
 * Clasa AppSettingController
 *
 * Gestioneaza setarile aplicatiilor din panoul de administrare.
 * Permite listarea, crearea, actualizarea si stergerea setarilor.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */
class AppSettingController extends BaseController
{
    /**
     * Returneaza toate setarile pentru o aplicatie specifica, sub forma de raspuns JSON.
     */
    public function index(array $getData): void
    {
        $this->checkAuth();

        $appId = isset($getData['app_id']) ? (int)$getData['app_id'] : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'ID aplicatie invalid.'], 400);
        }

        $appSettingModel = new AppSetting();
        $settings = $appSettingModel->getSettingsForApp($appId);

        $this->jsonResponse([
            'success' => true,
            'settings' => $settings
        ]);
    }

    /**
     * Adauga o setare noua pentru o aplicatie.
     */
    public function store(array $postData): void
    {
        $this->checkAuth();

        $payload = $postData;
        $validator = new Validation([
            'app_id' => 'ID aplicatie',
            'key' => 'cheie setare',
            'value' => 'valoare setare',
        ]);

        $errors = $validator->validate([
            'app_id' => ['required', 'int'],
            'key' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'value' => ['required', 'string'],
        ], $payload);

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
        }

        $appId = (int)$payload['app_id'];
        $key = trim($payload['key']);
        $value = trim($payload['value']);

        $appSettingModel = new AppSetting();

        if ($appSettingModel->getSetting($appId, $key)) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'O setare cu această cheie există deja.'
            ], 422);
        }

        $success = $appSettingModel->saveSetting($appId, $key, $value);

        if ($success) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Setarea a fost salvată cu succes.'
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Eroare la salvarea setării în baza de date.'
            ], 500);
        }
    }

    /**
     * Actualizeaza o setare existenta a unei aplicatii.
     */
    public function update(array $postData): void
    {
        $this->checkAuth();

        $payload = $postData;
        $validator = new Validation([
            'app_id' => 'ID aplicatie',
            'key' => 'cheie setare',
            'value' => 'valoare setare',
            'old_key' => 'cheie originala setare',
        ]);

        $errors = $validator->validate([
            'app_id' => ['required', 'int'],
            'key' => ['required', 'string', 'min:1', 'max:255', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'value' => ['required', 'string'],
            'old_key' => ['required', 'string'],
        ], $payload);

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
        }

        $appId = (int)$payload['app_id'];
        $key = trim($payload['key']);
        $value = trim($payload['value']);
        $oldKey = trim($payload['old_key']);

        $appSettingModel = new AppSetting();
        $success = $appSettingModel->updateSetting($appId, $oldKey, [
            'key' => $key,
            'value' => $value
        ]);

        if ($success) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Setarea a fost actualizată cu succes.'
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Eroare la actualizarea setării. Verificați dacă noua cheie nu există deja.'
            ], 400);
        }
    }

    /**
     * Sterge o setare a unei aplicatii.
     */
    public function delete(array $postData): void
    {
        $this->checkAuth();

        $appId = isset($postData['app_id']) ? (int)$postData['app_id'] : 0;
        $key = isset($postData['key']) ? trim($postData['key']) : '';

        if ($appId <= 0 || empty($key)) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Parametri invalizi pentru stergerea setarii.'
            ], 400);
        }

        $appSettingModel = new AppSetting();
        $success = $appSettingModel->deleteSetting($appId, $key);

        if ($success) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Setarea a fost stearsa cu succes.'
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Setarea nu a putut fi stearsa.'
            ], 500);
        }
    }
}
