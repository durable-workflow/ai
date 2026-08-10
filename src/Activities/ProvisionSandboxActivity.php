<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Contracts\V1\SandboxProvider;
use DurableWorkflow\AI\Exceptions\PermanentSandboxProvisionException;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use Throwable;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

final class ProvisionSandboxActivity extends Activity
{
    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function handle(?string $providerName = null, array $options = []): array
    {
        $manager = app(SandboxManager::class);

        try {
            $provider = $manager->driver($providerName);
            $ttl = $manager->leaseTtlSeconds($provider);
            $options['lease_ttl_seconds'] = $ttl;
            $handle = $provider->provision($options);
            self::assertHandleProvider($provider, $handle);
            $handle = $provider->renewLease($handle, $ttl);
            self::assertHandleProvider($provider, $handle);
        } catch (PermanentSandboxProvisionException|SandboxConfigurationException $exception) {
            if (isset($handle, $provider)) {
                self::destroyBestEffort($provider, $handle);
            }

            throw new NonRetryableException($exception->getMessage(), previous: $exception);
        } catch (Throwable $exception) {
            if (isset($handle, $provider)) {
                self::destroyBestEffort($provider, $handle);
            }

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
