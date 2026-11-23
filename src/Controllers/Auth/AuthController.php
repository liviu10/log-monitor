<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\BaseController;
use App\Core\LogViaStream;
use App\Core\MySQLWrapper;
use App\Core\Queue;
use App\Enums\LogLevel;
use App\Enums\UserStatus;
use App\Models\User;
use App\Utilities\FormGenerator;
use App\Utilities\InternalFileManager;
use App\Utilities\LoginThrottle;
use App\Utilities\SessionManager;
use Throwable;

/**
 * Authentication Controller
 *
 * Manages login, logout, profile update, and email verification processes.
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
class AuthController extends BaseController
{
    /** Argon2id hash of a random throw-away password: equalises login timing for unknown e-mails. */
    /** Minimum length for a new password. */
    private const MIN_PASSWORD_LENGTH = 12;

    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Z3NIeTFaNTFNdDhWbDVhUg$H08os2ACzJknIX2p0tPQD9uvT7jM/DQLhZrSYabe+8Q';

    /**
     * Displays the login page.
     *
     * @return string Rendered HTML content.
     */
    public function showLogin(): string
    {
        if (isset($_GET['inactivity'])) {
            $this->flash('warning', __('Notice'), __('You have been logged out due to inactivity.'));
        }

        $generator = new FormGenerator(
            MySQLWrapper::getInstance(),
            'users',
            ['email']
        );
        $form = $generator->buildForm();
        $form[] = [
            'key' => 'password',
            'label' => 'Password',
            'type' => 'password',
            'value' => '',
            'options' => null,
        ];
        foreach ($form as &$field) {
            if ($field['key'] === 'email') {
                $oldVal = old('email', '');
                $field['value'] = is_string($oldVal) ? $oldVal : '';
            }
        }
        unset($field);
        $form = FormGenerator::attachAntiBotFields($form);

        return $this->render('guest.login', [
            'title' => __('Login'),
            'form' => $form,
        ]);
    }

    /**
     * Processes the login attempt.
     * Performs secure password verification and session management.
     */
    public function login(): void
    {
        $data = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $email = isset($data['email']) && is_string($data['email']) ? trim($data['email']) : '';

        // Per-account lockout (the per-IP limit alone is bypassed by distributed attacks).
        if (LoginThrottle::tooManyAttempts($email)) {
            $this->redirectWithFlash('/login', 'error', __('Login Failed'), __('Too many failed attempts. Please try again later.'));
        }

        $user = User::findByEmail($email);

        $password = isset($data['password']) && is_string($data['password']) ? $data['password'] : '';
        // Unknown e-mail: verify against a dummy hash so the response time does not
        // reveal whether the account exists.
        $passwordHash = is_array($user) && isset($user['password_hash']) && is_string($user['password_hash'])
            ? $user['password_hash']
            : self::DUMMY_PASSWORD_HASH;
        $passwordValid = password_verify($password, $passwordHash);

        if (! is_array($user) || ! $passwordValid) {
            LoginThrottle::hit($email);
            $this->redirectWithFlash('/login', 'error', __('Login Failed'), __('Invalid email or password.'));
        }

        LoginThrottle::clear($email);

        if (($user['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            $this->redirectWithFlash('/login', 'error', __('Login Failed'), __('Your account is inactive.'));
        }

        // Regenerate session ID against Session Fixation
        session_regenerate_id(true);

        // Manually send the new session cookie to bypass FrankenPHP edge cases
        $sessIdRaw = session_id();
        $newSessionId = is_string($sessIdRaw) ? $sessIdRaw : '';
        $sessNameRaw = session_name();
        $sessName = is_string($sessNameRaw) ? $sessNameRaw : 'PHPSESSID';
        $cookieParams = session_get_cookie_params();
        setcookie(
            $sessName,
            $newSessionId,
            [
                'expires' => $cookieParams['lifetime'] > 0 ? time() + $cookieParams['lifetime'] : 0,
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => $cookieParams['secure'],
                'httponly' => $cookieParams['httponly'],
                'samesite' => $cookieParams['samesite'],
            ]
        );

        $rawUserId = $user['id'] ?? null;
        $userId = is_scalar($rawUserId) ? (int) $rawUserId : 0;
        $rawUserRoleId = $user['role_id'] ?? null;
        $userRoleId = is_scalar($rawUserRoleId) ? (int) $rawUserRoleId : 0;
        $userStatus = is_string($user['status'] ?? null) ? $user['status'] : null;
        $userEmail = is_string($user['email'] ?? null) ? $user['email'] : '';
        $userFirstName = is_string($user['first_name'] ?? null) ? $user['first_name'] : '';
        $userLastName = is_string($user['last_name'] ?? null) ? $user['last_name'] : '';
        $userRoleName = is_string($user['role_name'] ?? null) ? $user['role_name'] : '';
        $userEmailVerifiedAt = $user['email_verified_at'] ?? null;

        $userProfileImage = is_string($user['profile_image'] ?? null) ? $user['profile_image'] : '';

        SessionManager::set('auth.user', [
            'id' => $userId,
            'first_name' => $userFirstName,
            'last_name' => $userLastName,
            'email' => $userEmail,
            'profile_image' => $userProfileImage,
            'role_id' => $userRoleId,
            'role_name' => $userRoleName,
            'status' => UserStatus::getLabel($userStatus),
            'email_verified_at' => $userEmailVerifiedAt,
        ]);

        // Fresh CSRF token for the authenticated session (the pre-login token may be known
        // to whoever planted it) and re-bind it to the user.
        unset($_SESSION['csrf_token']);
        $_SESSION['csrf_token_user'] = $userId;
        // Role/status were just read from the database (see AuthMiddleware revalidation).
        $_SESSION['auth.verified_at'] = time();

        // If the account is not verified, simulate sending the verification email
        if ($userEmailVerifiedAt === null) {
            $this->sendVerificationEmail($userId, $userEmail);
        }

        setFlash('success', __('Welcome'), __('You have logged in successfully.'));

        if ($userRoleName === 'ADMIN') {
            redirect('/admin');
        } else {
            redirect('/admin/settings/users/profile');
        }
    }

    /**
     * Displays the current user's profile page.
     */
    public function profile(): string
    {
        $rawUser = SessionManager::get('auth.user', []);
        $user = is_array($rawUser) ? $rawUser : [];

        $generator = new FormGenerator(
            MySQLWrapper::getInstance(),
            'users',
            ['first_name', 'last_name', 'email']
        );
        $form = $generator->buildForm();
        foreach ($form as &$field) {
            $key = $field['key'];
            $defaultVal = isset($user[$key]) && is_string($user[$key]) ? $user[$key] : '';
            $oldVal = old($key, $defaultVal);
            $field['value'] = is_string($oldVal) ? $oldVal : '';
        }
        unset($field);
        $form = FormGenerator::attachAntiBotFields($form);

        return $this->render('admin.settings.users.profile', [
            'title' => __('Profile'),
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * Processes saving profile changes for a user.
     * If the email is modified, verification state is reset and email is resent.
     */
    public function updateProfile(): void
    {
        $currentUser = SessionManager::get('auth.user');
        if (! is_array($currentUser)) {
            redirect('/login');
        }

        $currentUserId = is_scalar($currentUser['id'] ?? null) ? (int) $currentUser['id'] : 0;
        $currentUserEmail = is_string($currentUser['email'] ?? null) ? $currentUser['email'] : '';

        $data = $this->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:50'],
            'last_name' => ['required', 'string', 'min:2', 'max:50'],
        ]);

        $firstName = is_string($data['first_name'] ?? null) ? $data['first_name'] : '';
        $lastName = is_string($data['last_name'] ?? null) ? $data['last_name'] : '';

        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $passwordConfirmation = isset($_POST['password_confirmation']) && is_string($_POST['password_confirmation']) ? $_POST['password_confirmation'] : '';

        if ($password !== '') {
            if ($password !== $passwordConfirmation) {
                $this->redirectWithFlash('/admin/settings/users/profile', 'error', __('Update Failed'), __('Passwords do not match.'));
            }
            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $this->redirectWithFlash('/admin/settings/users/profile', 'error', __('Update Failed'), __('Password must be at least 12 characters long.'));
            }

            // Re-authenticate: a hijacked session (or an XSS) must not be enough to take
            // over the account permanently by changing its password.
            $currentPassword = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
            $storedUser = User::findById($currentUserId);
            $storedHash = is_array($storedUser) && is_string($storedUser['password_hash'] ?? null) ? $storedUser['password_hash'] : self::DUMMY_PASSWORD_HASH;
            if ($currentPassword === '' || ! password_verify($currentPassword, $storedHash)) {
                $this->redirectWithFlash('/admin/settings/users/profile', 'error', __('Update Failed'), __('Current password is incorrect.'));
            }

            User::updateUser($currentUserId, ['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);

            // New credentials => new session identifier.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }

        $profileImage = $_FILES['profile_image'] ?? null;
        if (
            is_array($profileImage) &&
            isset($profileImage['error']) &&
            $profileImage['error'] === UPLOAD_ERR_OK &&
            is_string($profileImage['name'] ?? null) &&
            is_string($profileImage['tmp_name'] ?? null) &&
            is_string($profileImage['type'] ?? null) &&
            is_int($profileImage['size'] ?? null)
        ) {
            $uploadFile = [
                'name' => $profileImage['name'],
                'tmp_name' => $profileImage['tmp_name'],
                'type' => $profileImage['type'],
                'error' => $profileImage['error'],
                'size' => $profileImage['size'],
            ];
            $uploadedPath = InternalFileManager::upload($uploadFile, (string) $currentUserId, 'profile');
            if ($uploadedPath !== false) {
                User::updateUser($currentUserId, ['profile_image' => $uploadedPath]);
                $authData = SessionManager::get('auth.user');
                if (is_array($authData)) {
                    $authData['profile_image'] = $uploadedPath;
                    SessionManager::set('auth.user', $authData);
                }
            } else {
                $this->redirectWithFlash('/admin/settings/users/profile', 'error', __('Update Failed'), __('Could not upload profile image.'));
            }
        }

        User::updateProfile($currentUserId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $currentUserEmail,
        ]);

        $authData = SessionManager::get('auth.user');
        if (is_array($authData)) {
            $authData['first_name'] = $firstName;
            $authData['last_name'] = $lastName;
            SessionManager::set('auth.user', $authData);
        }

        $this->redirectWithFlash(
            '/admin/settings/users/profile',
            'success',
            __('Profile Updated'),
            __('Your profile has been updated successfully.')
        );
    }

    /**
     * Displays email confirmation form received via token.
     */
    public function showVerifyEmail(): string
    {
        $token = $_GET['token'] ?? '';

        return $this->render('guest.verify_email', [
            'title' => __('Confirm Email Address'),
            'token' => $token,
        ]);
    }

    /**
     * Processes the email verification form by decoding the token.
     */
    public function verifyEmail(): void
    {
        $data = $this->validate([
            'email' => ['required', 'email'],
            'token' => ['required'],
        ]);

        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $payload = $this->decodeVerificationToken($token);

        if ($payload === null) {
            $this->redirectWithFlash('/admin/settings/users/profile', 'error', __('Verification Failed'), __('The verification token is invalid.'));
        }

        $rawExpiresAt = $payload['expires_at'] ?? 0;
        $expiresAt = is_scalar($rawExpiresAt) ? (int) $rawExpiresAt : 0;
        if ($expiresAt < time()) {
            $this->redirectWithFlash('/admin/settings/users/profile', 'error', __('Verification Failed'), __('The verification link has expired.'));
        }

        $payloadEmail = is_string($payload['email'] ?? null) ? $payload['email'] : '';
        $dataEmail = is_string($data['email'] ?? null) ? $data['email'] : '';
        if (strtolower($payloadEmail) !== strtolower($dataEmail)) {
            $this->redirectWithFlash(
                '/verify-email?token='.urlencode($token),
                'error',
                __('Verification Failed'),
                __('The email address does not match the token.')
            );
        }

        $rawPayloadId = $payload['id'] ?? null;
        $payloadId = is_scalar($rawPayloadId) ? (int) $rawPayloadId : 0;

        // Mark the email as verified in the database
        User::updateEmailVerifiedAt($payloadId, date('Y-m-d H:i:s'));

        // Sync status in session if it is the same user
        $sessionUser = SessionManager::get('auth.user');
        if (is_array($sessionUser) && is_scalar($sessionUser['id'] ?? null) && (int) $sessionUser['id'] === $payloadId) {
            $sessionUser['email_verified_at'] = date('Y-m-d H:i:s');
            SessionManager::set('auth.user', $sessionUser);
        }

        $this->redirectWithFlash('/admin/settings/users/profile', 'success', __('Verification Successful'), __('Your email has been verified.'));
    }

    /**
     * Logs out the user from the session.
     */
    public function logout(): void
    {
        $inactivity = ($_POST['inactivity'] ?? '') === '1' ? '?inactivity=1' : '';
        SessionManager::remove('auth.user');
        session_destroy();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);

        redirect('/login'.$inactivity);
    }

    /**
     * Sends the verification email asynchronously by adding a job to the queue.
     */
    private function sendVerificationEmail(int $userId, string $email): void
    {
        $token = $this->generateVerificationToken($userId, $email);
        $rawAppUrl = defined('APP_URL') ? APP_URL : '';
        $appUrl = is_scalar($rawAppUrl) ? (string) $rawAppUrl : '';
        $link = $appUrl.'/verify-email?token='.urlencode($token);

        Queue::push('emails', [
            'action' => 'email',
            'payload' => [
                'template_event' => 'user_email_verification',
                'data' => [
                    'to' => $email,
                    'link' => $link,
                ],
            ],
        ]);
    }

    /**
     * Generates an AES-256-CBC encrypted token containing user ID, email, and expiration (3 days).
     */
    private function generateVerificationToken(int $userId, string $email): string
    {
        $payloadRaw = json_encode([
            'id' => $userId,
            'email' => $email,
            'expires_at' => time() + (3 * 24 * 60 * 60),
        ]);
        $payload = is_string($payloadRaw) ? $payloadRaw : '{}';

        $rawKey = config('app.key') ?? 'aegis-secret-key-32-chars-length!';
        $key = is_string($rawKey) ? $rawKey : 'aegis-secret-key-32-chars-length!';
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($payload, 'aes-256-cbc', $key, 0, $iv);

        $mac = hash_hmac('sha256', $iv.$encrypted, $key);

        return base64_encode($iv.$encrypted.$mac);
    }

    /**
     * Decodes and decrypts an email verification token.
     *
     * @return array<string, mixed>|null
     */
    private function decodeVerificationToken(string $token): ?array
    {
        try {
            $data = base64_decode($token, true);
            // 16 bytes IV + at least 16 bytes ciphertext + 64 bytes HMAC (SHA256 hex string)
            if (! is_string($data) || $data === '' || strlen($data) < 96) {
                return null;
            }

            $iv = substr($data, 0, 16);
            $mac = substr($data, -64);
            $encrypted = substr($data, 16, -64);

            $rawKey = config('app.key') ?? 'aegis-secret-key-32-chars-length!';
            $key = is_string($rawKey) ? $rawKey : 'aegis-secret-key-32-chars-length!';

            $calculatedMac = hash_hmac('sha256', $iv.$encrypted, $key);

            if (! hash_equals($calculatedMac, $mac)) {
                return null;
            }

            $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);

            if (! is_string($decrypted) || $decrypted === '') {
                return null;
            }
            $decoded = json_decode($decrypted, true);
            if (! is_array($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (Throwable $e) {
            if (class_exists(LogViaStream::class) && class_exists(LogLevel::class)) {
                LogViaStream::send(LogLevel::ERROR->value, 'Failed to decode verification token', [
                    'location' => __METHOD__,
                    'line' => __LINE__,
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'exception_trace' => $e->getTraceAsString(),
                    'identifier' => 'AuthController_DecodeVerificationToken_Failure',
                ]);
            }

            return null;
        }
    }
}
