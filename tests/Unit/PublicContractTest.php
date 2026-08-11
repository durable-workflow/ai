<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Unit;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SandboxProvider;
use DurableWorkflow\AI\Contracts\V1\SnapshotDeletingSandboxProvider;
use DurableWorkflow\AI\Contracts\V1\SnapshotReconcilingSandboxProvider;
use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxOperationId;
use DurableWorkflow\AI\SandboxToolCall;
use DurableWorkflow\AI\SandboxToolResult;
use PHPUnit\Framework\TestCase;

final class PublicContractTest extends TestCase
{
    public function test_snapshot_extensions_are_machine_readable_without_changing_the_v1_boundary(): void
    {
        $base = new \ReflectionClass(SandboxProvider::class);
        $deletion = new \ReflectionClass(SnapshotDeletingSandboxProvider::class);
        $reconciliation = new \ReflectionClass(SnapshotReconcilingSandboxProvider::class);

        $this->assertFalse($base->hasMethod('deleteSnapshot'));
        $this->assertFalse($base->hasMethod('snapshotForOperation'));
        $this->assertSame(1, $base->getMethod('snapshot')->getNumberOfRequiredParameters());
        $this->assertTrue($deletion->hasMethod('deleteSnapshot'));
        $this->assertTrue($deletion->isSubclassOf(SandboxProvider::class));
        $this->assertTrue($reconciliation->hasMethod('snapshotForOperation'));
        $this->assertTrue($reconciliation->isSubclassOf(SandboxProvider::class));
        $this->assertTrue($reconciliation->isSubclassOf(SnapshotDeletingSandboxProvider::class));

        /** @var array{extra: array{durable-workflow: array{contracts: array<string, string>}}} $composer */
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $contracts = $composer['extra']['durable-workflow']['contracts'];

        $this->assertSame(ProviderCapabilities::CONTRACT_VERSION, $contracts['sandbox-provider']);
        $this->assertSame(
            SnapshotDeletingSandboxProvider::CONTRACT_VERSION,
            $contracts[SnapshotDeletingSandboxProvider::CONTRACT_NAME],
        );
        $this->assertSame(
            SnapshotReconcilingSandboxProvider::CONTRACT_VERSION,
            $contracts[SnapshotReconcilingSandboxProvider::CONTRACT_NAME],
        );
    }

    public function test_versioned_capabilities_are_machine_readable_and_unsupported_operations_fail_clearly(): void
    {
        $capabilities = new ProviderCapabilities(
            supported: [SandboxCapability::LeaseReconciliation],
            deliveryGuarantee: DeliveryGuarantee::AtLeastOnceEffects,
            idempotentDestroy: true,
            maximumLeaseSeconds: 300,
        );

        $this->assertSame('1.0', $capabilities->toArray()['contract_version']);
        $this->assertSame('at_least_once_effects', $capabilities->toArray()['delivery_guarantee']);
        $this->assertContains(
            SandboxCapability::SnapshotDeletion->value,
            (new ProviderCapabilities(
                supported: [SandboxCapability::SnapshotDeletion, SandboxCapability::LeaseReconciliation],
                deliveryGuarantee: DeliveryGuarantee::AtLeastOnceEffects,
                idempotentDestroy: true,
                maximumLeaseSeconds: 300,
            ))->toArray()['supported'],
        );

        $this->expectException(UnsupportedSandboxCapabilityException::class);
        $this->expectExceptionMessage('does not support [snapshot]');
        $capabilities->require(SandboxCapability::Snapshot, 'limited');
    }

    public function test_typed_values_round_trip_without_losing_operation_identity(): void
    {
        $handle = SandboxHandle::fromArray((new SandboxHandle('sbx', 'fake', ['region' => 'test']))->toArray());
        $call = SandboxToolCall::fromArray((new SandboxToolCall('operation-1', 'shell', ['command' => 'true']))->toArray());
        $result = SandboxToolResult::fromArray((new SandboxToolResult(0, 'ok'))->toArray());

        $this->assertSame('sbx', $handle->id);
        $this->assertSame('operation-1', $call->operationId);
        $this->assertTrue($result->succeeded());
    }

    public function test_operation_ids_are_stable_per_run_and_call_index(): void
    {
        $first = SandboxOperationId::forWorkflowCall('run-123', 4);

        $this->assertSame($first, SandboxOperationId::forWorkflowCall('run-123', 4));
        $this->assertNotSame($first, SandboxOperationId::forWorkflowCall('run-123', 5));
        $this->assertNotSame($first, SandboxOperationId::forWorkflowCall('run-456', 4));

        $snapshot = SandboxOperationId::forWorkflowSnapshot('run-123', 4);
        $this->assertSame($snapshot, SandboxOperationId::forWorkflowSnapshot('run-123', 4));
        $this->assertNotSame($snapshot, SandboxOperationId::forWorkflowSnapshot('run-123', 5));
        $this->assertNotSame($first, $snapshot);
    }
}
