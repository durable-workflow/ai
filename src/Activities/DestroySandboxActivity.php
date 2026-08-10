<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use Workflow\V2\Activity;

final class DestroySandboxActivity extends Activity
{
    public int $tries = 3;

    /** @param array<string, mixed> $handle */
    public function handle(array $handle): bool
    {
        $manager = app(SandboxManager::class);
        $sandboxHandle = SandboxHandle::fromArray($handle);
        $manager->driver($sandboxHandle->provider)->destroy($sandboxHandle);

        return true;
    }
}
