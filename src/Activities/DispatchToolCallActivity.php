<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\SandboxToolCall;
use Workflow\V2\Activity;

final class DispatchToolCallActivity extends Activity
{
    public int $tries = 4;

    /**
     * @param  array<string, mixed>  $handle
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    public function handle(array $handle, array $call): array
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);
        $provider = $manager->driver($sandboxHandle->provider);
        $sandboxHandle = $provider->renewLease(
            $sandboxHandle,
            $manager->leaseTtlSeconds($provider),
        );

        return $provider
            ->execute($sandboxHandle, SandboxToolCall::fromArray($call))
            ->toArray();
    }
}
