<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

/**
 * Script de Mentenanta: Automatizarea copierii si transpilarii proiectului pentru diferite versiuni de PHP.
 * Executie: php bin/build-release.php [7.4|8.0]
 *
 * @category Maintenance
 * @package  Bin
 * @version  1.0
 * @since    PHP 8.4
 * @author   Voica Liviu
 * @license  Proprietar
 */

// Validarea argumentelor din linia de comanda si aplicarea filozofiei Fail Fast
$version = $argv[1] ?? null;
if (!in_array($version, ['7.4', '8.0'], true)) {
    echo "Usage: php bin/build-release.php [7.4|8.0]\n";
    exit(1);
}

$versionDirName = 'php' . str_replace('.', '', $version);
$targetDir = realpath(__DIR__ . '/..') . '/dist/' . $versionDirName;

echo "Building release for PHP {$version} in dist/{$versionDirName}...\n";

// 1. Crearea directorului tinta si curatarea build-ului anterior
if (file_exists($targetDir)) {
    echo "Cleaning existing target directory...\n";
    exec('rm -rf ' . escapeshellarg($targetDir));
}
mkdir($targetDir, 0755, true);

// 2. Definirea fisierelor si directoarelor ce trebuie copiate
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
    '.env.example'
];

foreach ($itemsToCopy as $item) {
    $source = realpath(__DIR__ . '/..') . '/' . $item;
    $destination = $targetDir . '/' . $item;

    if (!file_exists($source)) {
        continue;
    }

    if (is_dir($source)) {
        echo "Copying directory: {$item}...\n";
        exec('cp -r ' . escapeshellarg($source) . ' ' . escapeshellarg($destination));
    } else {
        echo "Copying file: {$item}...\n";
        copy($source, $destination);
    }
}

// 3. Stergerea scriptului de build-release din directorul final pentru curatenie
$targetBuildScript = $targetDir . '/bin/build-release.php';
if (file_exists($targetBuildScript)) {
    unlink($targetBuildScript);
}

// 3.5 Pre-procesare Enum-uri (conversie inainte de Rector pentru a detecta corect cuvantul cheie 'enum')
echo "Pre-processing enums in target directory...\n";
$di = new RecursiveDirectoryIterator($targetDir);
foreach (new RecursiveIteratorIterator($di) as $filename => $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($filename);

        // Detectam daca fisierul defineste un Enum
        if (preg_match('/enum\s+([a-zA-Z0-9_]+)/', $content, $matches)) {
            $enumName = $matches[1];
            echo "Dynamically converting enum {$enumName} in " . basename($filename) . "...\n";

            // Determinam tipul de backing (string sau int)
            $backingType = 'string'; // Implicit
            if (preg_match('/enum\s+' . $enumName . '\s*:\s*(string|int)/', $content, $typeMatches)) {
                $backingType = $typeMatches[1];
            }

            // 1. Transforma definitiile de tip "case KEY = 'value';" in "public const KEY = 'value';" in-place
            // Acest lucru pastreaza comentariile asociate exact deasupra constantelor!
            $content = preg_replace('/case\s+([a-zA-Z0-9_]+\s*=\s*.+?;)/', 'public const $1', $content);

            // 2. Transforma definitia "enum Name: string/int" in "class Name"
            $content = preg_replace('/enum\s+' . $enumName . '\s*(:\s*(string|int))?/', 'class ' . $enumName, $content);

            // 3. Injecteaza metodele native de mock (cases, tryFrom, from) cu comentarii in romana si fara diacritice, plus type-hints corecte
            $mockMethods = <<<CODE

    /**
     * Returneaza toate cazurile enum-ului sub forma de obiecte cu proprietatile name si value.
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
                 * Numele cazului.
                 *
                 * @var string
                 */
                public string \$name;

                /**
                 * Valoarea cazului.
                 *
                 * @var {$backingType}
                 */
                public {$backingType} \$value;

                /**
                 * Constructor caz.
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
     * Cauta si returneaza cazul corespunzator valorii transmise.
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
                 * Valoarea cazului.
                 *
                 * @var {$backingType}
                 */
                public {$backingType} \$value;

                /**
                 * Constructor caz.
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
     * Returneaza cazul corespunzator valorii transmise sau arunca o eroare.
     *
     * @param {$backingType} \$value
     * @return object
     * @throws \ValueError
     */
    public static function from(\$value): object
    {
        \$result = self::tryFrom(\$value);
        if (\$result === null) {
            throw new \ValueError("Valoarea '\$value' nu este valida pentru enum-ul " . self::class);
        }
        return \$result;
    }
CODE;
            
            // Plasam metodele la sfarsitul clasei, inainte de ultima acolada inchisa
            $pos = strrpos($content, '}');
            if ($pos !== false) {
                $content = substr_replace($content, $mockMethods . "\n", $pos, 0);
            }

            file_put_contents($filename, $content);
        }
    }
}

// 4. Executarea Rector pe directorul tinta
echo "Running Rector on target directory...\n";
$envVar = 'TARGET_PHP=' . str_replace('.', '', $version);
$command = "{$envVar} vendor/bin/rector process --config bin/rector.php " . escapeshellarg($targetDir);
passthru($command, $exitCode);

if ($exitCode === 0) {
    // 5. Post-procesare pentru compatibilitate PHP 7.4 / 8.0 (Enums si Composer)
    echo "Running post-processing for PHP {$version} compatibility...\n";

    // A. Inlocuire generica a oricarui apel Constant->value in toate fisierele PHP
    // Deoarece in PHP 7.4 constantele de clasa sunt valori scalare (strings/ints),
    // accesarea proprietatii ->value pe ele ar arunca Notice/Error.
    $di = new RecursiveDirectoryIterator($targetDir);
    foreach (new RecursiveIteratorIterator($di) as $filename => $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($filename);
            
            // Inlocuieste orice constructie ClassName::CONSTANT->value cu ClassName::CONSTANT
            $updatedContent = preg_replace('/([A-Za-z0-9_\\\\]+)::([A-Z0-9_]+)->value/', '$1::$2', $content);
            
            if ($content !== $updatedContent) {
                echo "Fixed Enum-constant->value access in: " . basename($filename) . "\n";
                file_put_contents($filename, $updatedContent);
            }
        }
    }

    // B. Actualizare composer.json
    $composerJsonPath = $targetDir . '/composer.json';
    if (file_exists($composerJsonPath)) {
        echo "Updating composer.json dependencies for PHP {$version}...\n";
        $composerData = json_decode(file_get_contents($composerJsonPath), true);
        if (is_array($composerData)) {
            // Seteaza versiunea corecta de PHP
            $composerData['require']['php'] = '^' . $version;
            // Downgradeaza symfony/var-dumper la ^5.4 (compatibil cu PHP 7.4 si 8.0)
            if (isset($composerData['require']['symfony/var-dumper'])) {
                $composerData['require']['symfony/var-dumper'] = '^5.4';
            }
            // Sterge rector/rector si downgradeaza phpunit daca este versiunea 7.4
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
