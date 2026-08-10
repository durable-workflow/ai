<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Exceptions\PermanentSandboxProvisionException;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Laravel\SandboxManager;
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
            $handle = $provider->renewLease($handle, $ttl);
        } catch (PermanentSandboxProvisionException|SandboxConfigurationException $exception) {
            throw new NonRetryableException($exception->getMessage(), previous: $exception);
        } catch (Throwable $exception) {
            if (isset($handle, $provider)) {
                try {
                    $provider->destroy($handle);
                } catch (Throwable) {
                }
            }

            throw $exception;
        }

        return $handle->toArray();
    }
}
