<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Contracts\V1;

/**
 * Version 1 extension for providers that can delete persistent snapshots.
 *
 * Deletion must be idempotent: an already deleted or unknown snapshot succeeds.
 */
interface SnapshotDeletingSandboxProvider extends SandboxProvider
{
    public const CONTRACT_NAME = 'sandbox-provider.snapshot-deletion';

    public const CONTRACT_VERSION = '1.0';

    public function deleteSnapshot(string $snapshotId): void;
}
