# Durable Workflow AI

`durable-workflow/ai` is the reusable sandbox lifecycle package for Durable
Workflow agents. It keeps provisioning, tool dispatch, snapshots, recovery,
suspend/resume, leases, and cleanup behind versioned provider contracts instead
of application-specific workflow code.

## Install

The current release is `2.0.0-rc.8`, aligned with the Durable Workflow 2.0
prerelease train. While the Durable Workflow 2.0 packages are prereleases,
require both packages with explicit RC stability flags in the same Composer
invocation:

```bash
composer require durable-workflow/workflow:^2.0@RC durable-workflow/ai:2.0.0-rc.8@RC
php artisan vendor:publish --tag=durable-workflow-ai-config
```

Composer applies stability flags only to packages required by the root project,
so the prerelease runtime must be listed explicitly.

This two-package command is only needed for the prerelease. Once stable 2.0 is
available, installation will return to the ordinary one-package command:
`composer require durable-workflow/ai`.

Laravel discovers `DurableWorkflow\AI\Laravel\SandboxServiceProvider`
automatically. The package requires Laravel 12 or later and the Durable Workflow
v2 runtime.

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
    retainLatestSnapshot: false,
);
```

The workflow attaches a stable durable operation ID to every call. It snapshots
at the requested interval and, after sandbox loss, restores the newest snapshot
and replays every completed later call in order before continuing. This includes
nonzero exits because a failed command can still mutate workspace state.
Superseded snapshots are deleted only after their replacement is durably
recorded, and the remaining snapshot is deleted during finalization. Set
`retainLatestSnapshot: true` only when the caller accepts ownership of the final
checkpoint and its eventual deletion; a retained ID is returned as
`latest_snapshot` after successful completion.

## Providers

The package includes:

- `e2b`: an HTTP adapter for E2B's documented management, filesystem, and
  Connect process APIs. Configure `E2B_API_KEY` and `E2B_TEMPLATE_ID`. E2B
  suspend/resume is intentionally unavailable because its running timeout does
  not bound the lifetime of a paused sandbox.
- `local`: a subprocess workspace for development and tests. It runs commands
  with the worker's own privileges. It is not a security isolation boundary and
  must never execute untrusted code.

Each provider publishes a machine-readable
`DurableWorkflow\AI\Contracts\V1\ProviderCapabilities` value. Snapshot,
restore, snapshot deletion, suspend, resume, operation deduplication, lease
reconciliation, cleanup, and delivery guarantees are explicit. Calling an
unsupported lifecycle method throws `UnsupportedSandboxCapabilityException`; it
never silently succeeds.

The base `DurableWorkflow\AI\Contracts\V1\SandboxProvider` interface retains
its original 1.0 method boundary. Providers that advertise snapshot deletion
also implement the versioned
`DurableWorkflow\AI\Contracts\V1\SnapshotDeletingSandboxProvider` extension.
Providers that can create or discover one snapshot for a repeated operation ID
implement the additive `SnapshotReconcilingSandboxProvider` extension.

See [delivery and recovery guarantees](docs/delivery-and-recovery.md) for the
failure contract and [the provider-author guide](docs/provider-author-guide.md)
for implementing and registering an adapter.

## License

MIT
