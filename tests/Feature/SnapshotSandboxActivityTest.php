<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\SnapshotSandboxActivity;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\Tests\Fakes\StubSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;

final class SnapshotSandboxActivityTest extends TestCase
{
    public function test_snapshot_operation_identity_reaches_a_reconciling_provider(): void
    {
        $provider = new StubSandboxProvider;
        $this->app->make(SandboxManager::class)->extend(
            'stub',
            static fn ($container, array $config): StubSandboxProvider => $provider,
        );
        $activity = new SnapshotSandboxActivity(new ActivityExecution, new WorkflowRun);

        $snapshot = $activity->handle(
            (new SandboxHandle('sandbox-1', 'stub'))->toArray(),
            'snapshot-operation-1',
        );

        $this->assertSame('snapshot', $snapshot);
        $this->assertSame(['snapshot-operation-1'], $provider->snapshotOperations);
    }
}
