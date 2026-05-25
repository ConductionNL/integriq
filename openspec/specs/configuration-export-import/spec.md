---
retrofit: true
---

# configuration-export-import Specification

## Purpose

OpenConnector configurations — Sources, Endpoints, Mappings, Rules, Jobs and
Synchronizations — are stored as OpenRegister objects in the `openconnector`
register and identified locally by UUID. Operators need to move a coherent set
of these objects between environments (development → acceptance → production)
without the in-environment identifiers losing meaning. The
`ConfigurationService` and its per-type `ConfigurationHandlers` serialise a set
of related objects into an OpenAPI-Specification-shaped document where
cross-entity references are expressed as human-readable **slugs**, and re-import
that document into a target environment by resolving slugs back to local UUIDs.

This capability documents the **observed behaviour** of the existing
export/import code as of the 2026-05-24 coverage scan. It is a retrofit spec:
no behaviour is changed. Where observed behaviour is fragile or
security-relevant (slug fallback, credential redaction by substring match), the
requirement Notes record it rather than silently "fixing" it. See ADR-015
(slug-translation contract) and ADR-007 (Source credentials stored plaintext)
for the governing decisions.

## Requirements
### Requirement: REQ-001 — Export a configuration set as a slug-referenced OAS document

The system SHALL export every OpenConnector entity tagged with a given
configuration id into an OpenAPI-Specification-shaped array under a top-level
`components` key, grouped by entity type (`sources`, `endpoints`, `mappings`,
`rules`, `jobs`, `synchronizations`) and further organised by component label.
Membership is determined by the entity's own `configurations` array containing
the requested configuration id. Before serialisation the system SHALL build
bidirectional id↔slug maps for all eight translatable types so that
cross-entity references in the export are expressed as slugs rather than local
UUIDs/integer ids (see REQ-004).

Notes: `getEntitiesByConfiguration()` returns the same membership set as a flat
slug-indexed map and is the read path the UI uses to preview a configuration;
`exportConfiguration()` is the serialising path. Both rely on
`findByConfiguration()` / `fetchBySchema()` which fetch the whole schema and
filter in PHP (`in_array($configurationId, $configurations, true)`) — there is
no server-side filter pushdown, so export cost is O(all entities of each type),
not O(members). Flagged for future optimisation, not changed here.

#### Scenario: members of a configuration are exported, non-members excluded

- GIVEN three sources, two endpoints and one mapping whose `configurations` array contains configuration id `cfg-1`
- WHEN `exportConfiguration('cfg-1')` is called
- THEN the returned array contains `components.sources`, `components.endpoints` and `components.mappings` keyed by their slugs
- AND entities not tagged with `cfg-1` are absent

#### Scenario: register/schema targetId is split and slug-translated per half

- GIVEN an endpoint with `targetType = "register/schema"` and `targetId = "12/34"`
- WHEN the configuration is exported
- THEN the exported endpoint's `targetId` is `"<registerSlug>/<schemaSlug>"`, with each half translated independently through its register/schema slug map

#### Scenario: an entity without a slug falls back to its UUID as the key

- GIVEN an entity that has no `slug` field set
- WHEN it is exported
- THEN the export uses the entity's UUID as the slug key, so the export is never keyed by an empty string

### Requirement: REQ-002 — Export every entity transitively reachable from a register

The system SHALL export the dependency closure of a single register: all
endpoints and synchronizations whose `targetId`/`sourceId` reference that
register (optionally filtered by source-side, target-side, or both), plus the
mappings, rules, sources and jobs those endpoints and synchronizations
transitively reference. The system SHALL resolve register/schema id→slug maps
before serialising any entity, and SHALL follow mapping-to-mapping references to
a fixed point so that nested mappings referenced inside other mappings are
included.

Notes: `getEndpointsByTarget()` / `getSynchronizationsByTarget()` match on
`str_starts_with($targetId, $registerId.'/')` or exact equality, so they match
register-level and register/schema-level references but not bare schema
references. `findJobsByArgumentIds()` decodes a string `arguments` field as JSON
before matching — a malformed JSON arguments value silently yields `[]` (no
match), so a job with corrupt arguments is quietly dropped from the export.

#### Scenario: endpoint dependency closure is exported

- GIVEN a register `reg-A` targeted by one endpoint that references an input mapping and two rules
- WHEN `exportRegister('reg-A')` is called
- THEN the export's `components` contains that endpoint, its input mapping, and both rules, each keyed by slug

#### Scenario: synchronizations can be excluded from the closure

- GIVEN `includeSynchronizations = false`
- WHEN `exportRegister()` is called
- THEN no synchronizations are walked or exported
- AND only endpoint-reachable dependencies appear

#### Scenario: nested mapping references are followed to a fixed point

- GIVEN a mapping `m1` that references another mapping `m2` in its config
- WHEN the register is exported
- THEN both `m1` and `m2` appear in `components.mappings` because the export loops on newly-discovered mapping ids until none remain

#### Scenario: jobs referencing exported ids are included

- GIVEN a job whose `arguments` reference an exported synchronization, endpoint, or source id
- WHEN the register is exported
- THEN that job is included
- AND jobs referencing none of the exported ids are excluded

### Requirement: REQ-003 — Import an OAS document in dependency order

The system SHALL import a configuration document by validating that it contains
a top-level `components` key (throwing `InvalidArgumentException` otherwise),
then delegating each present component group to its handler's `import()` in a
fixed dependency order: sources, mappings, rules, endpoints, synchronizations,
jobs. Each handler SHALL resolve slug references back to local UUIDs (REQ-004),
then upsert the entity — updating the existing object when its slug already
resolves to a local UUID, otherwise creating a new object in the `openconnector`
register under the matching schema.

Notes: The slug→id maps are populated once from the *target* environment by
`resetMappings()` at the start of the import; entities created earlier in the
same import run (e.g. a brand-new source) are NOT re-added to the map, so a
later endpoint referencing a *newly created* source by slug will not resolve and
falls back to leaving the slug verbatim (see REQ-004). This is a known
limitation of the current single-pass map, recorded not fixed. Import performs
no schema validation of the per-entity payload beyond the top-level `components`
check — handler `import()` passes the supplied array straight to `saveObject()`,
so OAS documents from untrusted sources MUST be treated as untrusted input by
the caller.

#### Scenario: missing components key is rejected

- GIVEN an OAS array with no `components` key
- WHEN `importConfiguration()` is called
- THEN an `InvalidArgumentException("OAS must contain a components property")` is thrown
- AND nothing is written

#### Scenario: import follows dependency order

- GIVEN an OAS array containing sources, endpoints and synchronizations
- WHEN it is imported
- THEN sources are written before endpoints and endpoints before synchronizations, so that a slug reference from an endpoint to a source resolves against an already-populated map

#### Scenario: existing slug updates in place

- GIVEN an imported endpoint whose `slug` already exists in the target environment
- WHEN it is imported
- THEN the existing object is updated in place (its UUID preserved) rather than a duplicate being created

#### Scenario: unknown slug creates a new object

- GIVEN an imported endpoint whose `slug` does not exist locally
- WHEN it is imported
- THEN a new object is created under the `endpoint` schema

### Requirement: REQ-004 — Translate cross-entity references between ids and slugs

The system SHALL maintain a bidirectional map (`idToSlug` / `slugToId`) for each
of eight types (`endpoint`, `synchronization`, `mapping`, `rule`, `source`,
`register`, `schema`, `job`), rebuilt from the live environment by
`resetMappings()` at the start of every export and import. On export, handlers
SHALL replace local id references with slugs; on import, handlers SHALL replace
slug references with local ids. The translated fields are `targetId` /
`sourceId` (by `targetType`/`sourceType`), `inputMapping`, `outputMapping`,
endpoint `rules[]`, and any nested key inside a rule's `configuration` that
matches an entity-type name or `<type>Id` suffix.

Notes: The verbatim-fallback below means a missing dependency surfaces only
later as a null/dangling FK or a downstream runtime error, never as a
human-readable import-time validation message. ADR-015 records this and proposes
a future pre-import validation pass; this retrofit does not add one.
`RuleHandler::convertIdsToSlugs()` / `convertSlugsToIds()` walk arbitrary nested
arrays and rewrite any key equal to a type name or ending in `<type>Id` whose
value is present in the map — a user-data field that happens to be named like an
entity reference and happens to hold a value colliding with a real id would be
rewritten, an observed aliasing risk recorded not fixed.

#### Scenario: nested rule configuration ids round-trip through slugs

- GIVEN a rule whose nested `configuration` contains `{"sourceId": 7, "register": 3}` and slug maps `7→"my-source"`, `3→"my-register"`
- WHEN the rule is exported
- THEN the exported configuration contains `{"sourceId": "my-source", "register": "my-register"}`
- AND the inverse holds on import

#### Scenario: register/schema pair round-trips

- GIVEN a `register/schema` reference `"12/34"`
- WHEN it is exported and then imported into an environment where the register/schema slugs resolve
- THEN the round trip yields the target environment's local `"<registerId>/<schemaId>"`

#### Scenario: unresolvable slug is left verbatim

- GIVEN an exported slug that does not exist in the target environment's `slugToId` map
- WHEN it is imported
- THEN the reference is left as the verbatim slug string (no exception), producing a dangling reference rather than a hard failure

### Requirement: REQ-005 — Redact source credentials from exported configurations

When exporting a Source, the system SHALL strip credential-bearing fields from
the serialised output — `authorizationHeader`, `auth`, `authenticationConfig`,
`authorizationPassthroughMethod`, `jwt`, `jwtId`, `secret`, `username`,
`password`, `apikey` — and SHALL remove any `configuration` key beginning with
`headers.` whose name contains `authorization`, `token`, `key`, or `secret`
(case-insensitive). The exported Source therefore SHALL NOT contain plaintext
credential values, even though those fields are stored unencrypted in the live
environment (ADR-007).

Notes: Credential stripping on export is the **only** barrier protecting these
secrets, because they are stored as plaintext (ADR-007) and any other API path
returning a full Source still exposes them. The redaction is therefore
security-critical and any new credential-bearing field added to Source MUST be
added to this `unset()` list. The `configuration` header sanitisation matches by
**substring** on the key name (`token`, `key`, `secret`, `authorization`) — a
credential carried under a differently-named header key (e.g.
`headers.X-Api-Cred`) would NOT be stripped and would leak into the export. This
partial-redaction gap is recorded for a future allowlist-based redaction;
behaviour is not changed in this retrofit. Handlers other than Source
(`endpoint`, `mapping`, `rule`, `job`, `synchronization`) do not strip any
fields; if any of those entity types ever carry secrets in their
`configuration`, those secrets are exported verbatim.

#### Scenario: credential fields are stripped on export

- GIVEN a Source with `apikey = "live_xyz"`, `secret = "s3cr3t"` and a `configuration` entry `"headers.Authorization" = "Bearer abc"`
- WHEN the Source is exported
- THEN none of `apikey`, `secret`, or `headers.Authorization` appear in the exported array

#### Scenario: non-sensitive headers are retained

- GIVEN a Source with a non-sensitive `configuration` entry `"headers.Accept" = "application/json"`
- WHEN it is exported
- THEN that header is retained

#### Scenario: imported source has no credentials and needs re-entry

- GIVEN an exported Source document imported into a target environment
- WHEN import runs
- THEN the Source object is created/updated without credential values
- AND an operator must re-enter credentials in the target environment for the Source to authenticate

