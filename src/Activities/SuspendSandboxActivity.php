<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use Workflow\V2\Activity;

final class SuspendSandboxActivity extends Activity
{
    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $handle
     * @return array<string, mixed>
     */
    public function handle(array $handle): array
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);
        $provider = $manager->driver($sandboxHandle->provider);
        $provider->capabilities()->require(SandboxCapability::Suspend, $provider->name());

        return $provider->suspend($sandboxHandle)->toArray();
    }
}
