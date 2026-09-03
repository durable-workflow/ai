# Durable Execution Operations

Read this reference when designing production readiness, diagnosing a run, or
changing long-lived workflow code.

## Minimum Run View

An operator should be able to answer without reading raw database rows:

- Which business operation is this?
- What phase is it in?
- What is it waiting for?
- Is a worker able to process the required task queue and type?
- Which attempt is active, and when is the next retry?
- What was the last durable event and last failure?
- Which external operation IDs and idempotency keys correlate its effects?
- Can it be cancelled, updated, retried, reset, or terminated safely?
- Which code/build/version will handle its next task?

Expose application-owned projections for business dashboards. Runtime history is
the execution audit trail, not a substitute for searchable product read models.

## Diagnostic Order

1. Confirm workflow identity, namespace/tenant, run, and current status.
2. Read the durable history around the last progress event.
3. Identify whether it is waiting on a timer, message, activity, child, or worker.
4. Check task-queue registration and worker compatibility.
5. Inspect the specific activity attempt and external provider operation.
6. Decide whether the outcome is failed, pending, or unknown.
7. Use a supported mutation only after understanding its replay and side-effect
   consequences.

Do not respond to a stuck run by starting a replacement with a new ID until you
know the old run cannot still produce effects.

## Failure Categories

### Dispatch failure

Symptoms: scheduled work remains unleased, queue age rises, or no compatible
handler is registered.

Check worker health, task queue, workflow/activity type spelling, deployment
version, authentication, namespace, concurrency limits, and rate limits.

### Activity failure

Inspect attempt count, timeout type, heartbeat details, and whether the external
operation may have succeeded. Retry only after applying the activity's
idempotency or reconciliation protocol.

### Replay failure

Stop the incompatible rollout, retain histories and failing build identity, and
replay offline against the exact history. Restore compatible code or apply the
engine's supported version/patch mechanism. Do not edit history to make it pass.

### Waiting forever

Confirm the correlation key, message authentication, deduplication, and whether
the event arrived before the workflow established its logical wait. Every
business wait should have a documented no-timeout or timeout/escalation policy.

### Compensation failure

Keep both the original failure and compensation state visible. Retry or repair
the compensation independently; do not mark the run cleanly rolled back while a
compensating effect remains unresolved.

## Safe Control Actions

- **Signal/message:** report an event; design handlers for duplicates and stale
  delivery.
- **Update/command:** validate and mutate live state with an acknowledged result.
- **Cancel:** request cooperative shutdown and bounded cleanup.
- **Retry:** re-attempt a failed boundary under the same durable identity when
  supported.
- **Reset:** create a new execution path from prior history; review which effects
  are already committed before doing so.
- **Terminate:** stop immediately when continued execution is more dangerous than
  skipped cleanup.

Require authorization and an audit record for mutating actions. Capture actor,
reason, prior state, action, and resulting run identity.

## Metrics And Alerts

Track at least:

- workflow starts, completions, failures, cancellations, and age
- task queue depth and schedule-to-start latency
- activity latency, attempts, timeout types, and retry exhaustion
- timer and message wait age against business deadlines
- worker registrations, poll health, capacity, and incompatible handlers
- replay latency and nondeterminism failures
- history event/byte growth and external payload failures
- compensation backlog and cleanup/lease expiry

Alert on actionable conditions, not raw event volume. Split platform health from
business outcome metrics.

## Deployment And Versioning

Before rollout:

1. Inventory open runs and the workflow types/builds they need.
2. Replay a representative history corpus against the candidate.
3. Verify payload compatibility in both directions needed by the rollout.
4. Confirm workers advertise or route compatible workflow types.
5. Use additive schema changes and engine-supported version markers.
6. Keep rollback code capable of reading any data written by the candidate.
7. Observe replay, dispatch, and failure metrics before retiring old workers.

For irreversible database changes, use expand/migrate/contract phases. A worker
rollback is not safe if the old worker cannot read the new schema.

## Recovery Exercises

Run these against real persistence boundaries:

- kill a worker during every side-effect boundary
- lose acknowledgement after external success
- restart all workers and the orchestration service
- delay and duplicate messages
- make a task queue unavailable, then restore it
- exhaust retries and perform the documented repair
- cancel and terminate at different phases
- corrupt or remove external payload data in a disposable environment
- replay old histories against the next release
- restore persistence from backup and verify workflow plus operator state

Record recovery time and unresolved effects, not only whether the final status
eventually became completed.

## Security And Data Handling

- Keep credentials out of inputs, outputs, memo, search attributes, history, and
  logs.
- Encrypt transport and durable stores; define tenant isolation at every queue,
  history, payload, and operator boundary.
- Validate and authorize signals, callbacks, and operator commands.
- Treat workflow IDs and external operation IDs as identifiers, not secrets.
- Apply retention and deletion policy to histories, payloads, logs, and backups.
- Redact failure messages before they become durable if upstream errors can
  contain secrets.
- Limit fan-out, payload size, start rate, and open executions per tenant so one
  workflow cannot exhaust shared capacity.
