<?php

namespace App\Controllers;

use App\Models\App;
use App\Utilities\Validation;

/**
 * Clasa AppController
 *
 * Gestioneaza interfata de administrare a aplicatiilor inregistrate.
 * Permite listarea, crearea si stergerea aplicatiilor, asigurand generarea cheilor API unice 
 * si validarea datelor introduse de utilizator.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
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
        
        setFlash('success', 'Succes', 'Aplicatia a fost creata cu succes.');
        $this->redirect('apps.php');
    }

    /**
     * Proceseaza actualizarea numelui unei aplicatii sau regenerarea cheii API.
     */
    public function update(array $postData, array $getData = []): never
    {
        $this->checkAuth();
        
        $id = $postData['id'] ?? null;
        if (!$id) {
            setFlash('danger', 'Eroare', 'ID aplicatie lipsa.');
            $this->redirect('apps.php');
        }

        $appModel = new App();

        // Regenerare cheie API daca sub_action=regenerate-key
        if (isset($getData['sub_action']) && $getData['sub_action'] === 'regenerate-key') {
            $newApiKey = bin2hex(random_bytes(32));
            $appModel->update((int)$id, ['api_key' => $newApiKey]);
            setFlash('success', 'Succes', 'Cheia API a fost regenerata cu succes.');
            $this->redirect('apps.php');
        }

        $payload = $postData;
        $validator = new Validation(['name' => 'nume aplicatie']);
        
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
        
        setFlash('success', 'Succes', 'Aplicatia a fost actualizata cu succes.');
        $this->redirect('apps.php');
    }

    /**
     * Sterge o aplicatie din sistem pe baza ID-ului furnizat prin POST.
     */
    public function delete(array $postData): never
    {
        $this->checkAuth();
        
        $id = $postData['id'] ?? null;
        if ($id) {
            $appModel = new App();
            $appModel->delete((int)$id);
            setFlash('success', 'Succes', 'Aplicatia a fost stearsa.');
        }
        
        $this->redirect('apps.php');
    }
}
