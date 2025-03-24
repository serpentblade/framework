<?php

namespace Illuminate\Tests\Benchmarks;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Orchestra\Testbench\TestCase;
use PhpBench\Attributes\AfterMethods;
use PhpBench\Attributes\BeforeMethods;

#[
    BeforeMethods('setUp'),
    AfterMethods('tearDown'),
]
abstract class OrchestraBenchmark
{
    private DatabaseTestCase $testCase;

    public function setUp()
    {
        $this->testCase = new DatabaseTestCase('benchmark');
        $this->testCase->setAfterRefreshingDatabaseCallback($this->afterRefreshingDatabase(...));

        // Call protected setup method
        $this->testCase->setUp();
    }

    public function tearDown()
    {
//        $this->testCase->tearDown();
    }

    public function afterRefreshingDatabase()
    {

    }
}

class DatabaseTestCase extends TestCase
{
    use DatabaseMigrations;

    protected $callbackAfterRefreshingDatabase;

    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function afterRefreshingDatabase()
    {
        $callback = $this->callbackAfterRefreshingDatabase;
        $callback();
    }

    /**
     * @param mixed $callbackAfterRefreshingDatabase
     */
    public function setAfterRefreshingDatabaseCallback($callbackAfterRefreshingDatabase): void
    {
        $this->callbackAfterRefreshingDatabase = $callbackAfterRefreshingDatabase;
    }
}


