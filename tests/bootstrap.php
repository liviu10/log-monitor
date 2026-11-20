<?php

declare(strict_types=1);

// Mock standard server variables for test environment bootstrap
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PORT'] = '8082';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit-Test-Agent';

if (session_status() === PHP_SESSION_NONE) {
    $_SESSION = [];
}

if (! defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

// Require global bootstrap configuration
require_once dirname(__DIR__).'/config/bootstrap.php';
