# Tasks — remove orphaned LogIndex.vue wrapper

## 1. Confirm dead-code scope

- [x] Re-confirmed zero manifest references: `grep -rn '"component": "LogIndex"' src/manifest.json src/manifest.d/*.json` returns nothing.
- [x] Re-confirmed zero JS/Vue importers outside the file itself:
      `grep -rn "LogIndex" src/ --include=*.js --include=*.vue` returned only
      the wrapper's own definition (its `name: 'LogIndex'` and its own docblock).
- [x] Checked `sourceStore.refreshSourceLogs` / `sourceStore.sourceLogs`
      (`src/views/wrappers/LogIndex.vue:51-53`): `grep -rln "refreshSourceLogs\|sourceLogs" src/store/`
      returned NOTHING — these members were never actually defined on
      `sourceStore` in `src/store/store.js`. The wrapper referenced undefined
      store members; had it ever been reachable it would have thrown at
      runtime. No store cleanup needed since there was nothing to remove.

## 2. Delete the dead code

- [x] Deleted `src/views/wrappers/LogIndex.vue`.
- [x] N/A — `sourceStore.refreshSourceLogs` / `sourceLogs` never existed in
      `src/store/store.js` (see task 1).
- [x] Ran `npx eslint src/views/` (0 errors, only pre-existing unrelated JSDoc
      warnings) and `npm run build` (succeeds, only pre-existing bundle-size
      warnings) — no dangling import.

## 3. Spec + issue cleanup

- [x] Updated `openspec/specs/app-shell-and-logs-ui/spec.md` REQ-SHELLUI-003 to
      match observed reality (the manifest's `"type": "logs"` pages resolved by
      nc-vue's `CnLogsPage`, not an integriq-owned wrapper) — applied the
      change's own MODIFIED-requirement delta verbatim, plus a Notes line
      recording why the file was safe to delete.
- [ ] NOT DONE — closing GitHub issue #814 is a live external repo action
      (gh CLI / GitHub API), out of scope for an isolated-worktree code task
      that must not touch anything outside the worktree's file tree. Left for
      a human/PR step.

## Acceptance criteria

- No file in `src/` imports or references `LogIndex.vue`.
- All five log routes (`SourceLogs`, `EndpointLogs`, `JobLogs`,
  `SynchronizationLogs`, `CloudEventLogs`) continue to render correctly via
  the manifest `"type": "logs"` → `CnLogsPage` path (unchanged by this
  change — verify with a manual click-through of each route).
- `openspec/specs/app-shell-and-logs-ui/spec.md` no longer describes
  unreachable code as shipped behavior.
