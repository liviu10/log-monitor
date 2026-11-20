<?php

declare(strict_types=1);

namespace App\Controllers\Common;

use App\Core\BaseController;
use App\Utilities\SessionManager;

/**
 * Language Switcher Controller (LangController)
 *
 * Manages switching active application language and ensures open redirect protection.
 *
 * @category Controller
 *
 * @version  1.1
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
class LangController extends BaseController
{
    /**
     * Changes the current application language and saves it in the session.
     */
    public function change(): void
    {
        $supportedLocales = array_keys(supportedLocales());
        $rawLocale = config('app.locale', 'en');
        $default = is_string($rawLocale) && $rawLocale !== '' ? $rawLocale : 'en';
        $lang = $_GET['lang'] ?? $default;
        if (in_array($lang, $supportedLocales, true)) {
            SessionManager::set('app_lang', $lang);
        }

        // Prevent Open Redirect (check referer domain)
        $rawReferer = config('server.http_referer') ?? '/';
        $referer = is_scalar($rawReferer) ? (string) $rawReferer : '/';
        $rawAppUrl = defined('APP_URL') ? APP_URL : '';
        $appUrl = is_scalar($rawAppUrl) ? (string) $rawAppUrl : '';
        $allowedHost = parse_url($appUrl, PHP_URL_HOST);
        $refererHost = parse_url($referer, PHP_URL_HOST);

        if ($refererHost !== null && $refererHost !== $allowedHost) {
            $referer = '/';
        }

        redirect($referer);
    }
}
