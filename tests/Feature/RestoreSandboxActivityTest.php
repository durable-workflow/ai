<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\RestoreSandboxActivity;
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
}
