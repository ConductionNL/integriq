# ADR-003: CallLog is the primary observability surface for outbound HTTP

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

Every outbound HTTP request that integriq makes to a Source goes through
`lib/Service/CallService.php`, which writes a `CallLog` entity for both
success and error paths. The `CallLog` entity (`lib/Db/CallLog.php`) records:

- HTTP request snapshot (`request: array json`) and response snapshot
  (`response: array json`)
- `statusCode`, `statusMessage`
- Linkage to `sourceId`, `actionId`, `synchronizationId`
- Initiator (`userId`, `sessionId`)
- `expires` (defaults to `+1 week` from creation) for retention
- Calculated `size` byte estimate (defaults 4096) so log volume can be reported

In parallel, `SynchronizationLog`, `SynchronizationContractLog`, and `JobLog`
record higher-level execution lifecycles, but each of those layers ultimately
emits one or more `CallLog` rows when an HTTP boundary is crossed.

The Prometheus metrics endpoint (see `openspec/specs/prometheus-metrics/`)
exposes counters derived primarily from `oc_openconnector_call_logs`. The
in-flight `openconnector-register-storage` change deliberately classifies
`call_log`, `job_log`, `synchronization_log`, and `synchronization_contract_log`
as the four **append-only + immutable** schemas, separate from the 11 mutable
config schemas.

## Decision

`CallLog` is the canonical observability record for any outbound HTTP request
issued by integriq. Every code path that issues an HTTP call through
`CallService` MUST produce a `CallLog`; new outbound HTTP integrations MUST go
through `CallService` (not raw Guzzle) so they appear in the call log surface.

CallLogs are write-once and meant to be immutable; future storage migration
will enforce this with OR's `appendOnly: true` + `immutable: true` schema
flags. Until that lands, treat the rows as immutable by convention.

## Consequences

- Adding a new external HTTP integration without going through `CallService`
  silently bypasses observability, metrics, and rate-limit detection. New
  code reviews should flag any raw `GuzzleHttp\Client` use in `lib/Service/`.
- The 1-week default expiry on `CallLog::expires` is intentional: success
  logs are short-lived (high volume, low forensic value); error retention is
  longer (see ADR-004). This default is currently the only retention floor
  for ad-hoc inserts that bypass per-config logic.
- The `request` and `response` arrays are JSON columns — large payloads
  inflate row size. `CallLog::$size` is tracked so admins can spot
  unusually fat logs.
- Cross-reference: ADR-004 (retention) — retention windows act on top of
  this record stream.
- Cross-reference: `openspec/specs/prometheus-metrics/spec.md` — metrics
  derived from CallLog and the sibling log tables.
- Cross-reference: `openspec/changes/openconnector-register-storage/` — the
  migration that enforces immutability declaratively at the storage layer.

## Evidence

- `lib/Db/CallLog.php:9-100` — entity definition with all observed fields.
- `lib/Db/CallLog.php:94-100` — default `expires = +1 week` and
  `calculateSize()` invocation in constructor.
- `lib/Service/CallService.php:33-54` — class-level docblock declaring "logs
  all calls" as the contract.
- `lib/Service/CallService.php:77-97` — constructor wires `CallLogMapper` as a
  required dependency.
- `openspec/changes/openconnector-register-storage/proposal.md:24-32` —
  "Logs become immutable in the storage layer".
