<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit('This script can only be run from the command line.');
}

/**
 * Maintenance Script: Automates copying and transpiling the project for different PHP versions.
 * Execution: php bin/build-release.php [7.4|8.0]
 *
 * @category Maintenance
 *
 * @version  1.0
 *
 * @since    PHP 8.4
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */

// Command line arguments validation and applying the Fail Fast philosophy
$version = $argv[1] ?? null;
if (! in_array($version, ['7.4', '8.0'], true)) {
    echo "Usage: php bin/build-release.php [7.4|8.0]\n";
    exit(1);
}

$versionDirName = 'php'.str_replace('.', '', $version);
$targetDir = realpath(__DIR__.'/..').'/dist/'.$versionDirName;

echo "Building release for PHP {$version} in dist/{$versionDirName}...\n";

// 1. Creating the target directory and cleaning up the previous build
if (file_exists($targetDir)) {
    echo "Cleaning existing target directory...\n";
    exec('rm -rf '.escapeshellarg($targetDir));
}
mkdir($targetDir, 0755, true);

// 2. Defining files and directories to be copied
$itemsToCopy = [
    'src',
    'tests',
    'bin',
    'db',
    'lang',
    'views',
    'bootstrap.php',
    'composer.json',
    'index.php',
    'app-settings.php',
    'apps.php',
    'change-lang.php',
    'api',
    'login.php',
    'users.php',
    '.env.example',
];

foreach ($itemsToCopy as $item) {
    $source = realpath(__DIR__.'/..').'/'.$item;
    $destination = $targetDir.'/'.$item;

    if (! file_exists($source)) {
        continue;
    }

    if (is_dir($source)) {
        echo "Copying directory: {$item}...\n";
        exec('cp -r '.escapeshellarg($source).' '.escapeshellarg($destination));
    } else {
        echo "Copying file: {$item}...\n";
        copy($source, $destination);
    }
}

// 3. Deleting the build-release script from the final directory for cleanliness
$targetBuildScript = $targetDir.'/bin/build-release.php';
if (file_exists($targetBuildScript)) {
    unlink($targetBuildScript);
}

// 3.5 Enum pre-processing (converting before Rector to correctly detect the 'enum' keyword)
echo "Pre-processing enums in target directory...\n";
$di = new RecursiveDirectoryIterator($targetDir);
foreach (new RecursiveIteratorIterator($di) as $filename => $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($filename);

        // Detect if the file defines an Enum
        if (preg_match('/enum\s+([a-zA-Z0-9_]+)/', $content, $matches)) {
            $enumName = $matches[1];
            echo "Dynamically converting enum {$enumName} in ".basename($filename)."...\n";

            // Determine backing type (string or int)
            $backingType = 'string'; // Implicit
            if (preg_match('/enum\s+'.$enumName.'\s*:\s*(string|int)/', $content, $typeMatches)) {
                $backingType = $typeMatches[1];
            }

            // 1. Transform definitions of type "case KEY = 'value';" to "public const KEY = 'value';" in-place
            // This preserves the associated comments directly above the constants!
            $content = preg_replace('/case\s+([a-zA-Z0-9_]+\s*=\s*.+?;)/', 'public const $1', $content);

            // 2. Transform "enum Name: string/int" definition to "class Name"
            $content = preg_replace('/enum\s+'.$enumName.'\s*(:\s*(string|int))?/', 'class '.$enumName, $content);

            // 3. Inject mock native methods (cases, tryFrom, from) with comments in English and correct type-hints
            $mockMethods = <<<CODE

    /**
     * Returns all enum cases as objects with name and value properties.
     *
     * @return array
     */
    public static function cases(): array
    {
        \$reflection = new \ReflectionClass(self::class);
        \$constants = \$reflection->getConstants();
        \$cases = [];
        foreach (\$constants as \$name => \$value) {
            \$cases[] = new class(\$name, \$value) {
                /**
                 * Case name.
                 *
                 * @var string
                 */
                public string \$name;

                /**
                 * Case value.
                 *
                 * @var {$backingType}
                 */
                public {$backingType} \$value;

                /**
                 * Case constructor.
                 *
                 * @param string \$name
                 * @param {$backingType} \$value
                 */
                public function __construct(string \$name, {$backingType} \$value) {
                     \$this->name = \$name;
                     \$this->value = \$value;
                }
            };
        }
        return \$cases;
    }

    /**
     * Searches and returns the case corresponding to the passed value.
     *
     * @param {$backingType} \$value
     * @return object|null
     */
    public static function tryFrom(\$value): ?object
    {
        \$reflection = new \ReflectionClass(self::class);
        \$constants = \$reflection->getConstants();
        if (in_array(\$value, \$constants, true)) {
            return new class(\$value) {
                /**
                 * Case value.
                 *
                 * @var {$backingType}
                 */
                public {$backingType} \$value;

                /**
                 * Case constructor.
                 *
                 * @param {$backingType} \$value
                 */
                public function __construct({$backingType} \$value) {
                     \$this->value = \$value;
                }
            };
        }
        return null;
    }

    /**
     * Returns the case corresponding to the passed value or throws an error.
     *
     * @param {$backingType} \$value
     * @return object
     * @throws \ValueError
     */
    public static function from(\$value): object
    {
        \$result = self::tryFrom(\$value);
        if (\$result === null) {
            throw new \ValueError("Value '\$value' is not valid for enum " . self::class);
        }
        return \$result;
    }
CODE;

            // Place the methods at the end of the class, before the last closing brace
            $pos = strrpos($content, '}');
            if ($pos !== false) {
                $content = substr_replace($content, $mockMethods."\n", $pos, 0);
            }

            file_put_contents($filename, $content);
        }
    }
}

// 4. Executarea Rector pe directorul tinta
echo "Running Rector on target directory...\n";
$envVar = 'TARGET_PHP='.str_replace('.', '', $version);
$command = "{$envVar} vendor/bin/rector process --config bin/rector.php ".escapeshellarg($targetDir);
passthru($command, $exitCode);

if ($exitCode === 0) {
    // 5. Post-processing for PHP 7.4 / 8.0 compatibility (Enums and Composer)
    echo "Running post-processing for PHP {$version} compatibility...\n";

    // A. Generic replacement of any Constant->value call in all PHP files
    // Since in PHP 7.4 class constants are scalar values (strings/ints),
    // accessing the ->value property on them would throw a Notice/Error.
    $di = new RecursiveDirectoryIterator($targetDir);
    foreach (new RecursiveIteratorIterator($di) as $filename => $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($filename);

            // Replace any ClassName::CONSTANT->value construction with ClassName::CONSTANT
            $updatedContent = preg_replace('/([A-Za-z0-9_\\\\]+)::([A-Z0-9_]+)->value/', '$1::$2', $content);

            if ($content !== $updatedContent) {
                echo 'Fixed Enum-constant->value access in: '.basename($filename)."\n";
                file_put_contents($filename, $updatedContent);
            }
        }
    }

    // B. Update composer.json
    $composerJsonPath = $targetDir.'/composer.json';
    if (file_exists($composerJsonPath)) {
        echo "Updating composer.json dependencies for PHP {$version}...\n";
        $composerData = json_decode(file_get_contents($composerJsonPath), true);
        if (is_array($composerData)) {
            // Set the correct PHP version
            $composerData['require']['php'] = '^'.$version;
            // Downgrade symfony/var-dumper to ^5.4 (compatible with PHP 7.4 and 8.0)
            if (isset($composerData['require']['symfony/var-dumper'])) {
                $composerData['require']['symfony/var-dumper'] = '^5.4';
            }
            // Delete rector/rector and downgrade phpunit if the version is 7.4
            if (isset($composerData['require-dev'])) {
                unset($composerData['require-dev']['rector/rector']);
                if ($version === '7.4' && isset($composerData['require-dev']['phpunit/phpunit'])) {
                    $composerData['require-dev']['phpunit/phpunit'] = '^9.6';
                }
            }
            file_put_contents($composerJsonPath, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    echo "\n[SUCCESS] Release for PHP {$version} successfully generated in dist/{$versionDirName}!\n";
} else {
    echo "\n[ERROR] Rector failed with exit code {$exitCode}.\n";
    exit($exitCode);
}
