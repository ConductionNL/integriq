---
kind: spec-only
depends_on: []
---

# Proposal: nextcloud-event-hub (superseded — re-scoped 2026-09-02)

This directory double-counted a change that had already shipped. The event
hub was implemented and archived on 2026-07-15
(`archive/2026-07-15-nextcloud-event-hub`, 30/43 tasks checked with per-task
evidence), yet this live copy was left standing at 0/43. The machinery exists
at HEAD: `NextcloudFileEventListener`, `NextcloudFileTagEventListener`,
`NextcloudCalendarEventListener`, `NextcloudTablesEventListener` and
`NextcloudFormsEventListener` in `lib/EventListener/`, the `jsonlogic` filter
dialect in `EventService::evaluateFilters`, the subscription-level `action`
(synchronization | job | webhook) and `retryPolicy` fields, and the
per-family self-service actions in the ADR-023 matrix.

Since then the outbound side has also grown past this change's framing: the
ADR-041 delivery seam (#1810) put `DeliveryRequestedEvent` /
`DeliveryConcludedEvent` into the same CloudEvents pipeline, and sibling-app
deliveries (dossiq publications) now arrive there.

57 `@spec` tags in `lib/`, `src/` and `tests/` point at this directory's
three delta specs (`specs/nextcloud-event-triggers/spec.md`,
`specs/events-cloudevents/spec.md`, `specs/dead-letter-replay/spec.md`), so
the whole `specs/` tree stays exactly where it is as anchor targets. The
other artifacts (context brief, design, discovery, migration, test plan) are
removed; they survive verbatim in the archived twin and in git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| NC file/calendar/Tables/Forms listeners, CloudEvents normalization, availability gating, `jsonlogic` filter dialect, subscription `action` and `retryPolicy`, self-service family actions (tasks 1, 3-11, 13-14) | **Already shipped and archived**: `archive/2026-07-15-nextcloud-event-hub`, code at HEAD |
| Task 2 spike (Tables/Forms event class names never confirmed against a live instance), Playwright for the family-grant + self-service-subscribe flow (task 12) and the action-type picker (task 13's open Test box), Newman for subscribe-with-action/retryPolicy and the 403 paths, feature docs, screenshot | `openspec/changes/nextcloud-event-hub-verification` — **first shippable slice**, fully authored. The spike comes first: two of the five listener families ship against unverified class names, which is a silent no-op if they are wrong |
| Outbound sibling-app deliveries | Landed as the **ADR-041 delivery seam** (#1810); the dossiq scope continues in `openspec/changes/absorb-dossiq-deliveries` (7/11) |
| Event-driven orchestration beyond sync/job/webhook | `openspec/changes/nc-events-start-or-flows`: a matched subscription starts an OpenRegister flow run, so NC events reach the one engine instead of growing a fourth app-local action kind. No app-local scheduler or workflow machinery |

## Sequencing

`nextcloud-event-hub-verification` is independent and ready to hand to an
agent today. `nc-events-start-or-flows` SHOULD wait for
`integriq-flow-nodes` to land so a triggered flow has call/sync nodes worth
running. Nothing remains to implement from this change directly.

## Archival

This directory is retired in place (not moved or renamed): its `specs/` tree
is a live `@spec` anchor target for 57 tags, and a rename would both break
those tags and detonate every diff-scoped gate. Archive it via the normal
flow only after those tags are repointed at the main specs.
