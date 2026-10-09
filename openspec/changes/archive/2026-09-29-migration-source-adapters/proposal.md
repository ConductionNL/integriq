---
kind: code
depends_on: []
---

# Proposal: migration-source-adapters

## Summary

A gemeente replacing its zaaksysteem migrates the running dossiers, not a
spreadsheet. Integriq holds the sources a migration reads from: an adapter
per named incumbent, and a file with a column mapping an administrator
stores and reuses. OpenRegister holds the import engine, its preview and its
conflict policy.

## Motivation

Round 4 discovery, cluster 8, "Migration in and migration out"
(`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Five candidates, every one of
them `must`, fifteen passers, thirteen driven and two documented, proving
system openproject. Four matrix holes, the most of any cluster in the sweep.

The cluster's owner is **openregister**, size L, and its mechanism line gives
integriq one half by name: "extend openregister import and export with a
preview and a conflict policy; **integriq holds the source adapters**". This
change is that half and nothing more.

| candidate | relevance | dossiq | owner of the work | in this change |
|---|---|---|---|---|
| C-configuration-88, a supported migration path out of a named competing product | must, matrix hole | no | integriq, the source adapters | yes |
| C-configuration-16, bulk import from a file with a column mapping | must, matrix hole | no | integriq, the file source and its stored mapping | yes |
| C-configuration-95, preview and conflict resolution before an import runs | must, matrix hole | partial | openregister, the engine | the read-only pass the preview needs |
| C-integrations-50, whole-instance export and import | must | no | openregister | no |
| C-integrations-22, a restorable dump written before permanent destruction | must, matrix hole | no | openregister | no |

A supported migration path is **number 2 of the twenty-five loudest** in
`_round4/discovery/found-and-lacking.md`, and number 3 of the ten strongest
capabilities the competition has: "3. **A supported migration path out of a
named competing product**, eight driven. dossiq has none." Bulk import from a
file with a column mapping is number 13 of the same list, with two driven
passers.

The evidence, verbatim from `_round4/discovery/candidates.json`:

- C-configuration-88, `configuration.tsv:57`, clause: "a gemeente replacing a
  zaaksysteem migrates the running dossiers, not a spreadsheet". Eight driven
  passers: Forgejo, Gitea, GitLab, Kanboard, OpenProject, OTOBO, Vikunja and
  Zammad, plus Easy Redmine documented. The proving evidence: "openproject:
  Administration Import, /admin/import/jira, resources :jira with post :test,
  twenty jobs under app/workers/import/ including jira_revert_import_job.rb".
  dossiq: "no (grep -rilE 'DecosImport\|MozardImport\|migrateFrom' lib src
  returns nothing)". The lane's note: "All four move an existing system's
  records into this one as a supported path."
- C-configuration-16, `configuration.tsv:41`, clause: "this is the migration
  and the bulk correction in one screen, and no row in 268 asks for either".
  Passer: "otobo: Import and export (AdminImportExport.pm,
  DynamicFieldImportExport.pm, imexport_ seven tables, the free
  ImportExportTicket package)". dossiq: "no (grep -rilE
  'importMapping\|csvImport' lib src returns nothing)" and "partial,
  OpenRegister import and export; no stored mapping object".
- C-configuration-95, `configuration.tsv:48`, clause: "it is the same dry-run
  argument as xxllnc's bulk simulation, on the more dangerous operation".
  dossiq: "partial, import exists via OpenRegister; no preview or conflict
  step found".

Two `revert` details in the passers are worth naming, because they are what
separates a migration from an import. OpenProject ships
`jira_revert_import_job.rb`, and its import path has a `post :test` before it
runs. A supported path is one you can rehearse and undo.

## What integriq builds and what dossiq consumes

Integriq builds the readers. dossiq consumes nothing directly: it receives
migrated cases through OpenRegister, in the shape it already reads.

- An adapter reads a running incumbent and yields records in a declared
  shape. It never writes, anywhere.
- A file source reads a delivered file through a stored column mapping an
  administrator authored once and reuses.
- Every yielded record carries the identifier and the source it had in the
  system it came from, so a second run matches rather than duplicates.

## What this change does not build, and who does

- **The import engine, the preview and the conflict policy.** Openregister,
  cluster 8. `configuration-export-import` already ships REQ-007, preview an
  import before writing anything, and REQ-008, import requires explicit
  confirmation after preview, for integriq's own configurations. Records are
  a different store and a different owner.
- **Whole-instance export and import**, C-integrations-50, and **the
  restorable dump before destruction**, C-integrations-22. Both openregister.
- **The foreign identity key itself.** C-integrations-11 sits in cluster 26
  and is already specified in `registry-backed-field-source`, which defines
  the provenance a resolved value carries. This change reuses that shape
  rather than defining a second one.

## The existing specs this extends

- `source-management`: a migration source is a source, tested from the
  Sources screen, with a mock-mode fixture like the BRP and KvK seeds.
- `synchronization-engine`, REQ-002 source fetching and pagination, REQ-008
  per-item isolation, REQ-009 fetch-completeness tracking and REQ-011 test
  runs make no writes. A migration is a read with a very large page count,
  and fetch completeness is exactly the guard a half-read migration needs.
- `mapping-and-search` and `mapping-editor-ui`: the column mapping is a
  mapping, authored in the surface that already exists.
- `configuration-export-import`, REQ-005 credential redaction and REQ-009 the
  re-entry flag: a migration source's credentials follow the same rule.
- `registry-backed-field-source`, REQ-RFS-003: the provenance shape a
  migrated record's foreign identity reuses.

## Size and dependencies

Size M for integriq's half. The cluster is L and openregister carries the
engine. The build plan makes the cluster depend on "export as its own right",
cluster 16, which is openregister's and does not gate a reader.

## Out of scope

- Writing. An adapter reads; the engine writes.
- Migration out. C-integrations-50 is openregister's, and this change adds no
  export.
- A conflict policy. Openregister owns what happens when a record already
  exists; integriq reports that it might.
