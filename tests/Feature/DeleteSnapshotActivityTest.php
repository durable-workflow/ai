<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\DeleteSnapshotActivity;
use DurableWorkflow\AI\Contracts\V1\SandboxProvider;
use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Tests\Fakes\Rc1SandboxProvider;
use DurableWorkflow\AI\Tests\Fakes\StubSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityRetryPolicy;

final class DeleteSnapshotActivityTest extends TestCase
{
    public function test_deletion_is_idempotent_and_retries_without_a_finite_cleanup_bound(): void
    {
        $provider = new StubSandboxProvider;
        $this->app->make(SandboxManager::class)->extend(
            'stub',
            static fn ($container, array $config): StubSandboxProvider => $provider,
        );
        $activity = new DeleteSnapshotActivity(new ActivityExecution, new WorkflowRun);

        $activity->handle('snapshot-1', 'stub');
        $activity->handle('snapshot-1', 'stub');

        $this->assertSame(['snapshot-1', 'snapshot-1'], $provider->deletedSnapshots);
        $this->assertNull(ActivityRetryPolicy::snapshot($activity)['max_attempts']);
    }

    public function test_an_rc1_shaped_provider_loads_and_fails_at_capability_negotiation(): void
    {
        $provider = new Rc1SandboxProvider;

        $this->assertInstanceOf(SandboxProvider::class, $provider);

        $this->app->make(SandboxManager::class)->extend(
            'rc1',
            static fn ($container, array $config): Rc1SandboxProvider => $provider,
        );

        $activity = new DeleteSnapshotActivity(new ActivityExecution, new WorkflowRun);

        $this->expectException(UnsupportedSandboxCapabilityException::class);
        $this->expectExceptionMessage('does not support [snapshot_deletion]; the operation was not attempted');

        $activity->handle('snapshot-1', 'rc1');
    }
}
