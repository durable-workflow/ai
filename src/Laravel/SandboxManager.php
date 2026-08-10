<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Laravel;

use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SandboxProvider;
use DurableWorkflow\AI\Exceptions\SandboxConfigurationException;
use DurableWorkflow\AI\Providers\E2bSandboxProvider;
use DurableWorkflow\AI\Providers\LocalSubprocessSandboxProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Throwable;

final class SandboxManager
{
    /**
     * @var array<string, callable(Container, array<string, mixed>): SandboxProvider>
     */
    private array $factories = [];

    /**
     * @var array<string, SandboxProvider>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly SandboxConfig $config,
    ) {
        $this->registerDefaults();
    }

    public function driver(?string $name = null): SandboxProvider
    {
        $name ??= $this->config->defaultDriver();
        $this->assertLocalProviderIsAllowed($name);

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        if (! isset($this->factories[$name])) {
            throw new SandboxConfigurationException(
                "Sandbox provider [{$name}] is not registered. Configure it or call SandboxManager::extend().",
            );
        }

        $provider = ($this->factories[$name])(
            $this->container,
            $this->config->driverConfig($name),
        );

        if ($provider->name() !== $name) {
            throw new SandboxConfigurationException(
                "Sandbox provider registration [{$name}] returned provider [{$provider->name()}].",
            );
        }

        try {
            $capabilities = $provider->capabilities();

            if (! $capabilities->idempotentDestroy) {
                throw new SandboxConfigurationException(
                    "Sandbox provider [{$name}] must guarantee idempotent destroy.",
                );
            }

            $capabilities->require(SandboxCapability::LeaseReconciliation, $name);
        } catch (SandboxConfigurationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SandboxConfigurationException(
                "Sandbox provider [{$name}] capability configuration is invalid: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return $this->resolved[$name] = $provider;
    }

    public function leaseTtlSeconds(SandboxProvider $provider): int
    {
        return min(
            $this->config->leaseTtlSeconds(),
            $provider->capabilities()->maximumLeaseSeconds,
        );
    }

    /**
     * @param  callable(Container, array<string, mixed>): SandboxProvider  $factory
     */
    public function extend(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    private function assertLocalProviderIsAllowed(string $name): void
    {
        if ($name !== 'local') {
            return;
        }

        $environment = $this->container->bound('env')
            ? $this->container->make('env')
            : null;

        if (is_string($environment) && in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        $environmentName = is_string($environment) && $environment !== ''
            ? $environment
            : 'unknown';

        throw new SandboxConfigurationException(
            "Sandbox provider [local] is development/test-only and cannot be used in the [{$environmentName}] environment. Configure a production sandbox provider.",
        );
    }

    private function registerDefaults(): void
    {
        $this->factories['local'] = static fn (Container $container, array $config): SandboxProvider => new LocalSubprocessSandboxProvider(
            workspaceRoot: (string) ($config['workspace_root'] ?? sys_get_temp_dir().'/durable-workflow-ai'),
            snapshotRoot: (string) ($config['snapshot_root'] ?? sys_get_temp_dir().'/durable-workflow-ai-snapshots'),
        );

        $this->factories['e2b'] = static fn (Container $container, array $config): SandboxProvider => new E2bSandboxProvider(
            apiKey: (string) ($config['api_key'] ?? ''),
            templateId: (string) ($config['template_id'] ?? 'base'),
            apiBaseUrl: (string) ($config['api_base_url'] ?? 'https://api.e2b.app'),
            sandboxBaseUrl: (string) ($config['sandbox_base_url'] ?? 'https://sandbox.e2b.app'),
            timeoutSeconds: (int) ($config['timeout_seconds'] ?? 300),
            http: $container->make(Factory::class),
        );
    }
}
