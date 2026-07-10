<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * BaseController Class
 *
 * Serves as the base class for all controllers in the application.
 * Offers utility methods for managing JSON responses, rendering views,
 * redirects, and authentication checks, including applying security headers.
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
class BaseController
{
    /**
     * Sends a JSON response to the client and stops execution.
     *
     * @param  array  $data  The data to be encoded in JSON format.
     * @param  int  $status  The HTTP status code (default 200).
     */
    protected function jsonResponse(array $data, int $status = 200): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Renders a view file and extracts the provided data.
     *
     * @param  string  $view  Name or path of the view file.
     * @param  array  $data  Data that will be available in the view.
     */
    protected function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require_once __DIR__.'/../../views/'.$view.'.php';
    }

    /**
     * Redirects the user to a specified URL and terminates execution.
     *
     * @param  string  $url  Destination URL.
     */
    protected function redirect(string $url): never
    {
        header('Location: '.$url);
        exit;
    }

    /**
     * Verifies if the user is authenticated and applies OWASP security headers.
     *
     * @return array|null The user's data if authenticated.
     */
    protected function checkAuth(): ?array
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        checkAuthUser();

        return getCurrentAuthUser();
    }
}
