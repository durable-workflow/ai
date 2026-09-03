# Durable Execution Design Patterns

Read this reference when the task requires architecture beyond the core
workflow/activity split.

## Infrastructure Provisioning

Model each provider mutation as an idempotent activity keyed by the desired
resource identity.

1. Validate the requested topology without creating resources.
2. Record the provisioning intent and stable operation IDs.
3. Create resources in dependency order.
4. Poll or receive callbacks for asynchronous provider operations using durable
   timers rather than a busy worker.
5. Verify application-level readiness, not only provider state.
6. Mark the deployment accepted.
7. On failure, reconcile unknown outcomes before retrying and destroy only
   resources owned by this run.

Provisioning APIs commonly time out after accepting a request. A blind retry can
create duplicate billable resources. Search by idempotency key, tags, or recorded
provider IDs before creating again.

For blue/green deployment, keep the old generation recoverable until the new
generation passes health checks and a bounded observation period. Database
changes need their own expand/migrate/contract discipline; swapping hosts does
not make an incompatible schema rollback-safe.

## Payment And Order Processing

Use the order or payment attempt as workflow identity. Keep authorization,
capture, fulfillment, and notification as separate effects.

- Pass a stable idempotency key to every payment mutation.
- Distinguish a decline from a transport failure.
- Reconcile an unknown authorization/capture outcome with the payment provider.
- Wait durably for settlement, disputes, or customer action.
- Compensate with domain actions such as void, refund, or inventory release.
- Never store full payment credentials in workflow input or history.

If the complete operation fits in one provider call and one local database
transaction, an idempotent queued job may be enough.

## Human Approval

Represent approval as an authenticated message with:

- workflow and approval-stage identity
- reviewer identity and authorization context
- unique message ID
- decision and optional reason
- decision timestamp supplied as data, not read nondeterministically by workflow
  code

The workflow should define expiration, escalation, withdrawal, duplicate, and
late-arrival behavior. A query can show pending approval state; an update can
validate and accept a decision. Do not hold a request or polling worker while a
human decides.

## Long-Running Import

Split work into restartable partitions with stable identities. Persist source
version/checksum and cursor state so a resumed import does not silently read a
different input.

- Keep parsing or transformation pure where possible.
- Make destination writes idempotent by source record ID and import generation.
- Bound fan-out to destination capacity.
- Record rejected records separately from infrastructure failures.
- Use child workflows for large independent partitions.
- Roll history forward for very large or continuous imports.

If recomputing the entire import is cheap and has no external side effects, a
batch job with checkpoint files may be simpler.

## External Callback

Create the callback correlation token before initiating the external operation.
Store only a hash when the token itself is sensitive.

1. Schedule the external request as an activity.
2. Persist the provider operation ID returned by the activity.
3. Wait for callback or timeout.
4. Authenticate and deduplicate callbacks at ingress.
5. Deliver the normalized event to the workflow.
6. On timeout, query provider state before retrying or compensating.

Design for callback-before-wait registration, duplicate callback, and callback
after terminal completion.

## Saga Compensation

Keep a durable compensation stack. Add compensation responsibility as soon as a
forward effect becomes possible, then mark whether reconciliation shows that it
actually occurred.

Compensation should be:

- idempotent
- independently retryable
- observable as business work
- aware that later real-world changes may make reversal impossible

Do not catch every failure and report success merely because compensation was
attempted. Preserve the original failure and compensation outcome.

## Entity Workflow

A long-lived workflow can own one entity's serialized state and messages, such
as an account onboarding process or device lifecycle.

Use this pattern when single-writer ordering and durable waiting are valuable.
Avoid it when state must be queried or joined at high volume; maintain an
application-owned projection for search and reporting. Roll history forward and
define how messages are routed during continuation or migration.

## Child Workflow Fan-Out

Use children when each item needs its own lifecycle, retry policy, cancellation,
or history. Use bounded activity concurrency when items are just parallel tasks
inside one lifecycle.

Define:

- maximum active children
- parent close and cancellation policy
- aggregation behavior for partial failure
- stable child IDs so parent replay cannot duplicate them
- backpressure against downstream systems

## Durable Agent Session

A durable supervisor can model:

1. provision or restore sandbox
2. ask the model for the next intent in an activity
3. validate intent against policy and remaining budget
4. dispatch tool call as an idempotent activity
5. checkpoint artifacts or sandbox state
6. wait for approval when required
7. repeat within hard iteration/time/spend bounds
8. summarize outcome
9. release resources through finalization plus lease reconciliation

Store model/tool evidence outside workflow history when large, recording an
immutable reference and digest. Treat model output as untrusted input, even when
it was produced by your own configured model.

## Common Anti-Patterns

### Queue choreography without durable ownership

Several queues publish events to one another, but no durable instance owns the
overall state, timeout, or compensation. Add an orchestrator when reconstructing
progress requires reading logs across services.

### Polling loop in a worker

A worker sleeps and polls for hours, tying progress to one process. Replace it
with durable timers plus bounded polling activities, or a callback/signal.

### Retry everything

Permanent failures consume capacity and hide actionable errors. Classify errors,
bound attempts by elapsed time, and surface exhaustion.

### Exactly-once claims without an effect protocol

Persisting workflow history cannot atomically commit an arbitrary third-party
side effect. Require idempotency/reconciliation at the boundary.

### Live reads in workflow code

Replaying against changed database/configuration state can choose a different
branch. Read through an activity and use its recorded result.

### Cleanup only in workflow finalization

Termination, runtime loss, or a permanently failing cleanup activity can leave
resources behind. Add leases and an external reconciler.

### One workflow owns everything

Unbounded histories and fan-out make replay, recovery, and operations expensive.
Partition ownership and roll history forward.
