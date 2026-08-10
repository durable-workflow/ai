<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Workflows;

use DurableWorkflow\AI\Activities\DestroySandboxActivity;
use DurableWorkflow\AI\Activities\DispatchToolCallActivity;
use DurableWorkflow\AI\Activities\ProvisionSandboxActivity;
use DurableWorkflow\AI\Activities\RestoreSandboxActivity;
use DurableWorkflow\AI\Activities\ResumeSandboxActivity;
use DurableWorkflow\AI\Activities\SnapshotSandboxActivity;
use DurableWorkflow\AI\Activities\SuspendSandboxActivity;
use DurableWorkflow\AI\Exceptions\SandboxGoneException;
use DurableWorkflow\AI\Exceptions\SandboxRecoveryException;
use DurableWorkflow\AI\SandboxOperationId;
use InvalidArgumentException;
use Throwable;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Workflow;

use function Workflow\V2\activity;

/**
 * Reusable durable sandbox lifecycle workflow.
 *
 * Every dispatch receives a stable operation id. Completed calls after the
 * latest snapshot are journaled in workflow history and replayed in order after
 * restore, before the interrupted call continues.
 */
final class SandboxAgentWorkflow extends Workflow
{
    private const MAX_RECOVERIES = 3;

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function handle(
        array $toolCalls,
        ?string $provider = null,
        int $snapshotEveryNCalls = 0,
        bool $suspendBetweenCalls = false,
        array $options = [],
    ): array {
        $preparedCalls = $this->prepareCalls($toolCalls);
        $handle = activity(ProvisionSandboxActivity::class, $provider, $options);
        $results = [];
        $completedSinceSnapshot = [];
        $latestSnapshot = null;
        $recoveryCount = 0;

        try {
            $index = 0;

            while ($index < count($preparedCalls)) {
                $call = $preparedCalls[$index];

                try {
                    $result = activity(DispatchToolCallActivity::class, $handle, $call);
                } catch (Throwable $throwable) {
                    if (! self::isSandboxGone($throwable)) {
                        throw $throwable;
                    }

                    [$handle, $recoveryCount] = $this->recoverAndReconstruct(
                        $latestSnapshot,
                        $provider,
                        $options,
                        $completedSinceSnapshot,
                        $recoveryCount,
                    );

                    continue;
                }

                $result['operation_id'] = $call['operation_id'];
                $results[] = $result;

                $completedSinceSnapshot[] = [
                    'call' => $call,
                    'outcome' => self::outcome($result),
                ];

                $index++;

                if ($snapshotEveryNCalls > 0 && $index % $snapshotEveryNCalls === 0) {
                    $latestSnapshot = activity(SnapshotSandboxActivity::class, $handle);
                    $completedSinceSnapshot = [];
                }

                if ($suspendBetweenCalls && $index < count($preparedCalls)) {
                    $handle = activity(SuspendSandboxActivity::class, $handle);
                    $handle = activity(ResumeSandboxActivity::class, $handle);
                }
            }

            return [
                'sandbox_id' => $handle['id'],
                'provider' => $handle['provider'],
                'tool_results' => $results,
                'latest_snapshot' => $latestSnapshot,
                'recovery_count' => $recoveryCount,
            ];
        } finally {
            try {
                activity(DestroySandboxActivity::class, $handle);
            } catch (Throwable) {
                // The provider lease remains the hard cleanup bound when all
                // idempotent destroy retries are exhausted.
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return list<array<string, mixed>>
     */
    private function prepareCalls(array $toolCalls): array
    {
        $prepared = [];
        $operationIds = [];

        foreach (array_values($toolCalls) as $index => $call) {
            $call['operation_id'] = is_string($call['operation_id'] ?? null)
                && $call['operation_id'] !== ''
                    ? $call['operation_id']
                    : SandboxOperationId::forWorkflowCall($this->runId(), $index);

            if (isset($operationIds[$call['operation_id']])) {
                throw new InvalidArgumentException(
                    "Sandbox operation_id [{$call['operation_id']}] must be unique within a workflow run.",
                );
            }

            $operationIds[$call['operation_id']] = true;
            $prepared[] = $call;
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<array{call: array<string, mixed>, outcome: array{exit_code: int, stdout: string, stderr: string}}>  $completedSinceSnapshot
     * @return array{array<string, mixed>, int}
     */
    private function recoverAndReconstruct(
        ?string $latestSnapshot,
        ?string $provider,
        array $options,
        array $completedSinceSnapshot,
        int $recoveryCount,
    ): array {
        while ($recoveryCount < self::MAX_RECOVERIES) {
            $recoveryCount++;
            $candidate = $latestSnapshot === null
                ? activity(ProvisionSandboxActivity::class, $provider, $options)
                : activity(RestoreSandboxActivity::class, $latestSnapshot, $provider);

            try {
                foreach ($completedSinceSnapshot as $completed) {
                    $call = $completed['call'];
                    $result = activity(DispatchToolCallActivity::class, $candidate, $call);

                    if (self::outcome($result) !== $completed['outcome']) {
                        throw new SandboxRecoveryException(
                            "Reconstruction operation {$call['operation_id']} produced a different outcome.",
                        );
                    }
                }

                return [$candidate, $recoveryCount];
            } catch (Throwable $throwable) {
                try {
                    activity(DestroySandboxActivity::class, $candidate);
                } catch (Throwable) {
                    // Preserve the reconstruction failure. The candidate's
                    // bounded provider lease remains the cleanup backstop.
                }

                if (! self::isSandboxGone($throwable)) {
                    throw $throwable;
                }
            }
        }

        throw new SandboxGoneException('Sandbox was lost too many times during recovery.');
    }

    private static function isSandboxGone(Throwable $throwable): bool
    {
        if ($throwable instanceof SandboxGoneException) {
            return true;
        }

        return $throwable instanceof RestoredWorkflowException
            && $throwable->originalExceptionClass() === SandboxGoneException::class;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private static function outcome(array $result): array
    {
        return [
            'exit_code' => (int) ($result['exit_code'] ?? 0),
            'stdout' => (string) ($result['stdout'] ?? ''),
            'stderr' => (string) ($result['stderr'] ?? ''),
        ];
    }
}
