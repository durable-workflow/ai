<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests;

use DurableWorkflow\AI\Laravel\SandboxServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Workflow\Providers\WorkflowServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            WorkflowServiceProvider::class,
            SandboxServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('workflows.v2.enabled', true);
    }
}
