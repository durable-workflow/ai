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
}
