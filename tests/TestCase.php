<?php

declare(strict_types=1);

namespace Tests;

use App\Utilities\MySQLWrapper;
use Phinx\Console\PhinxApplication;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

abstract class TestCase extends BaseTestCase
{
    protected static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Run database migrations once per test execution to ensure a clean schema
        if (! self::$migrated) {
            $this->runMigrations();
            self::$migrated = true;
        }
    }

    /**
     * Programmatically run Phinx migrations for the testing environment.
     */
    protected function runMigrations(): void
    {
        $app = new PhinxApplication;
        $app->setAutoExit(false);

        // Run rollback if tables already exist to start fresh
        $app->run(new StringInput('rollback -c db/phinx.php -e production -t 0'), new NullOutput);
        $app->run(new StringInput('migrate -c db/phinx.php -e production'), new NullOutput);
    }

    /**
     * Programmatically run Phinx seeders for the testing environment.
     */
    protected function runSeeds(): void
    {
        $app = new PhinxApplication;
        $app->setAutoExit(false);
        $app->run(new StringInput('seed:run -c db/phinx.php -e production'), new NullOutput);
    }

    /**
     * Helper to retrieve database connection instance.
     */
    protected function db(): MySQLWrapper
    {
        return MySQLWrapper::getInstance();
    }
}
