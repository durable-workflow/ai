# Provider author guide

Provider integrations implement the versioned base
`DurableWorkflow\AI\Contracts\V1\SandboxProvider` interface. Snapshot deletion
is an additive contract: providers that support it implement
`DurableWorkflow\AI\Contracts\V1\SnapshotDeletingSandboxProvider`, which
extends the unchanged base interface. Register a factory with
`SandboxManager::extend()`; workflows and activities should never resolve a
concrete adapter directly.

```php
use DurableWorkflow\AI\Contracts\V1\DeliveryGuarantee;
use DurableWorkflow\AI\Contracts\V1\ProviderCapabilities;
use DurableWorkflow\AI\Contracts\V1\SandboxCapability;
use DurableWorkflow\AI\Contracts\V1\SnapshotDeletingSandboxProvider;
use DurableWorkflow\AI\Laravel\SandboxManager;

$this->app->afterResolving(SandboxManager::class, function (SandboxManager $manager): void {
    $manager->extend('acme', static fn ($app, array $config): SnapshotDeletingSandboxProvider => new AcmeProvider(
        token: (string) $config['token'],
    ));
});

public function capabilities(): ProviderCapabilities
{
    return new ProviderCapabilities(
        supported: [
            SandboxCapability::Snapshot,
            SandboxCapability::SnapshotDeletion,
            SandboxCapability::Restore,
            SandboxCapability::LeaseReconciliation,
        ],
        deliveryGuarantee: DeliveryGuarantee::AtLeastOnceEffects,
        idempotentDestroy: true,
        maximumLeaseSeconds: 900,
    );
}
```

Add matching configuration beneath `durable-workflow-ai.drivers.acme` and set
`DURABLE_AI_SANDBOX_DRIVER=acme`.

## Required behavior

`name()` must exactly match the manager registration. `provision()` returns an
opaque `SandboxHandle`; never put short-lived credentials or access tokens in
handle metadata because handles are durable activity arguments. Resolve secrets
inside each activity-side provider call.

Provisioning failures are retryable unless the provider throws
`PermanentSandboxProvisionException`. Use that type only when another attempt
with the same input cannot succeed, such as missing credentials or a rejected
invalid request. Rate limits, request timeouts, conflicts, and provider 5xx
responses must remain retryable.

`execute()` must consume `SandboxToolCall::operationId`. If the provider can
atomically retain a result receipt with the tool effect, advertise operation
deduplication and return the retained result on a repeated delivery to the same
sandbox. Otherwise advertise `at_least_once_effects`. Do not infer
deduplication from a request header that the provider does not guarantee.

Snapshot and restore must survive worker restarts. Restoring a snapshot must
create a sandbox capable of receiving the completed post-snapshot journal,
including calls with nonzero exit codes, and reproducing their recorded
outcomes. A provider used for workflow checkpointing must expose
`SnapshotDeletion`, implement `SnapshotDeletingSandboxProvider`, and make
`deleteSnapshot()` idempotent: an already deleted or unknown ID succeeds, while
transient control-plane failures throw so the cleanup activity retries. The
base `SandboxProvider` contract remains at version 1.0, and the deletion
extension has its own `sandbox-provider.snapshot-deletion` 1.0 metadata entry.
A provider built against the original base contract continues to load; when it
leaves snapshot deletion out, a checkpoint request fails at capability
negotiation before an unsupported method is called.

Suspend and resume are separate capabilities. Do not implement an unsupported
operation as a no-op. Throw `UnsupportedSandboxCapabilityException`, normally by
calling `ProviderCapabilities::require()`. A provider may advertise suspension
only when the sandbox's deletion deadline remains effective while paused, or an
independently durable cleanup obligation is established before the pause. E2B
does not currently advertise suspend or resume because its running-sandbox
timeout does not delete a paused sandbox; both operations fail before a pause or
connect request is sent.

`destroy()` must succeed when the sandbox is already gone. Transient deletion
errors should throw so activity retry can try again. `renewLease()` must enforce
expiry in the provider control plane or another independently running
reconciler; updating only process-local state is not sufficient for production
paid resources. The manager rejects providers without idempotent destroy or
lease reconciliation.

## Contract tests

A provider test suite should prove:

- the capability value matches every implemented and unsupported method;
- repeated destroy is safe;
- lease expiry is bounded and renewals use the advertised maximum;
- the identical operation ID reaches every retry;
- an effect completed before acknowledgement follows the advertised delivery
  guarantee;
- snapshot restore plus several later completed calls reconstructs the same
  state;
- repeated snapshot deletion is safe and removes persistent filesystem and
  memory state; and
- authentication, request paths, payload casing, response casing, not-found
  mapping, and timeout behavior match the provider's public API.

The E2B adapter's tests are examples of HTTP contract assertions. It uses
`POST /sandboxes`, `POST /sandboxes/{sandboxID}/snapshots`,
`DELETE /templates/{snapshotID}`,
`POST /sandboxes/{sandboxID}/timeout`, `DELETE /sandboxes/{sandboxID}`, the envd
filesystem endpoints, and `process.Process/Start`. Its sandbox access token is
retrieved within the activity and never stored in workflow history.
