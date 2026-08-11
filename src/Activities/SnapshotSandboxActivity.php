<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SnapshotReconcilingSandboxProvider;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use Workflow\V2\Activity;

final class SnapshotSandboxActivity extends Activity
{
    public int $tries = 3;

    /** @param array<string, mixed> $handle */
    public function handle(array $handle, string $operationId): string
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);
        $provider = $manager->driver($sandboxHandle->provider);
        $provider->capabilities()->require(SandboxCapability::Snapshot, $provider->name());
        $provider->capabilities()->require(SandboxCapability::SnapshotDeletion, $provider->name());
        $provider->renewLease($sandboxHandle, $manager->leaseTtlSeconds($provider));

        if ($provider instanceof SnapshotReconcilingSandboxProvider) {
            return $provider->snapshotForOperation($sandboxHandle, $operationId);
        }

        return $provider->snapshot($sandboxHandle);
    }
}
