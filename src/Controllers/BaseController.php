<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * BaseController Class
 *
 * Serves as the base class for all controllers in the application.
 * Provides utility methods for managing JSON responses, rendering views, redirections, and authentication checking.
 * Also includes setting security headers for all protected requests.
 *
 * @category Controller
 * @package  App\Controllers
 * @version  1.1
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */
class BaseController
{
    /**
     * Sends a response in JSON format to the client.
     *
     * @param array $data   Data to be JSON encoded.
     * @param int   $status HTTP status code (default 200).
     */
    protected function jsonResponse(array $data, int $status = 200): never
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    /**
     * Render a view file and extract the provided data.
     *
     * @param string $view Name/Path of the view file.
     * @param array  $data Data to be available in the view.
     */
    protected function render(string $view, array $data = []): void
    {
        extract($data);
        require_once __DIR__ . '/../../views/' . $view . '.php';
    }

    /**
     * Redirect the user to a specified URL and stop execution.
     *
     * @param string $url Destination URL.
     */
    protected function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Checks if the user is authenticated and applies security headers.
     *
     * @return array|null User data if authenticated.
     */
    protected function checkAuth(): ?array
    {
        // Adding security headers for each authenticated request
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        checkAuthUser();
        return getCurrentAuthUser();
    }
}
