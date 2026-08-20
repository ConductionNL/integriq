# Tasks: adopt-live-updates-ui

- [x] 1. Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` and reinstall.
- [x] 2. Create `src/store/objectStore.js` exporting the app-owned `createObjectStore('openconnector-objects')` instance.
- [x] 3. Create `src/mixins/liveObjectSubscription.js` (pending-key + epoch guards, beforeDestroy release, optional `applyLiveObject` bridge).
- [x] 4. RuleDetailPage: swap store import, subscribe after `load()`, dirty-guarded `applyLiveObject` refreshing draft+pristine.
- [x] 5. SynchronizationDetailPage: swap store import, subscribe after `loadObject()`, dirty-guarded `applyLiveObject` refreshing original+draft.
- [x] 6. MappingDetailPage: swap store import, subscribe in `mounted()` (cache-rendered — no bridge needed).
- [x] 7. Add canonical spec `openspec/specs/realtime-updates/spec.md`; annotate touched methods with `@spec`.
- [x] 8. Verify: lint clean on touched files, vitest green, `npm run build` green.
