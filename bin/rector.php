<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Rector Configuration: Code transpiling, downgrade, and cleaning.
 *
 * @category Configuration
 * @package  Rector
 * @version  1.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietary
 */

// Read the target version from environment variable (default is 74 for PHP 7.4)
$targetPhp = getenv('TARGET_PHP') ?: '74';

$skips = [
    dirname(__DIR__) . '/vendor',
    dirname(__DIR__) . '/.phpunit.cache',
];

if (!getenv('TARGET_PHP')) {
    $skips[] = dirname(__DIR__) . '/dist';
}

return RectorConfig::configure()
    ->withPaths([
        dirname(__DIR__),
    ])
    ->withSkip($skips)
    ->withDowngradeSets(
        php74: $targetPhp === '74',
        php80: $targetPhp === '80'
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        earlyReturn: true,
        privatization: true
    );
