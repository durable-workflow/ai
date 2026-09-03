# Changelog

## Unreleased

- Publish the vendor-neutral `durable-execution` agent skill for recognizing,
  designing, and operating long-running fault-tolerant processes.
- Add deterministic local loss injection outside tool dispatch so recovery
  demonstrations cannot journal and replay a destructive injection effect.
- Keep a requested retained snapshot workflow-owned until successful completion
  exposes its ID, deleting it instead when the workflow fails.

## 2.0.0-rc.8

- Reconcile snapshot creation retries by a deterministic workflow operation ID
  without changing the V1 sandbox provider method boundary.

## 2.0.0-rc.7

- Restore the original V1 sandbox provider method boundary and expose snapshot
  deletion through a separately versioned, capability-gated extension.

## 2.0.0-rc.6

- Recover confirmed sandbox loss while suspending or resuming by reconstructing
  the last proven state within the bounded recovery budget.

## 2.0.0-rc.5

- Document and verify the explicit Durable Workflow prerelease requirement for
  Composer roots that keep the default `stable` minimum stability.

## 2.0.0-rc.4

- Return a missing E2B file as a failed tool result without starting sandbox
  recovery, while preserving recovery for genuine sandbox loss.

## 2.0.0-rc.3

- Stop advertising E2B suspend/resume until paused sandboxes have an
  independently durable cleanup deadline.

## 2.0.0-rc.2

- Delete workflow-owned sandbox snapshots after safe checkpoint replacement and
  terminal cleanup, with explicit caller-owned retention.
- Add idempotent snapshot deletion to the provider contract and the E2B and
  local providers.

## 2.0.0-rc.1

- Publish the versioned sandbox provider and lifecycle contracts.
- Add deterministic operation IDs and completed post-snapshot state reconstruction.
- Add bounded leases, idempotent cleanup, and explicit provider capabilities.
- Add E2B HTTP and development-only local subprocess providers.
