<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/**
 * Configurare Rector: Transpilare, downgrade si curatare cod.
 *
 * @category Configuration
 * @package  Rector
 * @version  1.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

// Citim versiunea tinta din variabila de mediu (implicit 74 pentru PHP 7.4)
$targetPhp = getenv('TARGET_PHP') ?: '74';

$skips = [
    __DIR__ . '/vendor',
    __DIR__ . '/.phpunit.cache',
];

if (!getenv('TARGET_PHP')) {
    $skips[] = __DIR__ . '/dist';
}

return RectorConfig::configure()
    ->withPaths([
        __DIR__,
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
