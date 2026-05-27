# Retrofit — configuration-export-import

Describes the observed behaviour of 35 methods under the
`configuration-export-import` cluster as 5 new REQs. The code already exists —
this change retroactively specifies it. New capability `configuration-export-import`.

## Why

The configuration export/import subsystem ships in production but had no spec.
Bringing it under the ADR-008 annotation convention makes its behaviour —
including security-relevant credential redaction and several fragile fallbacks —
explicit, traceable, and reviewable, and registers it in the Specter retrofit
cohort.

## What Changes

- ADDED: a new `configuration-export-import` capability with 5 numbered REQs
  describing the observed export, register-export, import, slug-translation, and
  credential-redaction behaviour.
- ADDED: `@spec` annotations on all 35 cluster methods pointing at the tasks.
- No code behaviour changes — annotations and spec only.

## Affected code units

`lib/Service/ConfigurationService.php`
- `exportConfiguration()`, `getEntitiesByConfiguration()`, `findByConfiguration()`,
  `fetchBySchema()`, `organizeEntitiesByComponent()`, `getEntityComponent()`,
  `exportSource()`, `exportEndpoint()`, `exportMapping()`, `exportRule()`,
  `exportJob()`, `exportSynchronization()` → REQ-001
- `exportRegister()`, `buildRegisterAndSchemaMappings()`, `getEndpointsByTarget()`,
  `getSynchronizationsByTarget()`, `findJobsByArgumentIds()`, `findByUuids()` → REQ-002
- `importConfiguration()` → REQ-003
- `resetMappings()`, `buildSchemaSlugMaps()` → REQ-004

`lib/Service/ConfigurationHandlers/`
- `EndpointHandler::export()/import()`, `JobHandler::export()/import()`,
  `MappingHandler::export()/import()`, `RuleHandler::export()/import()/convertIdsToSlugs()/convertSlugsToIds()`,
  `SynchronizationHandler::export()/import()` → REQ-003 (import) + REQ-004 (translation)
- `SourceHandler::export()` → REQ-005 (credential redaction); `SourceHandler::import()` → REQ-003

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behaviour (not aspirational)
- Notes sections surface observed-but-suspicious behaviour:
  - Slug-not-found falls back to verbatim slug → dangling FK, never a validation error (REQ-003/004)
  - Single-pass import map does not see entities created earlier in the same run (REQ-003)
  - No per-entity schema validation on import — array passed straight to `saveObject()` (REQ-003)
  - Source credential redaction is the only barrier given plaintext storage (ADR-007); header sanitisation is substring-based and can miss non-standard credential header names (REQ-005)
  - Non-Source handlers strip nothing — secrets in their config would export verbatim (REQ-005)

## Security notes

Configuration import/export is security-sensitive. Reviewed for the three
classic risks:
- **Unsafe deserialization**: none — the document is array/JSON only; no
  `unserialize()`, no object instantiation from the payload. `json_decode` is
  used on a string `arguments` field with assoc=true (array, not object).
- **Secret leakage**: handled on export by `SourceHandler::export()` redaction;
  flagged as the *only* barrier (ADR-007 plaintext) and as substring-fragile
  for non-standard header names. Non-Source handlers do not redact.
- **Path traversal**: none — no filesystem paths are derived from the document;
  all writes go through OpenRegister `saveObject()` into the `openconnector`
  register.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
