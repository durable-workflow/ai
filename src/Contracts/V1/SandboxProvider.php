<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Contracts\V1;

use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use DurableWorkflow\AI\SandboxToolResult;

/**
 * Version 1 of the public provider contract.
 *
 * destroy() must be idempotent. renewLease() must arrange provider-side expiry,
 * not merely update process-local state, so failed finalizers remain bounded.
 */
interface SandboxProvider
{
    public function name(): string;

    public function capabilities(): ProviderCapabilities;

    /**
     * @param  array<string, mixed>  $options
     */
    public function provision(array $options = []): SandboxHandle;

    public function execute(SandboxHandle $handle, SandboxToolCall $call): SandboxToolResult;

    public function suspend(SandboxHandle $handle): SandboxHandle;

    public function resume(SandboxHandle $handle): SandboxHandle;

    public function snapshot(SandboxHandle $handle): string;

    public function restore(string $snapshotId): SandboxHandle;

    public function renewLease(SandboxHandle $handle, int $ttlSeconds): SandboxHandle;

    public function destroy(SandboxHandle $handle): void;
}
