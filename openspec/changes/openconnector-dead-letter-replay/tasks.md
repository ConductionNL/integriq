# Tasks — dead-letter queue inspection and replay

## 1. Schema

- [ ] 1.1 Add `replayedBy`, `replayedAt`, `discardedBy`, `discardedAt` to the
  `event_message` schema in `lib/Settings/openconnector_register.json`; extend
  the `status` description with `discarded`
- [ ] 1.2 Validate the register JSON still parses

## 2. Service layer

- [ ] 2.1 `EventService::replayMessage(string $id, string $actorUid)` — state
  guard (`failed`/`abandoned` only), reset to pending + audit stamps, preserve
  `attempts[]`, immediate `deliverMessage`
- [ ] 2.2 `EventService::discardMessage(string $id, string $actorUid)` — state
  guard, terminal `discarded` + audit stamps, `nextAttempt=null`
- [ ] 2.3 Exclude `discarded` from the `processRetries` selection (assert via
  unit test; the retry-hardening selection already excludes non-pending/failed)

## 3. REST surface

- [ ] 3.1 Controller methods: `deadLetterIndex`, `deadLetterShow`, `replay`,
  `discard`, `bulkReplay`, `bulkDiscard` — admin-gated (no `@NoAdminRequired`),
  CSRF intact (no `@NoCSRFRequired`)
- [ ] 3.2 Register all six routes in `appinfo/routes.php` (route-reachability
  gate: every method routed, every route resolvable)
- [ ] 3.3 Bulk endpoints: 100-id cap (400 over cap), per-id outcome map,
  partial-failure isolation

## 4. UI

- [ ] 4.1 "Event deliveries" sub-view in the Events section: filtered list,
  status badges, pagination, loading + empty states
- [ ] 4.2 Detail modal in its own file under `src/modals/` (modal-isolation
  gate): payload viewer, attempt timeline, Replay/Discard with confirmation
- [ ] 4.3 Bulk selection + bulk Replay/Discard with confirmation and per-item
  result feedback
- [ ] 4.4 nl + en translations for all new UI strings (English source keys)

## 5. Tests

- [ ] 5.1 PHPUnit: replay/discard state guards (409 matrix), audit stamping,
  attempts[] preservation, bulk partial outcomes, sweep exclusion of
  `discarded`
- [ ] 5.2 Newman: dead-letter list/detail/replay/discard/bulk round trip,
  admin-gate rejection for non-admin
- [ ] 5.3 Playwright (gate-19): Event deliveries view scenarios from
  REQ-DLR-006 (inspect+replay, bulk discard confirmation, empty state)

## Acceptance criteria

- An operator can go from a `delivery-retries-exhausted` notification to a
  resolved (replayed-and-delivered or discarded) message entirely through the
  UI, with `replayedBy`/`discardedBy` recorded.
- No DLQ endpoint is reachable by a non-admin user or without a CSRF token.
- Discard never deletes; discarded messages stay queryable.
- Depends on `openconnector-event-retry-hardening` being applied first (the
  `abandoned` state and `attempts[]` it surfaces).
