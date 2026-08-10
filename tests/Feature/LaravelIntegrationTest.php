<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Providers\LocalSubprocessSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

final class LaravelIntegrationTest extends TestCase
{
    public function test_service_provider_registers_manager_and_publishes_configuration(): void
    {
        $this->assertInstanceOf(SandboxManager::class, app(SandboxManager::class));
        $this->assertInstanceOf(
            LocalSubprocessSandboxProvider::class,
            app(SandboxManager::class)->driver('local'),
        );

        Artisan::call('vendor:publish', [
            '--tag' => 'durable-workflow-ai-config',
            '--force' => true,
        ]);

        $this->assertFileExists(config_path('durable-workflow-ai.php'));
    }
}
