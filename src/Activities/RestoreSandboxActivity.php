<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Laravel\SandboxManager;
use Throwable;
use Workflow\V2\Activity;

final class RestoreSandboxActivity extends Activity
{
    public int $tries = 5;

    /** @return array<string, mixed> */
    public function handle(string $snapshotId, ?string $providerName = null): array
    {
        $manager = app(SandboxManager::class);
        $provider = $manager->driver($providerName);
        $provider->capabilities()->require(SandboxCapability::Restore, $provider->name());
        $handle = $provider->restore($snapshotId);

        try {
            $handle = $provider->renewLease($handle, $manager->leaseTtlSeconds($provider));
        } catch (Throwable $exception) {
            try {
                $provider->destroy($handle);
            } catch (Throwable) {
            }

            throw $exception;
        }

        return $handle->toArray();
    }
}
