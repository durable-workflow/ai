<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Workflows;

use DurableWorkflow\AI\Activities\DeleteSnapshotActivity;
use DurableWorkflow\AI\Activities\DestroySandboxActivity;
use DurableWorkflow\AI\Activities\DispatchToolCallActivity;
use DurableWorkflow\AI\Activities\InjectSandboxLossActivity;
use DurableWorkflow\AI\Activities\ProvisionSandboxActivity;
use DurableWorkflow\AI\Activities\ResolveSandboxProviderActivity;
use DurableWorkflow\AI\Activities\RestoreSandboxActivity;
use DurableWorkflow\AI\Activities\ResumeSandboxActivity;
use DurableWorkflow\AI\Activities\SnapshotSandboxActivity;
use DurableWorkflow\AI\Activities\SuspendSandboxActivity;
use DurableWorkflow\AI\Exceptions\SandboxGoneException;
use DurableWorkflow\AI\Exceptions\SandboxRecoveryException;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxOperationId;
use FiberError;
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
        bool $retainLatestSnapshot = false,
        ?int $injectLossAfterNCalls = null,
    ): array {
        $preparedCalls = $this->prepareCalls($toolCalls);
        $this->assertLossInjectionBoundary($injectLossAfterNCalls, count($preparedCalls));
        $provider = $this->resolveLossInjectionProvider($injectLossAfterNCalls, $provider);
        $handle = activity(ProvisionSandboxActivity::class, $provider, $options);
        $providerName = SandboxHandle::fromArray($handle)->provider;
        $results = [];
        $completedSinceSnapshot = [];
        $latestSnapshot = null;
        $latestSnapshotTransferred = false;
        $recoveryCount = 0;
        $lossInjected = false;

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
                        $providerName,
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
                    $previousSnapshot = $latestSnapshot;
                    $snapshotOperationId = SandboxOperationId::forWorkflowSnapshot(
                        $this->runId(),
                        $index,
                    );

                    while (true) {
                        try {
                            $replacementSnapshot = activity(
                                SnapshotSandboxActivity::class,
                                $handle,
                                $snapshotOperationId,
                            );

                            break;
                        } catch (Throwable $throwable) {
                            if (! self::isSandboxGone($throwable)) {
                                throw $throwable;
                            }

                            [$handle, $recoveryCount] = $this->recoverAndReconstruct(
                                $latestSnapshot,
                                $providerName,
                                $options,
                                $completedSinceSnapshot,
                                $recoveryCount,
                            );
                        }
                    }

                    // The replacement activity result is in durable workflow
                    // history before the only previously recoverable snapshot
                    // becomes eligible for deletion.
                    $latestSnapshot = $replacementSnapshot;
                    $completedSinceSnapshot = [];

                    if ($previousSnapshot !== null) {
                        activity(DeleteSnapshotActivity::class, $previousSnapshot, $providerName);
                    }
                }

                if (! $lossInjected && $injectLossAfterNCalls === $index) {
                    activity(
                        InjectSandboxLossActivity::class,
                        $handle,
                        SandboxOperationId::forWorkflowLossInjection($this->runId(), $index),
                    );
                    $lossInjected = true;

                    [$handle, $recoveryCount] = $this->recoverAndReconstruct(
                        $latestSnapshot,
                        $providerName,
                        $options,
                        $completedSinceSnapshot,
                        $recoveryCount,
                    );
                }

                if ($suspendBetweenCalls && $index < count($preparedCalls)) {
                    [$handle, $recoveryCount] = $this->suspendAndResume(
                        $handle,
                        $latestSnapshot,
                        $providerName,
                        $options,
                        $completedSinceSnapshot,
                        $recoveryCount,
                    );
                }
            }

            $output = [
                'sandbox_id' => $handle['id'],
                'provider' => $handle['provider'],
                'tool_results' => $results,
                'latest_snapshot' => $retainLatestSnapshot ? $latestSnapshot : null,
                'recovery_count' => $recoveryCount,
            ];

            // Retention is only an ownership transfer once this successful
            // result gives the caller the checkpoint ID.
            $latestSnapshotTransferred = $retainLatestSnapshot && $latestSnapshot !== null;

            return $output;
        } finally {
            try {
                if (! $latestSnapshotTransferred && $latestSnapshot !== null) {
                    activity(DeleteSnapshotActivity::class, $latestSnapshot, $providerName);
                }
            } catch (FiberError) {
                // The runtime force-closes replay fibers whenever an earlier
                // activity suspends. Cleanup is scheduled only when execution
                // actually reaches terminal finalization.
            } finally {
                try {
                    activity(DestroySandboxActivity::class, $handle);
                } catch (Throwable) {
                    // The provider lease remains the hard cleanup bound when
                    // all idempotent destroy retries are exhausted.
                }
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

    private function assertLossInjectionBoundary(?int $afterCalls, int $callCount): void
    {
        if ($afterCalls === null) {
            return;
        }

        if ($afterCalls < 1 || $afterCalls > $callCount) {
            throw new InvalidArgumentException(
                "Sandbox loss injection must follow one of the {$callCount} configured tool calls.",
            );
        }
    }

    private function resolveLossInjectionProvider(?int $afterCalls, ?string $provider): ?string
    {
        if ($afterCalls === null) {
            return $provider;
        }

        // An explicit non-local selection is already fully resolved for this
        // development-only boundary, so reject it without scheduling work.
        if ($provider !== null) {
            $this->assertLossInjectionProvider($provider);
        }

        $resolvedProvider = activity(ResolveSandboxProviderActivity::class, $provider);
        $this->assertLossInjectionProvider($resolvedProvider);

        return $resolvedProvider;
    }

    private function assertLossInjectionProvider(string $provider): void
    {
        if ($provider === 'local') {
            return;
        }

        throw new InvalidArgumentException(
            "Sandbox loss injection is development/test-only and requires the [local] provider; [{$provider}] was selected.",
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<array{call: array<string, mixed>, outcome: array{exit_code: int, stdout: string, stderr: string}}>  $completedSinceSnapshot
     * @return array{array<string, mixed>, int}
     */
    private function recoverAndReconstruct(
        ?string $latestSnapshot,
        string $provider,
        array $options,
        array $completedSinceSnapshot,
        int $recoveryCount,
    ): array {
        while ($recoveryCount < self::MAX_RECOVERIES) {
            $recoveryCount++;
            $candidate = null;

            try {
                $candidate = $latestSnapshot === null
                    ? activity(ProvisionSandboxActivity::class, $provider, $options)
                    : activity(RestoreSandboxActivity::class, $latestSnapshot, $provider);

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
                if ($candidate !== null) {
                    try {
                        activity(DestroySandboxActivity::class, $candidate);
                    } catch (Throwable) {
                        // Preserve the reconstruction failure. The candidate's
                        // bounded provider lease remains the cleanup backstop.
                    }
                }

                if (! self::isSandboxGone($throwable)) {
                    throw $throwable;
                }
            }
        }

        throw new SandboxGoneException('Sandbox was lost too many times during recovery.');
    }

    /**
     * @param  array<string, mixed>  $handle
     * @param  array<string, mixed>  $options
     * @param  list<array{call: array<string, mixed>, outcome: array{exit_code: int, stdout: string, stderr: string}}>  $completedSinceSnapshot
     * @return array{array<string, mixed>, int}
     */
    private function suspendAndResume(
        array $handle,
        ?string $latestSnapshot,
        string $provider,
        array $options,
        array $completedSinceSnapshot,
        int $recoveryCount,
    ): array {
        while (true) {
            try {
                $handle = activity(SuspendSandboxActivity::class, $handle);
                $handle = activity(ResumeSandboxActivity::class, $handle);

                return [$handle, $recoveryCount];
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
            }
        }
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
