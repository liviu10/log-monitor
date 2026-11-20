<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Exceptions\RedirectException;

/**
 * Response Trait
 *
 * Provides common, reusable utilities for HTTP responses (JSON, redirects, flashes).
 *
 * @category Traits
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
trait ResponseTrait
{
    /**
     * Redirects the user to another URL and terminates execution.
     *
     * @param  string  $url  The target URL.
     */
    protected function redirect(string $url): never
    {
        if (defined('PHPUNIT_RUNNING')) {
            throw new RedirectException($url);
        }
        // @codeCoverageIgnoreStart
        if (function_exists('redirect')) {
            redirect($url);
        }
        header("Location: {$url}");
        exit;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Registers a flash message in session.
     *
     * @param  string  $type  Flash type (success, error, warning).
     * @param  string  $title  The message title.
     * @param  string  $message  The message body text.
     */
    protected function flash(string $type, string $title, string $message): void
    {
        if (function_exists('setFlash')) {
            setFlash($type, $title, $message);
        }
    }

    /**
     * Saves a flash message and redirects the user.
     *
     * @param  string  $url  Destination URL.
     * @param  string  $type  Flash type.
     * @param  string  $title  Title.
     * @param  string  $message  Text message.
     */
    protected function redirectWithFlash(string $url, string $type, string $title, string $message): never
    {
        $this->flash($type, $title, $message);
        $this->redirect($url);
    }

    /**
     * Sends a JSON response to the browser.
     *
     * @param  array<string, mixed>  $data  The dataset to transmit.
     * @param  int  $statusCode  The HTTP status code of the response.
     *
     * @codeCoverageIgnore
     */
    protected function json(array $data, int $statusCode = 200): never
    {
        if (defined('PHPUNIT_RUNNING')) {
            throw new \RuntimeException('JSON_RESPONSE:'.$statusCode.':'.json_encode($data));
        }

        // @codeCoverageIgnoreStart
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-cache');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
        // @codeCoverageIgnoreEnd
    }
}
