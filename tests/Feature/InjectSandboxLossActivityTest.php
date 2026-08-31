<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\InjectSandboxLossActivity;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\SandboxHandle;
use DurableWorkflow\AI\Tests\Fakes\StubSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Support\ActivityRetryPolicy;

final class InjectSandboxLossActivityTest extends TestCase
{
    public function test_retried_injection_reuses_the_identity_and_idempotent_local_destroy(): void
    {
        $provider = new StubSandboxProvider('local');
        $this->app->make(SandboxManager::class)->extend(
            'local',
            static fn ($container, array $config): StubSandboxProvider => $provider,
        );
        $activity = new InjectSandboxLossActivity;
        $handle = (new SandboxHandle('sandbox-1', 'local'))->toArray();

        $first = $activity->handle($handle, 'loss-injection-operation-1');
        $second = $activity->handle($handle, 'loss-injection-operation-1');

        $this->assertSame('loss-injection-operation-1', $first);
        $this->assertSame($first, $second);
        $this->assertSame(['sandbox-1', 'sandbox-1'], $provider->destroyed);
        $this->assertSame(3, ActivityRetryPolicy::snapshot($activity)['max_attempts']);
    }

    public function test_injection_rejects_non_local_providers_before_destroy(): void
    {
        $activity = new InjectSandboxLossActivity;

        $this->expectException(NonRetryableException::class);
        $this->expectExceptionMessage('requires the [local] provider; [e2b] was selected');

        $activity->handle(
            (new SandboxHandle('sandbox-1', 'e2b'))->toArray(),
            'loss-injection-operation-1',
        );
    }
}
