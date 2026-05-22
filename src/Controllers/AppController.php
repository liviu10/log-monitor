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
    public function store(): never
    {
        $this->checkAuth();
        
        $payload = $_POST;
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
     * Sterge o aplicatie din sistem pe baza ID-ului furnizat prin POST.
     */
    public function delete(): never
    {
        $this->checkAuth();
        
        $id = $_POST['id'] ?? null;
        if ($id) {
            $appModel = new App();
            $appModel->delete((int)$id);
            setFlash('success', 'Succes', 'Aplicatia a fost stearsa.');
        }
        
        $this->redirect('apps.php');
    }
}
