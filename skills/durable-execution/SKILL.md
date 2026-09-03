---
name: durable-execution
description: Recognize, design, implement, and operate long-running processes that must survive failures, retries, restarts, delays, callbacks, or human input. Use for payments, provisioning, onboarding, imports, approvals, external API coordination, autonomous agents, and when deciding between ordinary code, queues, cron, state machines, or a durable workflow engine.
license: MIT
metadata:
  author: durable-workflow
---

# Durable Execution

Design from failure boundaries, not from a preferred product. The goal is a
process whose durable state outlives any request, worker, deployment, or host
that happens to execute it.

## Recognize The Problem

Consider durable execution when a process has one or more of these properties:

- It crosses multiple independently failing systems or side effects.
- It must wait minutes, days, or months without holding a process open.
- A crash must resume progress instead of restarting from the beginning.
- Retries must not duplicate charges, messages, infrastructure, or records.
- A callback, signal, approval, or external event determines what happens next.
- Operators need to inspect, cancel, repair, or otherwise influence a live run.
- Partial completion requires compensation rather than a database rollback.
- Existing runs must remain correct while code is deployed and changed.

Duration alone is not decisive. A five-second payment flow may need durability;
a two-hour disposable batch calculation may not. Ask whether correctness state
must survive a lost process and whether the process crosses irreversible failure
boundaries.

When uncertain, imagine a crash after every side effect and before every
acknowledgement. If reconstructing what happened would require guesses, the
process needs durable state somewhere.

## Choose The Smallest Adequate Mechanism

| Mechanism | Use when | Limit |
| --- | --- | --- |
| Request/response | Work is short, bounded, and can fail with the request | No progress after disconnect or process loss |
| Ordinary async/future | Concurrency is local to one live process | In-memory state disappears with the process |
| Job queue | One independently retryable background operation is enough | Multi-step progress and waits become application bookkeeping |
| Cron/scheduler | Time is the trigger and each run is independent | It is not per-instance orchestration |
| Database state machine | States are few, transitions are explicit, and the team wants to own polling, locking, retries, and repair | Operational machinery grows with every failure mode |
| Durable workflow | Progress spans time or services and must resume, wait, retry, compensate, and remain inspectable | Adds a runtime, replay rules, and history lifecycle |

Do not introduce a workflow engine merely because code has several functions.
Use one when it removes meaningful recovery state and failure handling that the
application would otherwise have to build and operate.

## Establish The Process Contract

Before choosing APIs, write down:

1. **Identity:** What business key identifies a run? What should a duplicate
   start do?
2. **Invariants:** What must never happen twice? What may happen at least once?
3. **Durable state:** Which decisions and outputs are needed after a restart?
4. **Side effects:** Where can the outside world change independently?
5. **Waits:** Which timers, callbacks, approvals, or messages resume progress?
6. **Deadlines:** Which step, attempt, and overall process timeouts apply?
7. **Failure policy:** Which failures retry, pause for intervention, compensate,
   or terminate?
8. **Operations:** What must a user or operator be able to see and change?
9. **Evolution:** How will old runs behave after a deployment?
10. **Retention:** How much history and payload data can the process create?

Model explicit terminal states such as completed, failed, cancelled, and
compensated. "The worker stopped" is not a business state.

## Separate Orchestration From Effects

Workflow code owns durable control flow. Keep it deterministic and replayable:

- branching and loops based on durable inputs and recorded results
- calls to activities, tasks, child workflows, timers, and durable waits
- compensation order and process-level state transitions
- handling signals, updates, cancellation, and deadlines

Activities or tasks own interaction with the nondeterministic world:

- network, database, filesystem, and subprocess calls
- random values, UUIDs, wall-clock time, environment reads, and secrets
- payment, email, infrastructure, browser, and LLM operations
- CPU-heavy work that should not block workflow-task execution

Do not call an API and then record that it happened as two unrelated operations.
The process can crash between them. Let the durable runtime record the scheduled
effect, and make the effect safe to retry.

### Replay Discipline

Workflow code may execute again to reconstruct state. During replay:

- Never perform an unrecorded side effect.
- Never branch on current time, randomness, mutable globals, live configuration,
  or a fresh database/API read.
- Use runtime-provided deterministic time, randomness, side effects, and version
  markers where supported.
- Treat recorded activity results and received messages as immutable history.
- Keep logs and metrics replay-aware so replay does not duplicate telemetry.

Fetching mutable external state in an activity is valid. Persist the returned
snapshot as history and make the workflow decision from that recorded result.
Fetch again explicitly when a fresh decision is required.

## Make Every Effect Retry-Safe

Assume an activity can complete externally while its acknowledgement is lost.
Most systems therefore provide at-least-once attempts, not magical exactly-once
effects.

For each effect:

- Derive an idempotency key from stable workflow/run/step identity.
- Pass that key to providers that support idempotency.
- Use unique constraints, upserts, compare-and-set, or an application receipt
  table when the provider does not.
- Return and persist the provider's operation identifier.
- Reconcile ambiguous timeouts before issuing the effect again.
- Make cleanup and compensation idempotent too.

Classify errors before retrying:

| Failure | Typical response |
| --- | --- |
| Timeout, rate limit, transient network/service error | Bounded retry with backoff and jitter |
| Invalid input, forbidden request, missing permanent configuration | Fail without retry or await correction |
| Unknown outcome after submission | Reconcile by idempotency key or provider operation ID |
| Business rejection | Record the outcome and follow business policy; do not disguise it as infrastructure failure |
| Exhausted transient retries | Pause, compensate, or fail visibly according to the process contract |

Set schedule-to-start, start-to-close, heartbeat, and overall deadlines according
to the actual operation. A retry count without a time budget can outlive the
business deadline.

## Model Interaction Explicitly

- **Signals/messages** report external facts and may arrive more than once.
  Deduplicate with stable message IDs and define behavior for early or late
  arrival.
- **Updates/commands** request a validated mutation and should report whether it
  was accepted.
- **Queries** inspect state and must not mutate it.
- **Timers** represent durable time. Do not keep a thread or process sleeping.
- **Cancellation** is cooperative and should allow bounded cleanup.
- **Termination** is an emergency stop; do not assume normal cleanup runs.

Correlate callbacks to workflow identity plus a one-time or versioned token.
Authenticate the sender, persist receipt, and make callback handling idempotent.

## Handle Partial Completion Deliberately

A saga is a sequence of committed local effects with compensations. It is not an
ACID transaction across services.

- Register compensation intent before or atomically with the forward effect.
- Compensate in the business-defined order, often reverse completion order.
- Retry compensation independently and expose failures to operators.
- Prefer semantic compensation such as refund or release over pretending an
  external action never happened.
- Do not compensate a step whose outcome is still unknown; reconcile it first.

Use `finally`-style workflow cleanup for best-effort lifecycle work, but retain a
separate lease/reaper mechanism for resources that must eventually be reclaimed
after termination or runtime loss.

## Bound Growth And Concurrency

- Use child workflows for independently owned lifecycles, isolated retry/cancel
  policy, or large fan-out.
- Apply explicit concurrency limits; durable fan-out can overwhelm downstream
  systems just as easily as ordinary concurrency.
- Use continue-as-new, history rollover, or an equivalent feature for perpetual
  or high-message workflows.
- Keep large payloads outside history in durable object storage and record an
  immutable reference plus integrity metadata.
- Treat task queues as routing boundaries, not as business identity.

## Evolve Running Workflows Safely

Old executions may replay code written months ago. Before changing workflow
control flow:

- Determine whether the engine pins code, versions workflows, or records patch
  markers.
- Keep old handlers available until no compatible runs remain, or migrate with a
  supported reset/continue-as-new strategy.
- Make additive payload changes and define defaults for missing fields.
- Test replay against representative production histories before deployment.
- Separate backward-compatible worker rollout from irreversible data migration.

Never assume redeploying new code rewrites durable history.

## Operate The Process As A Product

Every run should expose:

- stable workflow and run identity plus the business correlation key
- current status and meaningful business phase
- pending timer, message, activity, or child workflow
- attempt count, last failure, and next retry time
- timestamps and age in the current phase
- worker/task-queue availability when relevant
- cancellation, compensation, and terminal outcome

Diagnose from durable history before retrying or mutating anything. Prefer
documented control APIs for signal, update, cancel, retry, reset, or terminate;
do not edit runtime persistence directly.

Alert on symptoms that require action: overdue dispatch, retry exhaustion,
stalled waits beyond business deadlines, growing queue latency, failed
compensation, and history/payload growth. A merely long-running workflow is not
itself unhealthy.

For deeper design and operational checklists, read
[references/design-patterns.md](references/design-patterns.md) and
[references/operations.md](references/operations.md) when those concerns are
part of the task.

## Durable Supervision For AI Agents

Use a durable workflow to supervise an autonomous agent when the session spans
multiple tool calls, sandboxes, approvals, budgets, or restarts.

- Put every LLM call and tool call in an activity/task. Model output is
  nondeterministic and must not run inside replayed orchestration code.
- Persist only the result needed for the next durable decision; place large
  transcripts and artifacts in external storage with immutable references.
- Give tool calls stable operation IDs and enforce idempotency at effect
  boundaries.
- Bound iterations, elapsed time, spend, and parallelism. A durable infinite
  loop is still an infinite loop.
- Use signals/updates for human approval and cancellation, with explicit timeout
  and rejection paths.
- Snapshot sandbox state when useful, but design for sandbox loss and restore.
- Keep credentials scoped to activities and sandbox providers, never durable
  history.
- Ensure resource cleanup has both workflow-level finalization and an external
  lease expiry/reconciler.

The workflow supervises intent and lifecycle; the agent remains an unreliable,
nondeterministic participant.

## When Not To Use A Workflow Engine

Do not use one when:

- A single database transaction provides the required atomicity.
- One short, idempotent queued job with ordinary retries is sufficient.
- Work is stateless streaming or high-throughput transformation with no
  per-instance lifecycle to recover.
- The operation is latency-critical and adding a durable scheduling boundary
  provides no correctness value.
- Losing and recomputing the work is cheaper and simpler than persisting it.
- A small explicit state machine is already easy to own and operate.
- The team cannot support the runtime or honor its determinism/versioning model,
  and the process does not justify that cost.

Do not force all application logic into workflows. Keep ordinary request
handling, pure computation, projections, dashboards, and domain services in
their natural boundaries.

## Select An Implementation

Evaluate engines by execution model, language/runtime support, deployment and
data ownership, isolation, limits, observability, versioning, testing, and cost.
Do not choose from a feature checklist alone; prototype the hardest wait,
failure, replay, and upgrade path.

Read [references/platform-selection.md](references/platform-selection.md) when
selecting or comparing products. It includes vendor-neutral criteria and
starting points for Durable Workflow, Temporal, and Inngest.

## Validate The Design

Before calling the process ready, prove these cases:

1. Crash the worker after an external effect but before acknowledgement.
2. Restart on another worker and confirm the process resumes correctly.
3. Deliver the same callback or signal twice and out of order.
4. Let a transient failure recover, then exhaust retries on a permanent failure.
5. Cancel during an activity and during a durable wait.
6. Fail a compensation and recover it operationally.
7. Deploy changed workflow code and replay old histories.
8. Run enough fan-out and history growth to reach realistic limits.
9. Verify secrets and sensitive payloads do not appear in history or logs.
10. Confirm operators can explain and safely resolve a stuck run.

The proof should exercise real restart and persistence boundaries, not only an
in-memory unit test.
