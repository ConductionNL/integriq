---
kind: code
---

# Proposal: Adopt nc-vue Live Updates on the Bespoke Detail Pages

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 installs `liveUpdatesPlugin` default-on in `createObjectStore` (lazy until the first `subscribe()`), and OpenRegister pushes `or-object-{uuid}` / `or-collection-{register}-{schema}` events for all OR-backed objects — which, post chain-C cutover, is every OpenConnector entity. But the app consumed the package's **shared** `useObjectStore` instance, which is created without plugins and has no `subscribe()`, so an open rule/synchronization/mapping editor never noticed changes made in another session.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- New `src/store/objectStore.js`: app-owned `createObjectStore('openconnector-objects')` (liveUpdatesPlugin default-on). The three bespoke detail pages swap their `useObjectStore` import to it so fetch, save, and live refetches share one store instance.
- New `src/mixins/liveObjectSubscription.js`: per-object subscription with the reference guard set from OpenRegister's ObjectDetails (pending-key marker, epoch counter, `beforeDestroy` release, optional cache→draft bridge watcher).
- `RuleDetailPage.vue` + `SynchronizationDetailPage.vue`: subscribe after load; `applyLiveObject` refreshes draft+pristine/original **only when clean** — live events never clobber unsaved edits.
- `MappingDetailPage.vue`: subscribe after load; renders the store cache directly, so reactivity alone re-renders (no bridge).

## What Does NOT Change

- Generic manifest `index`/`detail`/`logs` pages (Sources, Endpoints, Consumers, Webhooks, Jobs, CloudEvents, …) are rendered by CnPageRenderer against the shared plugin-less store inside nc-vue; manifest JSON cannot carry a store instance into `CnDetailPage`'s `objectStore` prop. Live updates there are renderer-side work in nc-vue.
- `EventDeliveriesPage` / `SyncDeadLetterPage` use bespoke `/apps/openconnector/api/*` endpoints (not OR objects) — no OR push events exist for them.

## Impact

- Frontend only; OpenRegister already pushes the events. Without notify_push the plugin degrades to visibility-gated polling; subscribe failures are warn-logged, never rendered as errors.
- New canonical spec: `openspec/specs/realtime-updates/spec.md`.
