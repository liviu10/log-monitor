<?php

declare(strict_types=1);

use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\BotDetectionMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\GuestMiddleware;
use App\Middleware\PermissionMiddleware;

/**
 * Web Routes for LogMonitor
 *
 * Private monitoring application: no public CMS, SEO, sitemap, or LLM endpoints.
 */
return function (Router $router): void {
    // Root entry redirects cleanly based on authentication state
    $router->get('/', static function (): never {
        if (isset($_SESSION['auth.user'])) {
            redirect('/admin');
        }
        redirect('/login');
    });

    // Language switcher
    $router->get('/change-lang', 'Common\LangController@change');

    // Email verification routes (public with signed token)
    $router->get('/verify-email', 'Auth\AuthController@showVerifyEmail');
    $router->post('/verify-email', 'Auth\AuthController@verifyEmail');

    // Guest authentication routes
    $router->group(['middleware' => [GuestMiddleware::class]], function (Router $r): void {
        $r->get('/login', 'Auth\AuthController@showLogin');

        $r->group(['middleware' => [CsrfMiddleware::class, BotDetectionMiddleware::class]], function (Router $rPost): void {
            $rPost->post('/login', 'Auth\AuthController@login');
        });
    });

    // Authenticated routes
    $router->group(['middleware' => [AuthMiddleware::class]], function (Router $r): void {
        $r->get('/admin/settings/users/profile', 'Auth\AuthController@profile');

        $r->group(['middleware' => [CsrfMiddleware::class]], function (Router $rPost): void {
            $rPost->post('/logout', 'Auth\AuthController@logout');
            $rPost->post('/admin/settings/users/profile', 'Auth\AuthController@updateProfile');
        });

        // Core LogMonitor Administrative Routes
        $r->group(['prefix' => '/admin', 'middleware' => [PermissionMiddleware::class]], function (Router $rAdmin): void {
            $rAdmin->get('/', 'Admin\DashboardController@index');
            $rAdmin->get('/chart-data', 'Admin\DashboardController@chartData');

            // Logs timeline
            $rAdmin->get('/logs', 'Admin\LogsController@index');

            // High-throughput raw log ingestion queue
            $rAdmin->get('/log-queue', 'Admin\LogQueueController@index');

            // Sent notifications & async notification delivery queue
            $rAdmin->get('/notifications', 'Admin\NotificationsController@index');
            $rAdmin->get('/notification-queue', 'Admin\NotificationQueueController@index');

            // Registered applications & per-app alert settings
            $rAdmin->get('/apps', 'Admin\AppsController@index');
            $rAdmin->get('/apps/{id}/settings', 'Admin\AppSettingsController@index');

            $rAdmin->group(['middleware' => [CsrfMiddleware::class]], function (Router $rAdminPost): void {
                $rAdminPost->post('/log-queue/run-worker', 'Admin\LogQueueController@runWorker');
                $rAdminPost->post('/log-queue/retry-all', 'Admin\LogQueueController@retryAll');
                $rAdminPost->post('/log-queue/{id}/retry', 'Admin\LogQueueController@retry');
                $rAdminPost->post('/log-queue/{id}/delete', 'Admin\LogQueueController@delete');
                $rAdminPost->post('/log-queue/purge', 'Admin\LogQueueController@purge');

                $rAdminPost->post('/notification-queue/retry-all', 'Admin\NotificationQueueController@retryAll');
                $rAdminPost->post('/notification-queue/{id}/retry', 'Admin\NotificationQueueController@retry');
                $rAdminPost->post('/notification-queue/{id}/delete', 'Admin\NotificationQueueController@delete');
                $rAdminPost->post('/notification-queue/run-worker', 'Admin\NotificationQueueController@runWorker');
                $rAdminPost->post('/notification-queue/purge', 'Admin\NotificationQueueController@purge');

                $rAdminPost->post('/apps', 'Admin\AppsController@store');
                $rAdminPost->put('/apps/{id}', 'Admin\AppsController@update');
                $rAdminPost->post('/apps/{id}', 'Admin\AppsController@update');
                $rAdminPost->post('/apps/{id}/regenerate-key', 'Admin\AppsController@regenerateKey');
                $rAdminPost->post('/apps/{id}/delete', 'Admin\AppsController@delete');

                $rAdminPost->post('/apps/{id}/settings', 'Admin\AppSettingsController@store');
                $rAdminPost->put('/apps/{id}/settings', 'Admin\AppSettingsController@update');
                $rAdminPost->post('/apps/{id}/settings/delete', 'Admin\AppSettingsController@delete');
            });
        });

        // Settings: users, roles, permissions
        $r->group(['prefix' => '/admin/settings', 'middleware' => [PermissionMiddleware::class]], function (Router $rSettings): void {
            $rSettings->get('/users', 'Admin\Settings\UsersController@index');
            $rSettings->get('/roles', 'Admin\Settings\RolesController@index');
            $rSettings->get('/roles/{role_id}/permissions', 'Admin\Settings\RolesController@permissions');
            $rSettings->get('/queue', static function (): never {
                redirect('/admin/notification-queue');
            });

            $rSettings->group(['middleware' => [CsrfMiddleware::class]], function (Router $rPost): void {
                $rPost->post('/users', 'Admin\Settings\UsersController@store');
                $rPost->put('/users/{id}', 'Admin\Settings\UsersController@update');
                $rPost->post('/roles/{role_id}/permissions', 'Admin\Settings\RolesController@updatePermissions');
            });
        });
    });
};
