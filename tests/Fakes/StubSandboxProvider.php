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
use Throwable;

final class StubSandboxProvider implements SandboxProvider
{
    /** @var list<string> */
    public array $destroyed = [];

    public function __construct(
        private readonly string $providerName = 'stub',
        private readonly ?ProviderCapabilities $providerCapabilities = null,
        private readonly ?Throwable $capabilitiesFailure = null,
        private readonly ?Throwable $renewLeaseFailure = null,
        private readonly ?Throwable $destroyFailure = null,
        private readonly ?string $handleProvider = null,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function capabilities(): ProviderCapabilities
    {
        if ($this->capabilitiesFailure !== null) {
            throw $this->capabilitiesFailure;
        }

        return $this->providerCapabilities ?? new ProviderCapabilities(
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
        return new SandboxHandle('provisioned', $this->handleProvider ?? $this->providerName);
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
        return 'snapshot';
    }

    public function restore(string $snapshotId): SandboxHandle
    {
        return new SandboxHandle('restored', $this->handleProvider ?? $this->providerName);
    }

    public function renewLease(SandboxHandle $handle, int $ttlSeconds): SandboxHandle
    {
        if ($this->renewLeaseFailure !== null) {
            throw $this->renewLeaseFailure;
        }

        return $handle;
    }

    public function destroy(SandboxHandle $handle): void
    {
        $this->destroyed[] = $handle->id;

        if ($this->destroyFailure !== null) {
            throw $this->destroyFailure;
        }
    }
}
