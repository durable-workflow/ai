<?php

declare(strict_types=1);

namespace DurableWorkflow\AI;

use InvalidArgumentException;

final class SandboxOperationId
{
    public static function forWorkflowCall(string $workflowRunId, int $callIndex): string
    {
        if ($workflowRunId === '' || $callIndex < 0) {
            throw new InvalidArgumentException('Operation ids require a workflow run id and non-negative call index.');
        }

        return 'dwaiv1_'.hash('sha256', "{$workflowRunId}\0{$callIndex}");
    }

    public static function forWorkflowSnapshot(string $workflowRunId, int $completedCallCount): string
    {
        if ($workflowRunId === '' || $completedCallCount < 0) {
            throw new InvalidArgumentException('Snapshot operation ids require a workflow run id and non-negative completed call count.');
        }

        return 'dwaiv1_'.hash('sha256', "snapshot\0{$workflowRunId}\0{$completedCallCount}");
    }

    public static function forWorkflowLossInjection(string $workflowRunId, int $completedCallCount): string
    {
        if ($workflowRunId === '' || $completedCallCount < 1) {
            throw new InvalidArgumentException('Loss injection operation ids require a workflow run id and positive completed call count.');
        }

        return 'dwaiv1_'.hash('sha256', "loss-injection\0{$workflowRunId}\0{$completedCallCount}");
    }
}
