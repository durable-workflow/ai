<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Contracts\V1;

use DurableWorkflow\AI\SandboxHandle;

/**
 * Version 1 extension for providers that reconcile snapshot activity retries.
 *
 * The same non-empty operation ID is delivered across activity retries and
 * workflow replay. Implementations must return the persistent snapshot already
 * created for that operation instead of creating another artifact.
 */
interface SnapshotReconcilingSandboxProvider extends SnapshotDeletingSandboxProvider
{
    public const CONTRACT_NAME = 'sandbox-provider.snapshot-reconciliation';

    public const CONTRACT_VERSION = '1.0';

    public function snapshotForOperation(SandboxHandle $handle, string $operationId): string;
}
