<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\DeleteSnapshotActivity;
use DurableWorkflow\AI\Activities\DestroySandboxActivity;
use DurableWorkflow\AI\Activities\DispatchToolCallActivity;
use DurableWorkflow\AI\Activities\ProvisionSandboxActivity;
use DurableWorkflow\AI\Activities\RestoreSandboxActivity;
use DurableWorkflow\AI\Activities\ResumeSandboxActivity;
use DurableWorkflow\AI\Activities\SnapshotSandboxActivity;
use DurableWorkflow\AI\Activities\SuspendSandboxActivity;
use DurableWorkflow\AI\Exceptions\SandboxGoneException;
use DurableWorkflow\AI\Tests\TestCase;
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\WorkflowStub;

final class SandboxAgentWorkflowRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_e2b_file_result_never_provisions_or_restores_a_replacement(): void
    {
        WorkflowStub::fake();
        $provisions = 0;
        $restores = 0;
        $snapshots = 0;
        $readAttempts = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisions): array {
            $provisions++;

            return $this->handle('original', 'e2b');
        });
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$readAttempts): array {
            if ($call['type'] === 'read_file') {
                $readAttempts++;

                return [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => 'File not found: /home/user/missing.txt',
                ];
            }

            return ['exit_code' => 0, 'stdout' => 'checkpoint', 'stderr' => ''];
        });
        WorkflowStub::mock(SnapshotSandboxActivity::class, function () use (&$snapshots): string {
            $snapshots++;

            return 'snapshot-'.$snapshots;
        });
        WorkflowStub::mock(RestoreSandboxActivity::class, function () use (&$restores): array {
            $restores++;

            return $this->handle('unexpected-replacement', 'e2b');
        });
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            [
                'type' => 'write_file',
                'args' => ['path' => '/home/user/state.txt', 'contents' => 'checkpoint'],
            ],
            [
                'type' => 'read_file',
                'args' => ['path' => '/home/user/missing.txt'],
            ],
        ], 'e2b', 1);
        $output = $workflow->refresh()->output();

        $this->assertSame(1, $provisions);
        $this->assertSame(0, $restores);
        $this->assertSame(1, $readAttempts);
        $this->assertSame(0, $output['recovery_count']);
        $this->assertCount(2, $output['tool_results']);
        $this->assertSame(1, $output['tool_results'][1]['exit_code']);
        $this->assertSame('File not found: /home/user/missing.txt', $output['tool_results'][1]['stderr']);
    }

    public function test_loss_immediately_before_execution_retries_the_same_stable_operation_on_a_replacement(): void
    {
        WorkflowStub::fake();
        $provisions = 0;
        $operationIds = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisions): array {
            $provisions++;

            return $this->handle($provisions === 1 ? 'original' : 'replacement');
        });

        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$operationIds): array {
            $operationIds[] = $call['operation_id'];

            if ($handle['id'] === 'original') {
                throw new SandboxGoneException('lost before execution');
            }

            return ['exit_code' => 0, 'stdout' => 'ran once', 'stderr' => ''];
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([['type' => 'shell', 'args' => ['command' => 'true']]]);
        $output = $workflow->refresh()->output();

        $this->assertSame(1, $output['recovery_count']);
        $this->assertSame(2, $provisions);
        $this->assertCount(2, $operationIds);
        $this->assertSame($operationIds[0], $operationIds[1]);
    }

    public function test_loss_after_execution_before_acknowledgement_repeats_the_effect_on_the_replacement(): void
    {
        WorkflowStub::fake();
        $provisions = 0;
        $effects = [];
        $receipts = [];
        $deliveries = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisions): array {
            $provisions++;

            return $this->handle($provisions === 1 ? 'original' : 'replacement');
        });

        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$effects, &$receipts, &$deliveries): array {
            $operationId = $call['operation_id'];
            $deliveries[] = $operationId;
            $sandboxId = $handle['id'];

            if (! isset($receipts[$sandboxId][$operationId])) {
                $effects[$sandboxId] = ($effects[$sandboxId] ?? 0) + 1;
                $receipts[$sandboxId][$operationId] = ['exit_code' => 0, 'stdout' => 'effect', 'stderr' => ''];
            }

            if ($handle['id'] === 'original') {
                throw new SandboxGoneException('effect committed but acknowledgement lost');
            }

            return $receipts[$sandboxId][$operationId];
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([['type' => 'write_file', 'args' => ['path' => 'state', 'contents' => 'one']]]);
        $output = $workflow->refresh()->output();

        $this->assertSame(
            ['original' => 1, 'replacement' => 1],
            $effects,
            'receipts deduplicate within a sandbox but do not suppress reconstruction on its replacement',
        );
        $this->assertSame([$deliveries[0], $deliveries[0]], $deliveries);
        $this->assertSame($deliveries[0], $output['tool_results'][0]['operation_id']);
    }

    public function test_recovery_keeps_the_initial_provider_after_the_default_driver_changes(): void
    {
        WorkflowStub::fake();
        $defaultProvider = 'provider-before-config-change';
        $provisionProviders = [];
        $restoreProviders = [];
        $lostBeforeSnapshot = false;
        $lostAfterSnapshot = false;

        WorkflowStub::mock(ProvisionSandboxActivity::class, function ($context, ?string $provider) use (&$defaultProvider, &$provisionProviders): array {
            $selectedProvider = $provider ?? $defaultProvider;
            $provisionProviders[] = $selectedProvider;

            if (count($provisionProviders) === 1) {
                $defaultProvider = 'provider-after-config-change';

                return $this->handle('original', $selectedProvider);
            }

            return $this->handle('replacement', $selectedProvider);
        });
        WorkflowStub::mock(SnapshotSandboxActivity::class, 'snapshot-1');
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(RestoreSandboxActivity::class, function ($context, string $snapshotId, ?string $provider) use (&$defaultProvider, &$restoreProviders): array {
            $selectedProvider = $provider ?? $defaultProvider;
            $restoreProviders[] = [$snapshotId, $selectedProvider];

            return $this->handle('restored', $selectedProvider);
        });
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$lostBeforeSnapshot, &$lostAfterSnapshot): array {
            $command = $call['args']['command'];

            if ($handle['id'] === 'original' && ! $lostBeforeSnapshot) {
                $lostBeforeSnapshot = true;
                throw new SandboxGoneException('lost before the first snapshot');
            }

            if ($handle['id'] === 'replacement' && $command === 'after-snapshot' && ! $lostAfterSnapshot) {
                $lostAfterSnapshot = true;
                throw new SandboxGoneException('lost after the first snapshot');
            }

            return ['exit_code' => 0, 'stdout' => $command, 'stderr' => ''];
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(array_map(
            static fn (string $command): array => [
                'type' => 'shell',
                'args' => ['command' => $command],
            ],
            ['before-snapshot', 'checkpoint', 'after-snapshot'],
        ), null, 2);
        $output = $workflow->refresh()->output();

        $this->assertSame(
            ['provider-before-config-change', 'provider-before-config-change'],
            $provisionProviders,
        );
        $this->assertSame(
            [['snapshot-1', 'provider-before-config-change']],
            $restoreProviders,
        );
        $this->assertSame('provider-before-config-change', $output['provider']);
        $this->assertSame(2, $output['recovery_count']);
    }

    public function test_duplicate_caller_operation_ids_fail_before_provisioning(): void
    {
        WorkflowStub::fake();
        $provisions = 0;
        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisions): array {
            $provisions++;

            return $this->handle('unused');
        });

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            [
                'operation_id' => 'shared-operation',
                'type' => 'write_file',
                'args' => ['path' => 'first', 'contents' => 'one'],
            ],
            [
                'operation_id' => 'shared-operation',
                'type' => 'write_file',
                'args' => ['path' => 'second', 'contents' => 'two'],
            ],
        ]);

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertSame(0, $provisions);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'workflow_run')
            ->firstOrFail();
        $failureMessage = $failure->getAttribute('message');
        $this->assertIsString($failureMessage);
        $this->assertStringContainsString('operation_id [shared-operation] must be unique', $failureMessage);
    }

    public function test_restore_replays_every_completed_post_snapshot_call_before_continuing(): void
    {
        WorkflowStub::fake();
        $originalCalls = [];
        $restoredCalls = [];
        $lost = false;
        $snapshots = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(SnapshotSandboxActivity::class, function () use (&$snapshots): string {
            $snapshots++;

            return 'snapshot-'.$snapshots;
        });
        WorkflowStub::mock(RestoreSandboxActivity::class, $this->handle('restored'));
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$originalCalls, &$restoredCalls, &$lost): array {
            $command = $call['args']['command'];

            if ($handle['id'] === 'original') {
                $originalCalls[$command] = $call['operation_id'];

                if ($command === 'f' && ! $lost) {
                    $lost = true;
                    throw new SandboxGoneException('lost after two post-snapshot completions');
                }
            } else {
                $restoredCalls[$command] = $call['operation_id'];
            }

            return [
                'exit_code' => $command === 'e' ? 17 : 0,
                'stdout' => $command,
                'stderr' => $command === 'e' ? 'mutated before failure' : '',
            ];
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(array_map(
            static fn (string $command): array => [
                'type' => 'shell',
                'args' => ['command' => $command],
            ],
            ['a', 'b', 'c', 'd', 'e', 'f'],
        ), null, 3);
        $output = $workflow->refresh()->output();

        $this->assertSame(['d', 'e', 'f'], array_keys($restoredCalls));
        $this->assertSame($originalCalls['d'], $restoredCalls['d']);
        $this->assertSame($originalCalls['e'], $restoredCalls['e']);
        $this->assertSame($originalCalls['f'], $restoredCalls['f']);
        $this->assertCount(6, $output['tool_results']);
        $this->assertSame(17, $output['tool_results'][4]['exit_code']);
        $this->assertSame(1, $output['recovery_count']);
        $this->assertSame(2, $snapshots);
    }

    public function test_recovery_fails_when_a_replayed_completion_has_a_different_outcome(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(SnapshotSandboxActivity::class, 'snapshot-1');
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(RestoreSandboxActivity::class, $this->handle('restored'));
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call): array {
            $command = $call['args']['command'];

            if ($handle['id'] === 'original' && $command === 'trigger-loss') {
                throw new SandboxGoneException('lost after acknowledged mutation');
            }

            return [
                'exit_code' => 0,
                'stdout' => $handle['id'] === 'restored' && $command === 'mutation'
                    ? 'different replay outcome'
                    : $command,
                'stderr' => '',
            ];
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(array_map(
            static fn (string $command): array => [
                'type' => 'shell',
                'args' => ['command' => $command],
            ],
            ['checkpoint-a', 'checkpoint-b', 'mutation', 'trigger-loss'],
        ), null, 2);

        $this->assertTrue($workflow->refresh()->failed());
    }

    public function test_recovery_destroys_a_rejected_replacement_without_masking_the_replay_error(): void
    {
        WorkflowStub::fake();
        $destroyed = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(SnapshotSandboxActivity::class, 'snapshot-1');
        WorkflowStub::mock(DeleteSnapshotActivity::class, true);
        WorkflowStub::mock(RestoreSandboxActivity::class, $this->handle('rejected'));
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call): array {
            $command = $call['args']['command'];

            if ($handle['id'] === 'original' && $command === 'trigger-loss') {
                throw new SandboxGoneException('lost after acknowledged mutation');
            }

            return [
                'exit_code' => 0,
                'stdout' => $handle['id'] === 'rejected' && $command === 'mutation'
                    ? 'different replay outcome'
                    : $command,
                'stderr' => '',
            ];
        });
        WorkflowStub::mock(DestroySandboxActivity::class, function ($context, array $handle) use (&$destroyed): bool {
            $destroyed[] = $handle['id'];

            if ($handle['id'] === 'rejected') {
                throw new NonRetryableException('replacement cleanup also failed');
            }

            return true;
        });

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(array_map(
            static fn (string $command): array => [
                'type' => 'shell',
                'args' => ['command' => $command],
            ],
            ['checkpoint-a', 'checkpoint-b', 'mutation', 'trigger-loss'],
        ), null, 2);

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertSame(['rejected', 'original'], $destroyed);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'workflow_run')
            ->firstOrFail();

        $failureMessage = $failure->getAttribute('message');
        $this->assertIsString($failureMessage);
        $this->assertStringContainsString('produced a different outcome', $failureMessage);
        $this->assertStringNotContainsString('cleanup also failed', $failureMessage);
    }

    public function test_suspend_loss_reconstructs_the_journal_on_the_initial_provider_before_retrying_pause(): void
    {
        WorkflowStub::fake();
        $defaultProvider = 'provider-before-config-change';
        $events = [];
        $operationIds = [];
        $provisionProviders = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, function ($context, ?string $provider) use (&$defaultProvider, &$events, &$provisionProviders): array {
            $selectedProvider = $provider ?? $defaultProvider;
            $provisionProviders[] = $selectedProvider;
            $sandboxId = count($provisionProviders) === 1 ? 'original' : 'replacement';
            $events[] = 'provision:'.$sandboxId;
            $defaultProvider = 'provider-after-config-change';

            return $this->handle($sandboxId, $selectedProvider);
        });
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$events, &$operationIds): array {
            $command = $call['args']['command'];
            $events[] = "dispatch:{$handle['id']}:{$command}";
            $operationIds[$command][] = $call['operation_id'];

            return ['exit_code' => 0, 'stdout' => $command, 'stderr' => ''];
        });
        WorkflowStub::mock(SuspendSandboxActivity::class, function ($context, array $handle) use (&$events): array {
            $events[] = 'suspend:'.$handle['id'];

            if ($handle['id'] === 'original') {
                throw new SandboxGoneException('sandbox disappeared while suspending');
            }

            return $handle;
        });
        WorkflowStub::mock(ResumeSandboxActivity::class, function ($context, array $handle) use (&$events): array {
            $events[] = 'resume:'.$handle['id'];

            return $handle;
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'first']],
            ['type' => 'shell', 'args' => ['command' => 'second']],
        ], null, 0, true);
        $output = $workflow->refresh()->output();

        $this->assertSame([
            'provision:original',
            'dispatch:original:first',
            'suspend:original',
            'provision:replacement',
            'dispatch:replacement:first',
            'suspend:replacement',
            'resume:replacement',
            'dispatch:replacement:second',
        ], $events);
        $this->assertSame(
            ['provider-before-config-change', 'provider-before-config-change'],
            $provisionProviders,
        );
        $this->assertSame([$operationIds['first'][0], $operationIds['first'][0]], $operationIds['first']);
        $this->assertSame($operationIds['first'][0], $output['tool_results'][0]['operation_id']);
        $this->assertSame('replacement', $output['sandbox_id']);
        $this->assertSame(1, $output['recovery_count']);
    }

    public function test_resume_loss_cleans_up_a_rejected_replacement_and_reconstructs_in_journal_order(): void
    {
        WorkflowStub::fake();
        $events = [];
        $operationIds = [];
        $provisionCount = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$events, &$provisionCount): array {
            $sandboxId = ['original', 'rejected', 'replacement'][$provisionCount];
            $provisionCount++;
            $events[] = 'provision:'.$sandboxId;

            return $this->handle($sandboxId);
        });
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$events, &$operationIds): array {
            $command = $call['args']['command'];
            $events[] = "dispatch:{$handle['id']}:{$command}";
            $operationIds[$command][] = $call['operation_id'];

            if ($handle['id'] === 'rejected') {
                throw new SandboxGoneException('replacement disappeared during reconstruction');
            }

            return ['exit_code' => 0, 'stdout' => $command, 'stderr' => ''];
        });
        WorkflowStub::mock(SuspendSandboxActivity::class, function ($context, array $handle) use (&$events): array {
            $events[] = 'suspend:'.$handle['id'];

            return $handle;
        });
        WorkflowStub::mock(ResumeSandboxActivity::class, function ($context, array $handle) use (&$events): array {
            $events[] = 'resume:'.$handle['id'];

            if ($handle['id'] === 'original') {
                throw new SandboxGoneException('sandbox disappeared while resuming');
            }

            return $handle;
        });
        WorkflowStub::mock(DestroySandboxActivity::class, function ($context, array $handle) use (&$events): bool {
            $events[] = 'destroy:'.$handle['id'];

            return true;
        });

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'first']],
            ['type' => 'shell', 'args' => ['command' => 'second']],
        ], null, 0, true);
        $output = $workflow->refresh()->output();

        $this->assertSame([
            'provision:original',
            'dispatch:original:first',
            'suspend:original',
            'resume:original',
            'provision:rejected',
            'dispatch:rejected:first',
            'destroy:rejected',
            'provision:replacement',
            'dispatch:replacement:first',
            'suspend:replacement',
            'resume:replacement',
            'dispatch:replacement:second',
            'destroy:replacement',
        ], $events);
        $this->assertSame(
            [$operationIds['first'][0], $operationIds['first'][0], $operationIds['first'][0]],
            $operationIds['first'],
        );
        $this->assertSame($operationIds['first'][0], $output['tool_results'][0]['operation_id']);
        $this->assertSame(2, $output['recovery_count']);
    }

    public function test_repeated_lifecycle_loss_fails_with_the_bounded_recovery_reason(): void
    {
        WorkflowStub::fake();
        $provisionCount = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, function () use (&$provisionCount): array {
            $provisionCount++;

            return $this->handle('sandbox-'.$provisionCount);
        });
        WorkflowStub::mock(DispatchToolCallActivity::class, [
            'exit_code' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);
        WorkflowStub::mock(SuspendSandboxActivity::class, function (): never {
            throw new SandboxGoneException('sandbox disappeared while suspending');
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'first']],
            ['type' => 'shell', 'args' => ['command' => 'second']],
        ], null, 0, true);

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertSame(4, $provisionCount);

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'workflow_run')
            ->firstOrFail();
        $failureMessage = $failure->getAttribute('message');
        $this->assertIsString($failureMessage);
        $this->assertStringContainsString('lost too many times during recovery', $failureMessage);
    }

    public function test_checkpoint_replacement_is_recorded_before_the_previous_snapshot_is_deleted(): void
    {
        WorkflowStub::fake();
        $events = [];
        $snapshotCount = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(DispatchToolCallActivity::class, [
            'exit_code' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);
        WorkflowStub::mock(SnapshotSandboxActivity::class, function () use (&$events, &$snapshotCount): string {
            $snapshotCount++;
            $snapshotId = 'snapshot-'.$snapshotCount;
            $events[] = 'record:'.$snapshotId;

            return $snapshotId;
        });
        WorkflowStub::mock(DeleteSnapshotActivity::class, function ($context, string $snapshotId) use (&$events): bool {
            $events[] = 'delete:'.$snapshotId;

            return true;
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'first']],
            ['type' => 'shell', 'args' => ['command' => 'second']],
        ], null, 1);

        $this->assertSame([
            'record:snapshot-1',
            'record:snapshot-2',
            'delete:snapshot-1',
            'delete:snapshot-2',
        ], $events);
        $this->assertNull($workflow->refresh()->output()['latest_snapshot']);
    }

    public function test_explicit_retention_transfers_the_latest_snapshot_to_the_caller(): void
    {
        WorkflowStub::fake();
        $deleted = [];
        $snapshotCount = 0;

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(DispatchToolCallActivity::class, [
            'exit_code' => 0,
            'stdout' => 'ok',
            'stderr' => '',
        ]);
        WorkflowStub::mock(SnapshotSandboxActivity::class, function () use (&$snapshotCount): string {
            $snapshotCount++;

            return $snapshotCount === 1 ? 'snapshot-old' : 'snapshot-retained';
        });
        WorkflowStub::mock(DeleteSnapshotActivity::class, function ($context, string $snapshotId) use (&$deleted): bool {
            $deleted[] = $snapshotId;

            return true;
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start(
            [
                ['type' => 'shell', 'args' => ['command' => 'first checkpoint']],
                ['type' => 'shell', 'args' => ['command' => 'second checkpoint']],
            ],
            null,
            1,
            false,
            [],
            true,
        );

        $this->assertSame('snapshot-retained', $workflow->refresh()->output()['latest_snapshot']);
        $this->assertSame(['snapshot-old'], $deleted);
    }

    public function test_terminal_failure_deletes_the_remaining_workflow_owned_snapshot(): void
    {
        WorkflowStub::fake();
        $deleted = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call): array {
            if ($call['args']['command'] === 'fail') {
                throw new NonRetryableException('terminal tool failure');
            }

            return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''];
        });
        WorkflowStub::mock(SnapshotSandboxActivity::class, 'snapshot-before-failure');
        WorkflowStub::mock(DeleteSnapshotActivity::class, function ($context, string $snapshotId) use (&$deleted): bool {
            $deleted[] = $snapshotId;

            return true;
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'checkpoint']],
            ['type' => 'shell', 'args' => ['command' => 'fail']],
        ], null, 1);

        $workflow->refresh();
        $this->assertTrue($workflow->failed(), 'Unexpected workflow status: '.$workflow->status());
        $this->assertSame(['snapshot-before-failure'], $deleted);
    }

    public function test_snapshot_loss_during_rotation_recovers_before_retiring_the_prior_checkpoint(): void
    {
        WorkflowStub::fake();
        $events = [];
        $snapshotAttempts = 0;
        $replayed = [];

        WorkflowStub::mock(ProvisionSandboxActivity::class, $this->handle('original'));
        WorkflowStub::mock(DispatchToolCallActivity::class, function ($context, array $handle, array $call) use (&$replayed): array {
            if ($handle['id'] === 'restored') {
                $replayed[] = $call['args']['command'];
            }

            return ['exit_code' => 0, 'stdout' => $call['args']['command'], 'stderr' => ''];
        });
        WorkflowStub::mock(SnapshotSandboxActivity::class, function () use (&$events, &$snapshotAttempts): string {
            $snapshotAttempts++;

            if ($snapshotAttempts === 2) {
                $events[] = 'snapshot:lost';
                throw new SandboxGoneException('sandbox disappeared during snapshot rotation');
            }

            $snapshotId = $snapshotAttempts === 1 ? 'snapshot-1' : 'snapshot-2';
            $events[] = 'record:'.$snapshotId;

            return $snapshotId;
        });
        WorkflowStub::mock(RestoreSandboxActivity::class, function ($context, string $snapshotId) use (&$events): array {
            $events[] = 'restore:'.$snapshotId;

            return $this->handle('restored');
        });
        WorkflowStub::mock(DeleteSnapshotActivity::class, function ($context, string $snapshotId) use (&$events): bool {
            $events[] = 'delete:'.$snapshotId;

            return true;
        });
        WorkflowStub::mock(DestroySandboxActivity::class, true);

        $workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
        $workflow->start([
            ['type' => 'shell', 'args' => ['command' => 'first']],
            ['type' => 'shell', 'args' => ['command' => 'second']],
        ], null, 1);
        $output = $workflow->refresh()->output();

        $this->assertSame([
            'record:snapshot-1',
            'snapshot:lost',
            'restore:snapshot-1',
            'record:snapshot-2',
            'delete:snapshot-1',
            'delete:snapshot-2',
        ], $events);
        $this->assertSame(['second'], $replayed);
        $this->assertSame(1, $output['recovery_count']);
    }

    /** @return array{id: string, provider: string, metadata: array<string, mixed>} */
    private function handle(string $id, string $provider = 'fake'): array
    {
        return ['id' => $id, 'provider' => $provider, 'metadata' => []];
    }
}
