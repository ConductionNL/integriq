# Flow-native synchronization — retire the black box and the legacy surfaces

## Why

The flow editor consolidation (2026-08-19) put one flow editor over the fleet,
and it exposed the seam this change closes: `openconnector.synchronization-run`
is a **black box**. A flow author sees one card, its dialog offers a bare
synchronization uuid, and everything that actually happens — pagination,
mapping, contracting, writing, logging — is invisible and untunable from the
flow. That defeats the point of flows: the process IS the product, and the
process is hidden.

At the same time Integriq still carries four standalone surfaces — Jobs,
Rules, Mappings, Synchronizations — that predate the flow engine and duplicate
what flows now express: a Job is a schedule trigger, a Rule is an
object-trigger plus a switch, a Mapping is a step's configuration, and a
Synchronization is a flow that has not been drawn yet.

## What changes

1. **The synchronization decomposes into flow steps.** The canonical sync is a
   drawn flow: *trigger → fetch page → map page → contract page → bulk save →
   commit contracts → next page → stale sweep → end*. Each step delegates to
   the EXISTING services (`CallService`, `MappingService`,
   `SynchronizationContractService`, `ObjectService::saveObjects()`), which
   are **kept, not rewritten** — the black box's walls come off, its machinery
   stays.
2. **Bulk and parallel work is placed deliberately** (see `design.md`): fan-out
   at PAGE level never object level, concurrent page fetches through the
   existing bounded `FlowConcurrency` pool, one contract lookup and one bulk
   save per page.
3. **Node dialogs open the real editors.** A node referencing a
   synchronization/mapping/source opens the surface that can actually edit it
   (with the node's own fields alongside), via a per-node-type editor registry
   in `@conduction/nextcloud-vue`; and the GENERIC dialog gains reference
   PICKERS so no config field is ever a bare uuid text box again.
4. **The legacy pages deprecate, the code does not.** Jobs, Rules, Mappings
   and Synchronizations pages get deprecation banners and "open as flow"
   paths, then retire once migration completes. The services and entities
   behind them become the engines of the new steps.
5. **Existing synchronizations migrate by generation**: each Synchronization
   entity renders into a generated, reviewable flow — the same precedent
   `flow-engine-unification` task 6.2 set for legacy `steps[]` flows.
   `openconnector.synchronization-run` remains as the bridge and deprecates
   when the last generated flow is adopted.

## Impact

- Affected: integriq (nodes, pages, migration generator), openregister
  (bulk mode on `object-write`, `configFields` catalogue evolution),
  nextcloud-vue (node-editor registry, reference pickers).
- The CKAN 2000-dataset benchmark flow is the performance yardstick: the
  decomposed flow must meet or beat the black-box run before the bridge node
  deprecates.
- ADR-065 (one flow engine) and ADR-096 (index → detail surfaces) govern;
  ADR-022 (apps consume OR abstractions) is why the bulk save is
  `ObjectService::saveObjects()` and not a new writer.
