# Durable Workflow AI

<p align="center">
  <a href="https://github.com/durable-workflow/ai/actions/workflows/ci.yml?query=branch%3Amain"><img src="https://github.com/durable-workflow/ai/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI status"></a>
  <a href="https://packagist.org/packages/durable-workflow/ai"><img src="https://img.shields.io/packagist/v/durable-workflow/ai.svg?include_prereleases" alt="Packagist version"></a>
  <a href="https://packagist.org/packages/durable-workflow/ai"><img src="https://img.shields.io/packagist/php-v/durable-workflow/ai.svg" alt="Supported PHP versions"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/durable-workflow/ai" alt="MIT license"></a>
</p>

Durable sandbox orchestration for AI agents built on
[Durable Workflow](https://durable-workflow.com/). The package provides a
reusable Laravel workflow for provisioning a sandbox, dispatching tool calls,
checkpointing state, recovering from sandbox loss, and cleaning up resources.
Provider-specific APIs stay behind versioned contracts.

The package is currently published on the 2.0 release-candidate channel. The
Durable Workflow runtime it uses is stable 2.x.

## Install

```bash
composer require durable-workflow/ai:^2.0@RC
php artisan vendor:publish --tag=durable-workflow-ai-config
```

Laravel discovers `DurableWorkflow\AI\Laravel\SandboxServiceProvider`
automatically. The package requires PHP 8.2 or newer, Laravel 12 or newer, and
the embedded Durable Workflow runtime.

## Run A Sandbox Workflow

```php
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Workflow\V2\WorkflowStub;

$workflow = WorkflowStub::make(SandboxAgentWorkflow::class);

$workflow->start(
    toolCalls: [
        [
            'type' => 'write_file',
            'args' => [
                'path' => 'README.md',
                'contents' => "# Agent workspace\n",
            ],
        ],
        ['type' => 'shell', 'args' => ['command' => 'ls -la']],
    ],
    provider: 'e2b',
    snapshotEveryNCalls: 10,
);
```

Configure E2B before starting the application worker:

```dotenv
DURABLE_AI_SANDBOX_DRIVER=e2b
E2B_API_KEY=
E2B_TEMPLATE_ID=base
```

```bash
php artisan queue:work
```

Every tool call receives a stable operation ID. The workflow can restore its
latest snapshot after sandbox loss and replay every completed later call before
continuing. Success, cancellation, and failure all enter the cleanup path;
provider leases remain the final cleanup bound when deletion cannot complete.

## Providers

| Provider | Intended use | Snapshot recovery | Suspend and resume |
| --- | --- | --- | --- |
| `e2b` | Remote agent sandboxes | Yes | No |
| `local` | Development and deterministic tests | Yes | Yes |
| Custom | Any adapter implementing the versioned provider contract | Capability-dependent | Capability-dependent |

The local provider runs subprocesses with the application worker's privileges.
It is not a security boundary and must not execute untrusted code.

See the [provider-author guide](docs/provider-author-guide.md) to register E2B,
Modal, Daytona, Kubernetes, or another sandbox backend without changing workflow
code.

## Guarantees

The package makes effect delivery, snapshot ownership, recovery, leases, and
cleanup behavior explicit. Built-in providers currently advertise
at-least-once effects because neither remote HTTP execution nor a local process
can atomically commit an arbitrary tool effect with a Durable Workflow
acknowledgement.

Read the [delivery and recovery guarantees](docs/delivery-and-recovery.md) for
the full contract, including operation deduplication, checkpoint replacement,
post-snapshot reconstruction, and retained-snapshot ownership.

## Development

```bash
composer install
composer format
composer stan
composer test
```

Shared contribution, security, and release guidance lives in the
[Durable Workflow organization guide](https://github.com/durable-workflow/.github/blob/main/AGENTS.md).

## License

Durable Workflow AI is open-source software licensed under the [MIT license](LICENSE).
