<?php

declare(strict_types=1);

namespace App\Core;

use App\Utilities\ResponseTrait;
use App\Utilities\SessionManager;

/**
 * Abstract Base Controller
 *
 * Provides common, reusable utilities for application controllers,
 * reducing code duplication (validation, redirection, flashes, and authorization).
 *
 * @category Core
 *
 * @version  1.3
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
abstract class BaseController
{
    use ResponseTrait;

    /**
     * Renders a Blade template using the View class integrated with BladeOne.
     *
     * @param  string  $view  Template name.
     * @param  array<string, mixed>  $data  Parameters passed to template.
     * @return string Rendered HTML content.
     */
    protected function render(string $view, array $data = []): string
    {
        return View::render($view, $data);
    }

    /**
     * Validates input according to specified rules.
     * If validation fails, user is redirected back with errors saved in the session.
     *
     * @param  array<string, array<int, string>>  $rules  Validation rules set.
     * @param  array<string, mixed>  $data  Data to validate (defaults to $_POST).
     * @return array<string, mixed> Validated input data.
     */
    protected function validate(array $rules, array $data = []): array
    {
        if (count($data) === 0) {
            $data = $_POST;
        }

        $fieldNames = [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'password' => 'Password',
        ];
        $validator = new Validation($fieldNames);
        $rawErrors = $validator->validate($rules, $data);

        if (count($rawErrors) > 0) {
            $formattedErrors = [];
            foreach ($rawErrors as $field => $rulesFailed) {
                if ($field !== '') {
                    foreach ($rulesFailed as $rule) {
                        if ($rule !== '') {
                            $formattedErrors[$field][] = $validator->messages($field, $rule);
                        }
                    }
                }
            }
            SessionManager::set('errors', $formattedErrors);
            SessionManager::set('old', $data);

            $this->flash('error', __('Validation Error'), __('Please fix the validation errors in the form.'));

            $rawReferer = config('server.http_referer') ?? '/';
            $referer = is_scalar($rawReferer) ? (string) $rawReferer : '/';
            $this->redirect($referer);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Checks if the currently authenticated user has the required permission.
     * If they do not, they are redirected to their profile page with an error.
     */
    protected function checkPermission(string $permission): void
    {
        $hasAccess = (function_exists('hasPermission') && hasPermission($permission))
            || (function_exists('can') && can($permission));

        if (! $hasAccess) {
            $this->flash('error', __('Permission Denied'), __('You do not have permission to perform this action.'));
            $this->redirect('/admin/settings/users/profile');
        }
    }
}
