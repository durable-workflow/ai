<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

/**
 * Resolves the effective provider without provisioning a sandbox.
 */
final class ResolveSandboxProviderActivity extends Activity
{
    public function handle(?string $providerName = null): string
    {
        try {
            return app(SandboxManager::class)->driver($providerName)->name();
        } catch (SandboxConfigurationException $exception) {
            throw new NonRetryableException($exception->getMessage(), previous: $exception);
        }
    }
}
