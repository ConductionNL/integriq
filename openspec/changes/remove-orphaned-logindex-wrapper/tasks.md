# Tasks — remove orphaned LogIndex.vue wrapper

## 1. Confirm dead-code scope

- [ ] Re-confirm zero manifest references: `grep -rn '"component": "LogIndex"' src/manifest.json src/manifest.d/*.json` returns nothing.
- [ ] Re-confirm zero JS/Vue importers outside the file itself:
      `grep -rn "LogIndex" src/ --include=*.js --include=*.vue` returns only
      the wrapper's own definition.
- [ ] Check whether `sourceStore.refreshSourceLogs` / `sourceStore.sourceLogs`
      (`src/views/wrappers/LogIndex.vue:51-53`) are used by any other view
      (e.g. a source-detail logs tab). If yes, keep the store members; if no,
      remove them too.

## 2. Delete the dead code

- [ ] Delete `src/views/wrappers/LogIndex.vue`.
- [ ] Remove `sourceStore.refreshSourceLogs` / `sourceLogs` from
      `src/store/store.js` if step 1 confirms no other consumer.
- [ ] Run the frontend build / lint to confirm no dangling import.

## 3. Spec + issue cleanup

- [ ] Update `openspec/specs/app-shell-and-logs-ui/spec.md` REQ-SHELLUI-003 to
      match observed reality (the manifest's `"type": "logs"` pages resolved by
      nc-vue's `CnLogsPage`, not an openconnector-owned wrapper).
- [ ] Close GitHub issue #814 with a comment explaining resolution-by-supersession.

## Acceptance criteria

- No file in `src/` imports or references `LogIndex.vue`.
- All five log routes (`SourceLogs`, `EndpointLogs`, `JobLogs`,
  `SynchronizationLogs`, `CloudEventLogs`) continue to render correctly via
  the manifest `"type": "logs"` → `CnLogsPage` path (unchanged by this
  change — verify with a manual click-through of each route).
- `openspec/specs/app-shell-and-logs-ui/spec.md` no longer describes
  unreachable code as shipped behavior.
