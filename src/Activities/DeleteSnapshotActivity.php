<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Laravel\SandboxManager;
use Workflow\V2\Activity;

final class DeleteSnapshotActivity extends Activity
{
    /**
     * Snapshots have no provider lease as a cleanup backstop. Keep retrying an
     * idempotent deletion until the provider confirms the artifact is gone.
     */
    public int $tries = PHP_INT_MAX;

    public function handle(string $snapshotId, string $providerName): bool
    {
        $provider = app(SandboxManager::class)->driver($providerName);
        $provider->capabilities()->require(SandboxCapability::SnapshotDeletion, $provider->name());
        $provider->deleteSnapshot($snapshotId);

        return true;
    }
}
