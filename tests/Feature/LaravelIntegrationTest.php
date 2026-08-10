<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Providers\LocalSubprocessSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;

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

    #[DataProvider('localDriverSelections')]
    public function test_local_driver_fails_closed_outside_local_and_testing(?string $driver): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(SandboxConfigurationException::class);
        $this->expectExceptionMessage(
            'Sandbox provider [local] is development/test-only and cannot be used in the [production] environment.',
        );

        app(SandboxManager::class)->driver($driver);
    }

    /** @return array<string, array{string|null}> */
    public static function localDriverSelections(): array
    {
        return [
            'default local driver' => [null],
            'explicit local driver' => ['local'],
        ];
    }
}
