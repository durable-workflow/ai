<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Fakes;

use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SandboxProvider;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use DurableWorkflow\AI\SandboxToolResult;

/**
 * The exact provider method boundary published in 2.0.0-rc.1.
 */
final class Rc1SandboxProvider implements SandboxProvider
{
    public function name(): string
    {
        return 'rc1';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            supported: [
                SandboxCapability::Snapshot,
                SandboxCapability::Restore,
                SandboxCapability::LeaseReconciliation,
            ],
            deliveryGuarantee: DeliveryGuarantee::AtLeastOnceEffects,
            idempotentDestroy: true,
            maximumLeaseSeconds: 300,
        );
    }

    public function provision(array $options = []): SandboxHandle
    {
        return new SandboxHandle('rc1-sandbox', $this->name());
    }

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult
    {
        return new SandboxToolResult(0);
    }

    public function suspend(SandboxHandle $handle): SandboxHandle
    {
        return $handle;
    }

    public function resume(SandboxHandle $handle): SandboxHandle
    {
        return $handle;
    }

    public function snapshot(SandboxHandle $handle): string
    {
        return 'rc1-snapshot';
    }

    public function restore(string $snapshotId): SandboxHandle
    {
        return new SandboxHandle('rc1-restored', $this->name());
    }

    public function renewLease(SandboxHandle $handle, int $ttlSeconds): SandboxHandle
    {
        return $handle;
    }

    public function destroy(SandboxHandle $handle): void {}
}
