<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Contracts\V1;

use DurableWorkflow\AI\Exceptions\UnsupportedSandboxCapabilityException;
use InvalidArgumentException;

final class ProviderCapabilities
{
    public const CONTRACT_VERSION = '1.0';

    /**
     * @param  list<SandboxCapability>  $supported
     */
    public function __construct(
        private readonly array $supported,
        public readonly DeliveryGuarantee $deliveryGuarantee,
        public readonly bool $idempotentDestroy,
        public readonly int $maximumLeaseSeconds,
    ) {
        if ($maximumLeaseSeconds < 1) {
            throw new InvalidArgumentException('A sandbox provider must enforce a positive maximum lease.');
        }

        if ($this->supports(SandboxCapability::OperationDeduplication)
            !== ($deliveryGuarantee === DeliveryGuarantee::DeduplicatedOperations)) {
            throw new InvalidArgumentException(
                'Operation deduplication capability and delivery guarantee must agree.',
            );
        }
    }

    public function supports(SandboxCapability $capability): bool
    {
        return in_array($capability, $this->supported, true);
    }

    public function require(SandboxCapability $capability, string $provider): void
    {
        if (! $this->supports($capability)) {
            throw UnsupportedSandboxCapabilityException::for($provider, $capability);
        }
    }

    /**
     * @return array{
     *     contract_version: string,
     *     supported: list<string>,
     *     delivery_guarantee: string,
     *     idempotent_destroy: bool,
     *     maximum_lease_seconds: int
     * }
     */
    public function toArray(): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'supported' => array_map(
                static fn (SandboxCapability $capability): string => $capability->value,
                $this->supported,
            ),
            'delivery_guarantee' => $this->deliveryGuarantee->value,
            'idempotent_destroy' => $this->idempotentDestroy,
            'maximum_lease_seconds' => $this->maximumLeaseSeconds,
        ];
    }
}
