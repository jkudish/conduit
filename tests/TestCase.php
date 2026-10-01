<?php

declare(strict_types=1);

namespace Conduit\Tests;

use Conduit\ConduitServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, ConduitServiceProvider::class];
    }
}
