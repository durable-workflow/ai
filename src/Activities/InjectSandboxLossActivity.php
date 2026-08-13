<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Activities;

use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

/**
 * Development/test-only boundary for simulating confirmed sandbox loss.
 *
 * The operation is intentionally separate from tool dispatch so it can never
 * become a completed tool effect in the reconstruction journal.
 */
final class InjectSandboxLossActivity extends Activity
{
    public int $tries = 3;

    /** @param array<string, mixed> $handle */
    public function handle(array $handle, string $operationId): string
    {
        if ($operationId === '') {
            throw new NonRetryableException('Sandbox loss injection requires a stable operation id.');
        }

        $sandboxHandle = SandboxHandle::fromArray($handle);

        if ($sandboxHandle->provider !== 'local') {
            throw new NonRetryableException(
                "Sandbox loss injection is development/test-only and requires the [local] provider; [{$sandboxHandle->provider}] was selected.",
            );
        }

        $manager = app(SandboxManager::class);
        $manager->driver($sandboxHandle->provider)->destroy($sandboxHandle);

        return $operationId;
    }
}
