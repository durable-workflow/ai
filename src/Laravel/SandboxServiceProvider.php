<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Laravel;

use Illuminate\Support\ServiceProvider;

final class SandboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 2).'/config/durable-workflow-ai.php',
            'durable-workflow-ai',
        );

        $this->app->singleton(
            SandboxConfig::class,
            fn ($app): SandboxConfig => new SandboxConfig($app->make('config')),
        );

        $this->app->singleton(
            SandboxManager::class,
            fn ($app): SandboxManager => new SandboxManager(
                $app,
                $app->make(SandboxConfig::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__, 2).'/config/durable-workflow-ai.php' => config_path('durable-workflow-ai.php'),
        ], 'durable-workflow-ai-config');
    }
}
