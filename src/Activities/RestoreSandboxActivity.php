<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SandboxProvider;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
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
            self::assertHandleProvider($provider, $handle);
            $handle = $provider->renewLease($handle, $manager->leaseTtlSeconds($provider));
            self::assertHandleProvider($provider, $handle);
        } catch (Throwable $exception) {
            self::destroyBestEffort($provider, $handle);

            throw $exception;
        }

        return $handle->toArray();
    }

    private static function assertHandleProvider(SandboxProvider $provider, SandboxHandle $handle): void
    {
        if ($handle->provider !== $provider->name()) {
            throw new SandboxConfigurationException(
                "Sandbox provider [{$provider->name()}] returned a handle for provider [{$handle->provider}].",
            );
        }
    }

    private static function destroyBestEffort(SandboxProvider $provider, SandboxHandle $handle): void
    {
        try {
            $provider->destroy($handle);
        } catch (Throwable) {
        }
    }
}
