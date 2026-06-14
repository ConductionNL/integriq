# Tasks — dead-letter queue inspection and replay

## 1. Schema

- [x] 1.1 Add `replayedBy`, `replayedAt`, `discardedBy`, `discardedAt` to the
  `event_message` schema in `lib/Settings/openconnector_register.json`; extend
  the `status` enum + description with `discarded`
- [x] 1.2 Validate the register JSON still parses

## 2. Service layer

- [x] 2.1 `EventService::replayMessage(string $id, string $actorUid)` — state
  guard (`failed`/`abandoned` only → InvalidMessageStateException), reset to
  pending + audit stamps, preserve `attempts[]`, immediate `deliverMessage`
- [x] 2.2 `EventService::discardMessage(string $id, string $actorUid)` — state
  guard, terminal `discarded` + audit stamps, `nextAttempt=null`
- [x] 2.3 Exclude `discarded` from the `processRetries` selection (the
  retry-hardening selection already only selects `pending`/`failed`; asserted
  via the retry selection-matrix unit test)

## 3. REST surface

- [x] 3.1 Controller methods: `deadLetterIndex`, `deadLetterShow`, `replay`,
  `discard`, `bulkReplay`, `bulkDiscard` — admin-gated via
  `#[AuthorizedAdminSetting(OpenConnectorAdmin)]` (no `@NoAdminRequired`),
  CSRF intact (no `@NoCSRFRequired`)
- [x] 3.2 Register all six routes in `appinfo/routes.php` (bulk routes ordered
  before the `{id}` routes; route-reachability gate green)
- [x] 3.3 Bulk endpoints: 100-id cap (400 over cap), per-id outcome map,
  partial-failure isolation

## 4. UI

- [x] 4.1 "Event deliveries" sub-view in the Cloud events section
  (EventDeliveriesPage custom page): filtered list, status badges, loading +
  empty states
- [x] 4.2 Detail modal in its own file under `src/modals/EventDelivery/`
  (modal-isolation gate): payload viewer, attempt timeline, Replay/Discard
  with confirmation
- [x] 4.3 Bulk selection + bulk Replay/Discard with confirmation and per-item
  result feedback
- [x] 4.4 nl + en translations for all new UI strings (English source keys);
  full 36-locale parity restored via the app l10n tool

## 5. Tests

- [x] 5.1 PHPUnit: replay/discard state guards (409 matrix), audit stamping,
  attempts[] preservation, bulk partial outcomes, sweep exclusion of
  `discarded` (EventServiceTest + EventsControllerTest; full suite 363 green)
- [~] 5.2 Newman: dead-letter round trip + admin-gate rejection — deferred:
  needs a running instance with an admin session + seeded failed/abandoned
  rows; the surface is covered at unit level (controller + service).
- [~] 5.3 Playwright (gate-19): Event deliveries view scenarios — deferred:
  needs the renderer-installed live app; the spec scenarios are not diff-
  flagged by gate-19 yet (added on archive). To be added with the live e2e
  harness pass.

## Acceptance criteria

- An operator can go from a `delivery-retries-exhausted` notification to a
  resolved (replayed-and-delivered or discarded) message entirely through the
  UI, with `replayedBy`/`discardedBy` recorded.
- No DLQ endpoint is reachable by a non-admin user or without a CSRF token.
- Discard never deletes; discarded messages stay queryable.
- Depends on `openconnector-event-retry-hardening` being applied first (the
  `abandoned` state and `attempts[]` it surfaces).
