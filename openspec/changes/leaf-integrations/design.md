# Design: leaf-integrations

## Context

OpenRegister's leaf machinery (verified at `origin/development`): ~17 providers under `lib/Service/Integration/Providers/` + built-ins; stage 2 of the three-stage filter reads the schema's linked types (`Schema::getConfiguration()['linkedTypes']`, validated at import against `IntegrationRegistry::listIds()`); stage 3 is the surface — a manifest integration widget on a `type: "detail"` page, or the shared object sidebar.

Integriq verified state:

- Zero integration widgets in `src/manifest.json`; zero `linkedTypes` in `lib/Settings/integriq_register.json` (39 schemas).
- Manifest-driven `detail` pages: **SourceDetail, EndpointDetail, ConsumerDetail, CloudEventDetail** only. `MappingDetail`, `RuleDetail`, `SynchronizationDetail`, `FlowDetail`, `ApprovalDetail`, `TraceDetail`, `DeadLetters` are `type: "custom"` Vue pages — a manifest widget cannot land on them.
- `source` carries plaintext credential properties (`password`, `apikey`, `secret`, `jwt`, `authenticationConfig` — local ADR-007) plus operational state (`circuitBreakerState`, `lastCall`, `status`).

## Goals / Non-Goals

**Goals**
- Anchor incident coordination (documents, follow-up cards, war-room) on the `source` object, and maintenance/cutover planning on the `synchronization` object.
- Zero PHP; the whole leaf surface enumerable from two JSON files.
- An explicit, reasoned OFF list so the next "add a leaf" request starts from recorded rationale.

**Non-Goals**
- Consumer-style leaves on infra objects; page rewrites; leaf-triggered automation; membership sync.

## Decisions

### Decision 1: ON — two schemas, four leaf types, three widgets

| Schema | `configuration.linkedTypes` | Surface | Why |
|---|---|---|---|
| `source` | `["files", "deck", "talk"]` | SourceDetail widgets `src-files` / `src-deck` / `src-talk` | The source is where integrations break. Supplier OAS/PDF docs belong on the connection they describe; incident follow-ups are card-shaped multi-day work; a repeated-failure incident needs one war-room whose context is the failing connection. |
| `synchronization` | `["calendar"]` | Shared object sidebar (no widget — custom page) | Maintenance windows and cutover dates are agreed with suppliers per data flow; today they live in private agendas. Planning-only: the job scheduler (`job.interval`/`nextRun`) remains the execution mechanism, untouched. |

### Decision 2: OFF — everything else, with reasons

- `endpoint`, `consumer`, `event_subscription`, `rule`, `lti_*`, `eudi_*` — credential-bearing or authorization-bearing config; adding link surfaces invites pasting secrets into linked entities ("the supplier's key is in the Deck card"). Refused.
- `mapping`, `job` — code-like configuration; their collaboration artefact is version/export (`configuration.export`), not cards or chats. `job` additionally has no detail page at all (index only, verified).
- All log schemas (`call_log`, `job_log`, `synchronization_log`, …) — evidence records; nothing should be attachable to evidence.
- `sync_item_dead_letter` — a files leaf for exported payload samples is genuinely useful for supplier tickets, but the DeadLetters page is `type: "custom"` and bulk-oriented: no per-object surface exists to render it. **Deferred, not refused** (proposal Out of Scope).
- Leaf types `email`/mail-intake, `contacts`, `forms`, `polls`, `photos`, `maps`, `bookmarks`, `collectives`, `notes`, `shares`, `time-tracker`, `analytics` — no integrator workflow maps to them on infra objects; forcing consumer-style leaves onto the control plane is exactly what this change refuses.

### Decision 3: Leaves never touch source properties

Files/deck/talk/calendar providers link external entities by object id; none reads or writes register properties. On `source` this is load-bearing (Risk 1): the leaf widgets must be pure link surfaces on a page that also shows credentials. REQ-OCL-002 pins it; the e2e test asserts no credential value renders inside a leaf widget.

### Decision 4: Honest surfaces only

The calendar leaf on `synchronization` gets no manifest widget because the manifest cannot reach a custom page — declaring one would be a phantom. Sidebar-only is weaker discoverability (Risk 3) and is documented as such. The alternative — postponing the whole leaf until the page is rewritten — was rejected: the schema declaration is forward-compatible and the sidebar works today.

## Risks / Trade-offs

Carried in proposal.md (credentials adjacency; stale war-rooms; sidebar discoverability).

## Migration Plan

Pure addition; register re-import applies the keys. Rollback = revert.

## Open Questions

Carried in proposal.md (circuit-breaker automation; deck on synchronization later).
