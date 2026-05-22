<?php

namespace App\Controllers;

use App\Models\AppSetting;
use App\Utilities\Validation;

/**
 * Clasa AppSettingController
 *
 * Gestioneaza setarile aplicatiilor din panoul de administrare.
 * Permite listarea, crearea, actualizarea si stergerea setarilor (individual sau multiplu).
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.2
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
     * Adauga o setare noua sau mai multe setari simultan pentru o aplicatie.
     */
    public function store(array $postData): void
    {
        $this->checkAuth();

        $appId = isset($postData['app_id']) ? (int)$postData['app_id'] : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'ID aplicatie invalid.'], 400);
        }

        $appSettingModel = new AppSetting();

        // 1. Suport pentru salvare multipla (trimitere array in campul 'settings')
        if (isset($postData['settings']) && is_array($postData['settings'])) {
            $saved = 0;
            $errors = [];
            
            foreach ($postData['settings'] as $index => $item) {
                $key = isset($item['key']) ? trim($item['key']) : '';
                $value = isset($item['value']) ? trim($item['value']) : '';

                if (empty($key)) {
                    $errors[] = "Setarea #$index: Cheia este obligatorie.";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                    $errors[] = "Setarea '$key': Cheia poate conține doar litere, cifre, sublinieri (_), cratime (-) și puncte (.).";
                    continue;
                }

                $success = $appSettingModel->saveSetting($appId, $key, $value);
                if ($success) {
                    $saved++;
                } else {
                    $errors[] = "Setarea '$key': Eroare la salvare.";
                }
            }

            if (!empty($errors)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Salvare finalizată cu unele erori: ' . implode(' ', $errors),
                    'saved_count' => $saved
                ], 422);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'message' => "$saved setări au fost salvate cu succes."
            ]);
            return;
        }

        // 2. Comportamentul clasic pentru salvarea unei singure setari
        $validator = new Validation([
            'app_id' => 'ID aplicatie',
            'key' => 'cheie setare',
            'value' => 'valoare setare',
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
                'message' => 'O setare cu această cheie există deja.'
            ], 422);
            return;
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
     * Actualizeaza o setare existenta sau mai multe setari simultan.
     */
    public function update(array $postData): void
    {
        $this->checkAuth();

        $appId = isset($postData['app_id']) ? (int)$postData['app_id'] : 0;
        if ($appId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'ID aplicatie invalid.'], 400);
        }

        $appSettingModel = new AppSetting();

        // 1. Suport pentru actualizare multipla (trimitere array in campul 'settings')
        if (isset($postData['settings']) && is_array($postData['settings'])) {
            $updated = 0;
            $errors = [];

            foreach ($postData['settings'] as $index => $item) {
                $key = isset($item['key']) ? trim($item['key']) : '';
                $value = isset($item['value']) ? trim($item['value']) : '';
                $oldKey = isset($item['old_key']) ? trim($item['old_key']) : $key;

                if (empty($key)) {
                    $errors[] = "Setarea #$index: Cheia este obligatorie.";
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                    $errors[] = "Setarea '$key': Cheia poate conține doar litere, cifre, sublinieri (_), cratime (-) și puncte (.).";
                    continue;
                }

                $success = $appSettingModel->updateSetting($appId, $oldKey, [
                    'key' => $key,
                    'value' => $value
                ]);
                if ($success) {
                    $updated++;
                } else {
                    $errors[] = "Setarea '$key': Eroare la actualizare (verificati daca noua cheie nu este deja folosita).";
                }
            }

            if (!empty($errors)) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'Actualizare finalizată cu unele erori: ' . implode(' ', $errors),
                    'updated_count' => $updated
                ], 422);
                return;
            }

            $this->jsonResponse([
                'success' => true,
                'message' => "$updated setări au fost actualizate cu succes."
            ]);
            return;
        }

        // 2. Comportamentul clasic pentru actualizarea unei singure setari
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
            return;
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
