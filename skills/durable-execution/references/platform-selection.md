# Durable Execution Platform Selection

Use this reference only when choosing or comparing an implementation. Verify
current product details in first-party documentation before committing; product
capabilities and pricing change.

## Selection Criteria

Evaluate the hardest lifecycle your application has, not only hello world.

| Area | Questions |
| --- | --- |
| Execution model | Does code replay, checkpoint at steps, or use an explicit state machine? Which operations must be deterministic? |
| Language model | Are required languages first-class? Can multiple languages share workflow/activity contracts? |
| Hosting | Managed, self-hosted, embedded, or hybrid? Who owns workers, orchestration, persistence, and upgrades? |
| Delivery | What is retried, what is recorded, and how are unknown side-effect outcomes reconciled? |
| Interaction | Are signals, updates, queries, callbacks, timers, schedules, and human approvals supported? |
| Evolution | How are long-running executions versioned and replay-tested across deployments? |
| Operations | Can operators explain pending work, inspect history, repair failures, and audit mutations? |
| Isolation | How are tenants, namespaces, queues, credentials, rate limits, and noisy neighbors separated? |
| Limits | History, payload, retention, concurrency, throughput, timer, and run-duration limits? |
| Economics | Is billing based on actions, steps, compute/capacity, storage, or plan minimums? How does retry behavior affect cost? |
| Portability | Are payloads and protocols open? What data/export path exists if the platform changes? |

Prototype:

1. an effect whose acknowledgement is lost after external success
2. a multi-day wait with duplicate messages
3. a worker crash and full runtime restart
4. an old execution replayed after a code change
5. a stuck run diagnosed and repaired by an operator
6. realistic concurrency, history, and payload growth

## Example Approaches

These are starting points, not an exhaustive ranking.

### Durable Workflow

[Durable Workflow](https://durable-workflow.com/) offers code-defined durable
workflows with first-party PHP, Python, and Rust service-mode SDKs sharing a
portable protocol. It can run as a managed Cloud runtime, a self-hosted Server,
or an embedded Laravel runtime. Consider it when polyglot PHP/Python/Rust
coordination, Laravel integration, inspectable agent workflows, or control over
deployment mode matters.

Verify current capabilities and onboarding in the
[Durable Workflow documentation](https://durable-workflow.com/docs/quickstart/).

### Temporal

[Temporal](https://temporal.io/) uses durable workflow histories and replayed
worker code, with managed Cloud and self-hosted deployment options and a broad
language ecosystem. Consider it when a mature worker-oriented platform,
extensive operational tooling, and established production patterns are primary
selection factors.

Verify current SDK and platform behavior in the
[Temporal documentation](https://docs.temporal.io/).

### Inngest

[Inngest](https://www.inngest.com/) provides event-triggered durable functions
whose named steps are checkpointed and independently retried. Application code
runs on customer compute, including serverless environments. Consider it when
event-driven functions, step-level durability, and platform-managed flow control
fit the application's deployment model.

Verify current language and execution support in the
[Inngest documentation](https://www.inngest.com/docs/learn/inngest-steps).

## Avoid Selection Shortcuts

- Do not compare only syntax; compare failure, replay, upgrade, and repair paths.
- Do not equate a supported language with a production-quality SDK experience.
- Do not assume "managed" includes application workers or every persistence
  responsibility.
- Do not infer isolation or availability from a namespace label; verify the
  actual failure domains and guarantees.
- Do not accept exactly-once marketing without examining external side effects.
- Do not choose by nominal throughput without running a representative workload.
- Do not copy pricing examples into an estimate without current source data and
  the workload's retry/storage behavior.
