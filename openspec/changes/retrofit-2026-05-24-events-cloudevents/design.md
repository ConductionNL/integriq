# Design — Retrofit events-cloudevents

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`EventService` (494 LOC, 10 methods) is the producer/dispatcher for integriq's
CloudEvents implementation, and `EventsController` (313 LOC, 7 methods) is the REST
surface. Together they implement: (a) OR object lifecycle → CloudEvent conversion,
(b) subscription filter matching (types/source/filters with exact/prefix/suffix/
expression dialects), (c) push delivery with status tracking, and (d) pull-style
cursor pagination.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `EventService::processEvent`, `doesEventMatchSubscription`, `evaluateFilters`, `createEventMessage` |
| REQ-002 | `EventService::deliverMessage`, `processRetries` |
| REQ-003 | `EventService::pullEvents` + `EventsController::pull` |
| REQ-004 | `EventService::handleObjectCreated`, `handleObjectUpdated`, `handleObjectDeleted` |
| REQ-005 | `EventsController::messages`, `subscribe`, `updateSubscription`, `unsubscribe`, `subscriptions`, `subscriptionMessages` |

`EventsController::pull` is folded into REQ-003 (cursor pagination is its
distinguishing behaviour) rather than REQ-005, so the controller annotation for
`pull` points at task-3 not task-5.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `EventsController::subscribe/update/unsubscribe/subscriptions/messages/subscriptionMessages` | `@NoAdminRequired` + no per-object auth gate — any authed user owns every subscription | **high** (IDOR) |
| `EventsController::subscribe/updateSubscription/unsubscribe` | `@NoCSRFRequired` on state-changing endpoints | high |
| `EventService::evaluateFilters` | `ExpressionLanguage::evaluate` runs caller-supplied strings against event data; subscription owner = caller | high |
| `EventService::processRetries` | `retryCount` is read but never written — pending messages re-attempt forever | medium (bug) |
| `EventService::deliverMessage` | no Retry-After honour | low |
| `EventService::handle*` | `userId` reflects object owner, not session actor | low (audit trail accuracy) |
| `EventService::pullEvents` | `id > $cursor` filter relies on monotonic UUID ordering | low |
| `EventsController::subscribe` | shallow `_*` strip; any non-prefixed field passes through | low |

The IDOR finding (REQ-005 Notes) is the most consequential — every endpoint should
either be admin-gated or guarded by a per-subscription owner check. Two of the three
gates the security reviewer will hit are pattern matches for
`hydra-gate-no-admin-idor` and the IDOR/CSRF combo.

## What the spec deliberately does NOT cover

- The OR `event_subscription` / `event_message` / `event` schemas themselves —
  those are openregister-side artifacts, not integriq behaviour.
- Background job scheduling — `processRetries` is a service method here; the cron
  job wrapper (if any) lives elsewhere (cluster `job-scheduling`, Wave 5).
- CloudEvents 1.0 spec compliance — this spec captures what the code produces, not
  what the CloudEvents spec mandates. Drift between the two would be a separate
  hardening change.

## Validation

After archive, `openspec validate events-cloudevents --strict` MUST pass and Specter
MUST register the spec as part of the retrofit cohort.
