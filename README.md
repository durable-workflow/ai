# Durable Workflow AI

`durable-workflow/ai` is the reusable sandbox lifecycle package for Durable
Workflow agents. It keeps provisioning, tool dispatch, snapshots, recovery,
suspend/resume, leases, and cleanup behind versioned provider contracts instead
of application-specific workflow code.

## Install

The first release target is `2.0.0-rc.1`, aligned with the Durable Workflow 2.0
prerelease train. The following command becomes usable only after that tag is
published to Packagist:

```bash
composer require durable-workflow/ai:2.0.0-rc.1@RC
php artisan vendor:publish --tag=durable-workflow-ai-config
```

Laravel discovers `DurableWorkflow\AI\Laravel\SandboxServiceProvider`
automatically. The package requires the Durable Workflow v2 runtime.

## Run a sandbox workflow

```php
use DurableWorkflow\AI\Workflows\SandboxAgentWorkflow;
use Workflow\V2\WorkflowStub;

$workflow = WorkflowStub::make(SandboxAgentWorkflow::class);
$workflow->start(
    toolCalls: [
        ['type' => 'write_file', 'args' => ['path' => 'README.md', 'contents' => "# Agent workspace\n"]],
        ['type' => 'shell', 'args' => ['command' => 'ls -la']],
    ],
    provider: 'e2b',
    snapshotEveryNCalls: 10,
);
```

The workflow attaches a stable durable operation ID to every call. It snapshots
at the requested interval and, after sandbox loss, restores the newest snapshot
and replays every completed later call in order before continuing. This includes
nonzero exits because a failed command can still mutate workspace state.

## Providers

The package includes:

- `e2b`: an HTTP adapter for E2B's documented management, filesystem, and
  Connect process APIs. Configure `E2B_API_KEY` and `E2B_TEMPLATE_ID`.
- `local`: a subprocess workspace for development and tests. It runs commands
  with the worker's own privileges. It is not a security isolation boundary and
  must never execute untrusted code.

Each provider publishes a machine-readable
`DurableWorkflow\AI\Contracts\V1\ProviderCapabilities` value. Snapshot,
restore, suspend, resume, operation deduplication, lease reconciliation, cleanup,
and delivery guarantees are explicit. Calling an unsupported lifecycle method
throws `UnsupportedSandboxCapabilityException`; it never silently succeeds.

See [delivery and recovery guarantees](docs/delivery-and-recovery.md) for the
failure contract and [the provider-author guide](docs/provider-author-guide.md)
for implementing and registering an adapter.

## License

MIT
