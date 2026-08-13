# Delivery and recovery guarantees

Durable Workflow records each tool call in workflow history and passes a stable
`operation_id` to `SandboxProvider::execute()`. It also passes a distinct stable
operation ID to each snapshot rotation through the additive
`SnapshotReconcilingSandboxProvider` contract. The default IDs are derived from
the workflow run and call or checkpoint position, so activity retries and
workflow replay do not invent new identities. Applications may supply their own
non-empty tool-call operation ID when they need an external business key.
Caller-supplied IDs must be unique within the workflow run; the workflow rejects
a duplicate before provisioning a sandbox so distinct calls can never share a
provider idempotency key.

## Dispatch boundary

An activity can be lost before provider execution, or provider execution can
finish before its acknowledgement is recorded. The first case has no tool
effect. The second is an uncertain retry: the same operation ID can be delivered
again.

`ProviderCapabilities::deliveryGuarantee` defines the effect boundary:

- `deduplicated_operations` means repeated delivery of the same operation ID to
  the same sandbox has one observable effect. A provider should key receipts by
  both sandbox identity and operation ID; globally suppressing the operation on
  a newly restored sandbox would prevent state reconstruction.
- `at_least_once_effects` means an uncertain retry can repeat the effect. The
  call still carries the stable operation ID for logs and downstream services,
  but callers must make mutating tools idempotent or accept duplication.

Both built-in adapters currently declare `at_least_once_effects`. E2B's public
HTTP contract does not document an operation-id deduplication guarantee, and a
local subprocess cannot atomically commit an arbitrary process effect with a
receipt. The package does not claim a stronger guarantee than either provider.

## Snapshot recovery

The workflow retains the newest snapshot ID plus a journal of every completed
tool call after that snapshot. A nonzero command can still mutate workspace
state, so acknowledgement—not exit status—is the journal boundary. When a
sandbox is reported gone it:

1. restores the newest snapshot, or provisions a new sandbox if no snapshot
   exists;
2. replays the completed post-snapshot journal in original order, using each
   call's original operation ID, and requires the same exit code, stdout, and
   stderr recorded for the original completion;
3. retries the interrupted call with its original operation ID; and
4. continues only after reconstruction succeeds.

A different replay outcome fails recovery instead of continuing from state that
cannot be proven equivalent. If the sandbox disappears while creating a
replacement checkpoint, the workflow restores the current checkpoint, replays
the journal, and retries the rotation with the same snapshot operation ID. The
E2B adapter derives a deterministic snapshot name from that ID and lists by that
name before creation. If E2B persisted the snapshot but its response was lost,
the retry recovers its ID instead of creating an anonymous orphan. A new
snapshot resets the journal because its state is now in the provider checkpoint.
Recovery is bounded to three replacement attempts.

The local provider also supports a workflow-level development/test loss
injection. Its trigger identity is derived from the workflow run and completed
call count, so workflow replay and activity retry reach the same boundary. The
injection destroys the active local sandbox as an idempotent lifecycle activity
and is consumed once by the workflow. It is not dispatched as a tool call, does
not appear in `tool_results`, and cannot enter the reconstruction journal.
Completed post-snapshot tool calls remain journaled and replayed normally.

## Snapshot ownership and retention

Snapshots created by `SandboxAgentWorkflow` are workflow-owned by default. The
current checkpoint remains recoverable until the replacement snapshot activity
has completed and its ID is recorded in workflow history. Only then does the
workflow delete the superseded checkpoint. On success or failure, finalization
deletes the last workflow-owned checkpoint before the run closes.

Snapshot deletion is idempotent and its cleanup activity retries without a
finite attempt limit. Unlike a running sandbox, a persistent checkpoint has no
provider lease to bound a failed finalizer, so the workflow does not declare the
recovery window closed while deletion is still failing.

Passing `retainLatestSnapshot: true` requests an ownership transfer on successful
completion. It keeps only the newest snapshot; superseded checkpoints are still
deleted. On success the result returns its ID as `latest_snapshot`, and the caller
then owns retention and deletion of that checkpoint. If a later activity fails,
the workflow still owns and deletes the final checkpoint because no successful
result exposed its ID.

## Cleanup and leases

Every provider used by `SandboxManager` must declare both idempotent destroy and
lease reconciliation. Providers that create workflow checkpoints must also
declare snapshot deletion, implement the versioned
`SnapshotDeletingSandboxProvider` extension, and delete idempotently. Providers
that can durably name or discover a checkpoint should implement
`SnapshotReconcilingSandboxProvider` so an uncertain retry returns the original
artifact. Provision, restore, resume, snapshot, and dispatch renew a bounded
lease. Normal
finalization retries idempotent destroy three times. If all destroy attempts
fail, provider-side TTL remains the hard upper bound on a paid resource's
lifetime.

The local development provider records leases and reconciles expired workspaces
when it next starts or provisions, and deletes snapshot archives explicitly.
Those local directories are not paid remote resources and are not a security
boundary.
