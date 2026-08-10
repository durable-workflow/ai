<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Contracts\V1;

enum SandboxCapability: string
{
    case Snapshot = 'snapshot';
    case Restore = 'restore';
    case Suspend = 'suspend';
    case Resume = 'resume';
    case OperationDeduplication = 'operation_deduplication';
    case LeaseReconciliation = 'lease_reconciliation';
}
