# Changelog

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
