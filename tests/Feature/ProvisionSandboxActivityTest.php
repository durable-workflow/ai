<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Tests\Feature;

use DurableWorkflow\AI\Activities\ProvisionSandboxActivity;
use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Exceptions\SandboxProvisionException;
use DurableWorkflow\AI\Laravel\SandboxManager;
use DurableWorkflow\AI\Tests\Fakes\StubSandboxProvider;
use DurableWorkflow\AI\Tests\TestCase;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use RuntimeException;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;

final class ProvisionSandboxActivityTest extends TestCase
{
    public function test_transient_e2b_responses_remain_retryable_activity_failures(): void
    {
        $statuses = [429, 503];
        $this->configureE2b();
        $http = $this->app->make(Factory::class);
        $http->fake(function () use (&$statuses, $http) {
            return $http->response(
                ['message' => 'temporarily unavailable'],
                array_shift($statuses),
            );
        });

        foreach ([429, 503] as $status) {
            try {
                $this->activity()->handle('e2b');
                $this->fail("Expected HTTP {$status} to fail provisioning.");
            } catch (SandboxProvisionException $exception) {
                $this->assertSame(SandboxProvisionException::class, $exception::class);
                $this->assertStringContainsString("HTTP {$status}", $exception->getMessage());
            }
        }
    }

    public function test_invalid_e2b_request_is_a_nonretryable_activity_failure(): void
    {
        $this->configureE2b();
        $http = $this->app->make(Factory::class);
        $http->fake([
            'https://api.e2b.app/sandboxes' => $http->response(
                ['message' => 'templateID is invalid'],
                422,
            ),
        ]);

        $this->expectException(NonRetryableException::class);
        $this->activity()->handle('e2b');
    }

    public function test_missing_e2b_credentials_are_a_nonretryable_activity_failure(): void
    {
        $this->configureE2b(apiKey: '');

        $this->expectException(NonRetryableException::class);
        $this->activity()->handle('e2b');
    }

    public function test_unknown_provider_is_a_nonretryable_activity_failure(): void
    {
        $this->expectException(NonRetryableException::class);
        $this->expectExceptionMessage('Sandbox provider [missing] is not registered');
        $this->activity()->handle('missing');
    }

    public function test_unusable_local_workspace_configuration_is_nonretryable(): void
    {
        $invalidRoot = tempnam(sys_get_temp_dir(), 'durable-workflow-ai-invalid-');

        if ($invalidRoot === false) {
            $this->fail('Could not create invalid local workspace fixture.');
        }

        try {
            $this->app['config']->set('durable-workflow-ai.drivers.local', [
                'workspace_root' => $invalidRoot,
                'snapshot_root' => $invalidRoot,
            ]);

            $this->activity()->handle('local');
            $this->fail('Expected unusable local paths to fail provisioning.');
        } catch (NonRetryableException $exception) {
            $this->assertStringContainsString(
                'Local subprocess sandbox paths are not usable',
                $exception->getMessage(),
            );
        } finally {
            @unlink($invalidRoot);
        }
    }

    public function test_missing_required_provider_capability_is_nonretryable_configuration(): void
    {
        $provider = new StubSandboxProvider(
            providerName: 'missing-lease',
            providerCapabilities: new ProviderCapabilities(
                supported: [],
                deliveryGuarantee: DeliveryGuarantee::AtLeastOnceEffects,
                idempotentDestroy: true,
                maximumLeaseSeconds: 300,
            ),
        );
        $this->extendManager('missing-lease', $provider);

        $this->expectException(NonRetryableException::class);
        $this->expectExceptionMessage('does not support [lease_reconciliation]');
        $this->activity()->handle('missing-lease');
    }

    public function test_provider_capability_construction_failure_is_nonretryable_configuration(): void
    {
        $provider = new StubSandboxProvider(
            providerName: 'invalid-capabilities',
            capabilitiesFailure: new InvalidArgumentException('capability payload is invalid'),
        );
        $this->extendManager('invalid-capabilities', $provider);

        $this->expectException(NonRetryableException::class);
        $this->expectExceptionMessage('capability configuration is invalid');
        $this->activity()->handle('invalid-capabilities');
    }

    public function test_provider_handle_name_mismatch_is_cleaned_up_and_nonretryable(): void
    {
        $provider = new StubSandboxProvider(
            providerName: 'selected',
            destroyFailure: new RuntimeException('mismatched handle cleanup failed'),
            handleProvider: 'other',
        );
        $this->extendManager('selected', $provider);

        try {
            $this->activity()->handle('selected');
            $this->fail('Expected a mismatched provider handle to fail provisioning.');
        } catch (NonRetryableException $exception) {
            $this->assertStringContainsString(
                'Sandbox provider [selected] returned a handle for provider [other]',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(SandboxConfigurationException::class, $exception->getPrevious());
        }

        $this->assertSame(['provisioned'], $provider->destroyed);
    }

    private function configureE2b(string $apiKey = 'e2b-test-key'): void
    {
        $this->app['config']->set('durable-workflow-ai.drivers.e2b', [
            'api_key' => $apiKey,
            'template_id' => 'coding-agent',
            'api_base_url' => 'https://api.e2b.app',
            'sandbox_base_url' => 'https://sandbox.e2b.app',
            'timeout_seconds' => 60,
        ]);
    }

    private function activity(): ProvisionSandboxActivity
    {
        return new ProvisionSandboxActivity(new ActivityExecution, new WorkflowRun);
    }

    private function extendManager(string $name, StubSandboxProvider $provider): void
    {
        $this->app->make(SandboxManager::class)->extend(
            $name,
            static fn ($container, array $config): StubSandboxProvider => $provider,
        );
    }
}
