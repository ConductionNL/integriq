---
kind: code
depends_on: [zgw-connectors-for-dossiq]
---

# Proposal: connectors-inavigator-case-types

## Summary

A municipality keeps its case types and products in i-Navigator and wants
them in the case type catalogue without retyping, with every custom
attribute. When i-Navigator changes, an administrator wants to see what
would change before it lands. This change adds an i-Navigator case type
import that writes into the case type schema an operator picks, and makes
every gated synchronization show its change set, object by object, on the
approval screen before anyone accepts it.

## Why

This change covers two rows.

- `integriq:con-inavigator`, "Import case types and products from
  i-Navigator into the case type catalogue", rated no and none. Demand:
  tender https://www.tenderned.nl/aankondigingen/overzicht/226100 (Gemeente
  Stein). The matrix note: "Gemeente Stein requirements 166838 and 166851:
  i-Navigator content must be importable into the ZTC with unlimited case
  attributes." No competitor rates yes.
- `integriq:nl-zgw-resync`, "Preview and accept the changes when case types
  are synchronised again from their source", rated no and none. No
  competitor rates yes and no demand row. It rides with
  `integriq:con-inavigator`, because a re-import of i-Navigator content is
  exactly the re-synchronisation this row asks to preview. The matrix lists
  `opencatalogi:svc-resync` as its sibling row.

## What integriq already has

- A packaged Catalogi API set, `lib/Settings/configurations/zgw-catalogi.json`,
  read only, `storageStrategy: external`, target register and schema chosen
  by the operator. It names a synchronization `zgw-catalogi-pull` and a
  mapping `zgw-zaaktype-to-object`, and `git grep` finds neither defined
  anywhere else at this sha. Its change, `zgw-connectors-for-dossiq`, has
  every task open. That is why this change depends on it.
- Test runs that write nothing: `synchronization-engine` REQ-011,
  `SynchronizationService` `isTest` (`lib/Service/SynchronizationService.php:2316`).
- A batch approval gate before target writes: `synchronization-engine`
  REQ-015. When `sourceConfig.requiresApproval` is true, the run pauses
  before its write loop (`SynchronizationService.php:2436` to :2472) and
  `ApprovalService::suspendForSynchronization()`
  (`lib/Service/ApprovalService.php:227`) stores an `approval_request` with an
  empty `snapshot` (:242).
- The approval screen, `src/views/Approvals/ApprovalDetail.vue`, which shows a
  request's method and path from `snapshotPreview` (:50) and nothing about a
  synchronization's objects.
- `grep -ri navigator lib/ src/` finds only the browser `navigator` API.

## What this change builds

1. An i-Navigator case type import: a source template, a synchronization and
   a mapping onto the case type schema the operator picks, carrying every
   custom attribute as a case type property rather than a fixed list.
2. A change set on every gated synchronization run: for each object, created,
   changed with the fields that differ, or removed, stored on the approval
   request.
3. The change set on the approval screen, and an accept that writes exactly
   what was previewed.

## Out of scope

- The case type catalogue itself. dossiq serves the ZTC over OpenRegister;
  integriq writes into the schema the operator picks and owns no case type.
- Accepting some objects and rejecting others in one run. REQ-015 gates a
  batch, and this change keeps it a batch.
- Writing back to i-Navigator. A catalogue is maintained where it is
  published, as the `zgw-catalogi` set's own description says.

## Sibling half

dossiq's half is the target: a case type schema that holds the imported
attributes. dossiq builds nothing new if its case type schema accepts custom
properties; the mapping writes onto whichever schema the operator picks.
`opencatalogi:svc-resync` is OpenCatalogi's own row for its own
re-synchronisation, and this change's preview applies to any gated
synchronization, including one OpenCatalogi configures in integriq.
