<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Unit;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxOperationId;
use DurableWorkflow\AI\SandboxToolCall;
use DurableWorkflow\AI\SandboxToolResult;
use PHPUnit\Framework\TestCase;

final class PublicContractTest extends TestCase
{
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
    }
}
