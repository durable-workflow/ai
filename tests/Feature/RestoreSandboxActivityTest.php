<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\RestoreSandboxActivity;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Tests\Fakes\StubSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use RuntimeException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;

final class RestoreSandboxActivityTest extends TestCase
{
    public function test_failed_initial_lease_renewal_destroys_the_restored_handle_without_masking_the_failure(): void
    {
        $provider = new StubSandboxProvider(
            renewLeaseFailure: new RuntimeException('initial lease renewal failed'),
            destroyFailure: new RuntimeException('restored handle cleanup failed'),
        );
        $this->app->make(SandboxManager::class)->extend(
            'stub',
            static fn ($container, array $config): StubSandboxProvider => $provider,
        );
        $activity = new RestoreSandboxActivity(new ActivityExecution, new WorkflowRun);

        try {
            $activity->handle('snapshot-1', 'stub');
            $this->fail('Expected initial lease renewal to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('initial lease renewal failed', $exception->getMessage());
        }

        $this->assertSame(['restored'], $provider->destroyed);
    }

    public function test_provider_handle_name_mismatch_is_cleaned_up_and_nonretryable(): void
    {
        $provider = new StubSandboxProvider(
            providerName: 'selected',
            destroyFailure: new RuntimeException('mismatched handle cleanup failed'),
            handleProvider: 'other',
        );
        $this->app->make(SandboxManager::class)->extend(
            'selected',
            static fn ($container, array $config): StubSandboxProvider => $provider,
        );
        $activity = new RestoreSandboxActivity(new ActivityExecution, new WorkflowRun);

        try {
            $activity->handle('snapshot-1', 'selected');
            $this->fail('Expected a mismatched provider handle to fail restoration.');
        } catch (SandboxConfigurationException $exception) {
            $this->assertStringContainsString(
                'Sandbox provider [selected] returned a handle for provider [other]',
                $exception->getMessage(),
            );
        }

        $this->assertSame(['restored'], $provider->destroyed);
    }
}
