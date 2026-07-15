---
status: done
---

# realtime-updates Specification

## Purpose
The bespoke OR-backed detail pages (Rule / Synchronization / Mapping) stay fresh without manual refresh. The `@conduction/nextcloud-vue` object store (>= 1.0.0-beta.212) installs `liveUpdatesPlugin` default-on in `createObjectStore`, exposing `subscribe(type, id?)` / `unsubscribe(handle)` backed by notify_push with a visibility-gated polling fallback. OpenRegister pushes `or-object-{uuid}` events for per-object changes; events are refetch hints only — the plugin re-runs `fetchObject` through the same store, and pages either render the store cache directly (Mapping) or bridge fresh data into their local draft with a dirty-guard (Rule, Synchronization).

@e2e exclude requires notify_push (or timed-polling) infrastructure plus a second concurrent session mutating the same object; not exercisable deterministically in CI e2e — subscription bookkeeping is covered by the shared library's unit tests for liveUpdatesPlugin

## Requirements

### Requirement: Store instance with live-updates capability
The bespoke detail pages SHALL fetch, save, and subscribe through one app-owned `createObjectStore('openconnector-objects')` instance (`src/store/objectStore.js`). The package's shared `useObjectStore` instance is created without plugins and has no `subscribe()`; splitting fetch and subscription across two store instances would refetch into a cache no page renders.

#### Scenario: fetch and live refetch share one cache
- **GIVEN** a detail page fetched its object through the openconnector object store
- **WHEN** an or-object event triggers the plugin's refetch
- **THEN** the refetched object lands in the same store cache the page renders from

### Requirement: Per-object subscription on bespoke detail pages
WHEN a rule, synchronization, or mapping detail page has loaded its object, the page SHALL subscribe to `or-object-{uuid}` via `objectStore.subscribe(type, uuid)`. Pages holding a local draft (Rule, Synchronization) SHALL apply refetched data to the draft ONLY when the draft is clean — a live event never clobbers unsaved edits. The mapping page renders the store cache directly and needs no bridge.

#### Scenario: sync edited elsewhere refreshes a clean detail page
- **GIVEN** a user has a synchronization detail page open with no unsaved edits
- **WHEN** another session updates that synchronization
- **THEN** the visible fields refresh without a manual reload

#### Scenario: live event never clobbers a dirty draft
- **GIVEN** a user has unsaved edits on a rule detail page
- **WHEN** an or-object event refetches a newer server version
- **THEN** the draft keeps the user's edits and only the store cache updates

### Requirement: Subscription lifecycle guards
Subscriptions SHALL be guarded against leaks (mixin `src/mixins/liveObjectSubscription.js`): an in-flight subscribe is marked pending so the same (type, uuid) is never double-subscribed, an epoch counter invalidates in-flight resolutions after a release (the resolution unsubscribes its own stale handle), and `beforeDestroy` releases the active handle and bridge watcher.

#### Scenario: navigating away releases the subscription
- **GIVEN** a detail page holds a live subscription
- **WHEN** the component is destroyed
- **THEN** the handle is unsubscribed and no further refetches occur
