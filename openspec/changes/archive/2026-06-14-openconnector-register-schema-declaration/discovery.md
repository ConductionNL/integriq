# Discovery: openconnector-register-schema-declaration

## Question

Can the full openconnector data model (15 entities, ~250 fields, 6 integer FKs,
4 log entities) be expressed declaratively as a single OpenRegister descriptor
without requiring extensions to OR's `Schema` entity or its `ConfigurationService`
importer? Specifically:

1. Are `appendOnly: true` and `immutable: true` first-class properties of OR's
   `Schema` entity, or do they require a schema extension?
2. Does OR's importer support cross-schema `$ref` within a single register
   document, and does the relation engine pick those up automatically?
3. Does `x-openregister-archival` accept the ISO-8601 retention durations
   needed for the openconnector log windows (1 h, 30 days)?
4. Are there entity fields in `lib/Db/*.php` that cannot be cleanly represented
   in JSON Schema (e.g. computed properties, polymorphic columns, weird casts)?

## Approach Taken

1. Read OR's `lib/Db/Schema.php` to confirm which schema-level properties exist
   as first-class columns. Result: `appendOnly`, `immutable`, `hardValidation`,
   `searchable`, `archive`, `maxDepth`, `owner`, `application`, `organisation`,
   `groups`, `authorization` are all native (see `Schema.php:1289` and
   `Schema.php:1343-1387`).
2. Searched `openregister/lib/` and `openregister/openspec/specs/` for
   `appendOnly` to confirm the spec, the migration (`Version1Date20260511110000`),
   and the runtime check (`AppendOnlyException`) all exist.
3. Inspected pipelinq's `lib/Settings/pipelinq_register.json` (25 schemas, 2,198
   LOC) and procest's `lib/Settings/procest_register.json` (35 schemas, 5,790
   LOC) as reference shapes. Both successfully import via OR's
   `ConfigurationService::importFromApp` and exhibit the `$ref` cross-schema
   relation pattern (e.g. procest line 1860–1866 — `voorstel` field with
   `format: uuid`, `$ref: "voorstel"`, `onDelete: "CASCADE"`).
4. Read all 15 openconnector entities in `lib/Db/`. Catalogued every
   `addType()` call to determine JSON Schema mapping (`string` → `type:string`,
   `integer` → `type:integer`, `json` → `type:object` or `type:array`,
   `boolean` → `type:boolean`, `datetime` → `type:string, format:date-time`).
5. Cross-checked `Synchronization.sourceId` / `targetId` usage across
   `lib/Service/SynchronizationService.php` lines 141–545 to confirm the
   three-form overload (integer PK / register-schema slug / uuid). Confirmed:
   the field is `string`-typed in PHP but receives all three values.
6. Confirmed the docudesk register file (line 682) uses `"immutable": true` on
   the signing-audit-log schema with the surrounding archive metadata — this
   is the exact pattern we need for openconnector's 4 log schemas.

## Findings

### Q1: appendOnly + immutable native

Confirmed. `Schema.php:314` declares `protected bool $appendOnly = false;` and
`Schema.php:478` registers it as a real column via `addType()`. The migration
`Version1Date20260511110000.php` provisioned it on existing installs. The
runtime guard is `OCA\OpenRegister\Exception\AppendOnlyException`. No schema
extension or x-vendor annotation is needed.

`immutable` was already native before `appendOnly` and is exercised by docudesk's
signing-audit-log seed schema.

### Q2: Cross-schema $ref resolves

Confirmed. The procest descriptor uses `$ref: "voorstel"` (bare slug, not a URI)
inside a property definition, accompanied by `format: "uuid"` and
`onDelete: "CASCADE"`. OR's importer resolves these after parsing the full
document — order of schema declarations within the file does not matter. The
relation engine (`AnnotationNotificationDispatcher.php:1095`) picks them up via
the `x-openregister-relations` umbrella annotation, which is derived from the
`$ref`-bearing properties.

### Q3: x-openregister-archival accepts ISO-8601 durations

Partial. The capability table at
`openregister/openspec/platform-capabilities.md:37` lists
`x-openregister-archival` as `implemented` with the spec name
`archival-destruction-workflow`. The exact JSON shape it accepts is not fully
catalogued in a single place, but the `archive` first-class column on `Schema`
(`Schema.php:1355`) is an `array | null` — meaning the importer accepts any
shape and lets the archival workflow interpret it. We will encode retention as
`{"retentionPeriod": "PT1H"}` for success logs and
`{"retentionPeriod": "P30D"}` for error logs (ISO-8601 form), matching the
constants `3,600,000 ms` and `2,592,000,000 ms` respectively. If the archival
workflow expects a different shape we adapt during apply — the descriptor is
re-importable.

### Q4: Entity-to-schema cleanness

All 15 entities map cleanly. Notes per entity:

- **Source** — 6 string columns are encrypted on disk
  (`secret/password/apikey/jwt/username/authenticationConfig`); JSON Schema can
  only describe them as `type:string, format:password`. The actual
  encryption/decryption is invisible to the descriptor.
- **CallLog** — has integer FKs (`sourceId, actionId, synchronizationId`).
  Source/sync target schemas exist; `actionId` is ambiguous (Q2 below).
- **EventMessage** — integer FKs (`eventId, consumerId, subscriptionId`) — all
  three target schemas exist. Clean mapping.
- **Synchronization.sourceId/targetId** — overloaded string field, three
  formats. Declared as `type: string` with explanatory `description`. The
  three-way disjunction is documented prose-style; the storage chain validates
  at write time.
- **SynchronizationContract.originId/targetId** — already-string columns
  holding external-system identifiers (foreign keys to systems we don't own).
  These are NOT openconnector relations; declared as `type: string`.
- **Computed properties**: `getSlug()` falls back from name when slug is null,
  `JobLog::size`/`CallLog::size` are auto-calculated. The descriptor describes
  the stored field shape, not the getter logic, so this is fine — the storage
  chain calls the existing setters which invoke the computation.

## Recommendation

**Proceed.** All 15 schemas are declaratively expressible without OR-side
changes. The chain split (config → code) is the right ADR-032 partitioning.

Open items resolved here:

- ✅ Use `appendOnly: true` + `immutable: true` together on the 4 log schemas.
- ✅ Use `format: uuid` + `$ref: <schema-slug>` for relation fields, mirroring
  procest.
- ✅ Use `x-openregister-archival.retentionPeriod` ISO-8601 string. If shape
  needs adjustment after dev-env import, patch in apply phase.
- ⏳ Leave `actionId` target ambiguous; the storage chain resolves it from call
  sites (DEFERRED Q2).
- ⏳ Keep both `*Id` and target-schema-named fields during transition (DEFERRED
  Q1). The storage chain populates both.

## Risks Uncovered

- **`x-openregister-archival` JSON shape is undocumented in a single canonical
  place.** Risk that our ISO-8601 form is reinterpreted by the archival
  workflow. Mitigation: dev-env round-trip during apply; the descriptor is
  trivially re-importable.

## Next Steps

1. Author the spec file (one capability: `openconnector-register-schema`).
2. Author the migration outline (note that NO openconnector migration class is
   added — the storage chain handles that).
3. Author tasks.md with two implementation tasks: (a) write
   `openconnector_register.json`, (b) write `openconnector_seed_data.json`,
   plus a CI validation task that imports into a dev OR instance.
4. Author test-plan.md covering: importer accepts the file, all 15 schemas
   provision, all FK `$ref`s resolve, seed objects materialise.
