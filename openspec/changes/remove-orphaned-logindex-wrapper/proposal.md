---
kind: code
depends_on: []
---

## Why

`src/views/wrappers/LogIndex.vue` is dead code. Its own docblock claims:

> "Manifest entries reference this wrapper via `component: "LogIndex"` and pass
> `config: { logType: "source" | "endpoint" | "job" | "synchronization" | "event" }`.
> ... As of this commit only `logType: "source"` is wired up. The other four log
> routes are tracked in issue #814 as a follow-up."
> (`src/views/wrappers/LogIndex.vue:6-18`)

But grep across `src/manifest.json` and `src/manifest.d/*.json` finds **zero**
page entries with `"component": "LogIndex"` — none of the five log pages
(`SourceLogs`, `EndpointLogs`, `JobLogs`, `SynchronizationLogs`,
`CloudEventLogs`) reference it. All five are declared as `"type": "logs"`
pages with a plain `{ register, schema }` config (e.g.
`src/manifest.json` `SourceLogs` → `{"register": "openconnector", "schema":
"call_log"}`), which `@conduction/nextcloud-vue`'s generic
`CnLogsPage` component (`nextcloud-vue/src/components/CnLogsPage/CnLogsPage.vue`)
resolves declaratively per the ADR-036 universal widget manifest — reading
directly from the OR schema, not from an app-owned pinia store.

`LogIndex.vue` is never imported anywhere in `src/` outside its own file (confirmed:
`grep -rn "LogIndex" src/ --include=*.js --include=*.vue` returns only the
wrapper's own definition). Its single wired configuration,
`sourceStore.refreshSourceLogs` / `sourceStore.sourceLogs`
(`src/views/wrappers/LogIndex.vue:51-53`), also has zero other call sites in
`src/` — the entire data path from component to store method is unreachable at
runtime.

The retrofit change `openspec/changes/retrofit-2026-05-25-app-shell-and-logs-ui/`
documents this component's behavior as REQ-SHELLUI-003 ("Log index viewer") as
if it is live, shipped behavior — it is not reachable from any route today.
The retrofit is a closed, retroactive-annotation change (all tasks checked;
"retroactive annotation of already-existing code, not new implementation
work") and does not track completing or removing this component, so the
current spec text is stale relative to the actual manifest wiring.

## What Changes

- Delete `src/views/wrappers/LogIndex.vue` (confirmed orphaned — no manifest
  entry, no other importer).
- Remove the now-unused `sourceStore.refreshSourceLogs` / `sourceStore.sourceLogs`
  plumbing if `src/store/store.js`'s `sourceStore` has no other consumer of
  those members after the wrapper is deleted (verify at implementation time —
  the log-fetch action may still be shared with other source-detail views; if
  so, keep the store action and only delete the dead Vue wrapper).
- Update `openspec/specs/app-shell-and-logs-ui/spec.md` REQ-SHELLUI-003 to
  either (a) remove the "Log index viewer" requirement entirely since
  `CnLogsPage` (a shared nc-vue component, not an integriq-owned one) now
  serves this role, or (b) retitle it to describe the actual manifest
  `"type": "logs"` wiring, whichever the spec owner prefers at implementation
  time.
- Close tracking issue #814 ("wrapper rollout to other 4 log types") as
  resolved-by-supersession — the rollout target (`CnLogsPage` via the manifest)
  already covers all five log routes; no further per-`logType` wiring is
  needed in this app.
- **No BREAKING changes** — the component being removed has no live route, so
  no user-visible behavior changes.

## Impact

- `src/views/wrappers/LogIndex.vue` — deleted.
- `src/store/store.js` — `sourceStore.refreshSourceLogs` / `sourceLogs` removed
  if confirmed unused elsewhere at implementation time.
- `openspec/specs/app-shell-and-logs-ui/spec.md` — REQ-SHELLUI-003 revised or
  removed to match observed (not aspirational) behavior.
- GitHub issue #814 — closed as resolved-by-supersession.
