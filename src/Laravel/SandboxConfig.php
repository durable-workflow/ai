<?php

declare(strict_types=1);

namespace DurableWorkflow\AI\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class SandboxConfig
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function defaultDriver(): string
    {
        return (string) $this->config->get('durable-workflow-ai.default', 'local');
    }

    public function leaseTtlSeconds(): int
    {
        return max(1, (int) $this->config->get('durable-workflow-ai.lease_ttl_seconds', 900));
    }

    /**
     * @return array<string, mixed>
     */
    public function driverConfig(string $name): array
    {
        $config = $this->config->get("durable-workflow-ai.drivers.{$name}");

        return is_array($config) ? $config : [];
    }
}
