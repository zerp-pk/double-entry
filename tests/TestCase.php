<?php

namespace Zerp\DoubleEntry\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zerp\DoubleEntry\Providers\DoubleEntryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DoubleEntryServiceProvider::class];
    }
}
