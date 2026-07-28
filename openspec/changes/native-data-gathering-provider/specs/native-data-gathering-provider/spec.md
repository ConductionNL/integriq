# native-data-gathering-provider — Delta: OpenConnector as the fleet's scheduled gather layer

## Purpose

Positions OpenConnector as the fleet's native "get this / crawl that" data
gathering provider: a scheduled Source (fetch) plus Mapping (transform) plus
Synchronization (keyed-upsert into an OpenRegister register/schema) plus Job
(OpenConnector cron) is the sanctioned replacement for a Specter `sync_*.py`
ingestion script. Establishes the gather contract, formalizes the live-verified
connectors as shipped app fragments, draws the boundary against OpenRegister's
per-object leaf data-providers, and governs how a connector blocked on a
not-yet-built capability must behave. Data gathering runs on OpenConnector's own
cron and has no flow-engine dependency; flows react to the objects it writes
through OpenRegister events.

@e2e exclude backend and configuration governance surface — covered by connector
import/run verification and PHPUnit, no dedicated OpenConnector browser-UI flow

## ADDED Requirements

### Requirement: Scheduled gather pipeline replaces a Specter ingestion script

A data-gathering connector MUST be expressed as one OpenConnector Source, one or
more Mappings, one Synchronization, and one Job, all persisted as OpenRegister
objects in register `openconnector`. The Source SHALL fetch an external
HTTP/REST/file endpoint; a Mapping SHALL transform the fetched payload; the
Synchronization SHALL upsert each mapped record into an OpenRegister
register/schema target declared by a `targetType` of `register/schema` and a
`targetId` naming the target register and schema as one slash-joined string (for
example the string `spectr/tender`); and the Job SHALL run the Synchronization on
a recurring interval using OpenConnector's own cron. Re-running the same
Synchronization MUST reuse the same target objects rather than creating
duplicates, keyed on the synchronization contract identity (`synchronizationId`
plus `originId`) with `sourceHash` change-detection. A connector MUST NOT require
any flow-engine component to fetch, transform, upsert, or schedule.

#### Scenario: A connector fetches, maps, upserts, and self-schedules

- **GIVEN** a connector bundle whose Source targets a public REST endpoint, whose
  Synchronization carries `targetType: "register/schema"` and
  `targetId: "spectr/tender"`, and whose Job carries a non-zero `interval` and
  `isEnabled: true`
- **WHEN** OpenConnector's cron reaches the Job's `nextRun`
- **THEN** the Synchronization fetches the endpoint, maps each record, and upserts
  it into the `tender` schema of the `spectr` register
- **AND** the run needs no flow-engine trigger, node, or runtime

#### Scenario: Re-run does not duplicate

- **GIVEN** a Synchronization that has already populated its target on a first run
- **WHEN** the same Synchronization runs again on its next cron tick
- **THEN** the same target objects are reused (matched on `synchronizationId` plus
  `originId`) and no duplicate objects are created

### Requirement: Gathering connectors ship as register.d fragments

Each formalized connector MUST be shipped inside OpenConnector as an ADR-037
`register.d` fragment at `lib/Settings/register.d/` carrying a `$comment` and a
`components.objects` array, where every object declares an `@self` triplet of
register, schema, and slug. On `occ app:enable` or upgrade the fragment SHALL be
folded into the OpenConnector register and materialised idempotently by slug, so
a fresh install self-provisions the connector without a manual import step. A
connector object that is live-fixed after first import MUST carry a bumped
`version`, because the importer skips an object whose incoming `version` is less
than or equal to the existing one.

#### Scenario: Fresh install self-provisions a Wave-0 connector

- **GIVEN** a fresh OpenConnector install that ships the TenderNed connector as a
  `register.d` fragment
- **WHEN** the app is enabled
- **THEN** the TenderNed Source, Mapping(s), Synchronization, and Job exist as
  OpenRegister objects keyed by their slugs, with no operator import step

#### Scenario: A live-fixed connector object bumps its version

- **GIVEN** an already-imported connector Mapping at `version: "1.0.0"` that is
  corrected in the shipped fragment
- **WHEN** the corrected fragment is re-imported without a version bump
- **THEN** the importer skips it (incoming version not greater than existing)
- **AND** bumping the corrected object's `version` is required for the fix to take

### Requirement: Bulk-ingestion sources are distinct from transport-only leaf sources

The system MUST keep two roles of an OpenConnector `source` distinct. A
bulk-ingestion source SHALL always carry a Synchronization and a Job and pull
many records onto a schedule into a register/schema target. A transport-only
source SHALL carry neither a Synchronization nor a Job and exists solely as the
HTTP transport for an OpenRegister per-object leaf provider making a single live
call. A per-object leaf provider (for example a live company or person lookup, or
a read-time projection of one object) MUST NOT be modelled as a bulk-ingestion
connector, and a scheduled bulk gatherer MUST NOT be modelled as a per-object
leaf provider. Both write to OpenRegister, but a gatherer persists many keyed
rows on a cron while a leaf augments or serves one already-addressed object at
read time.

#### Scenario: A source with a Synchronization and Job is a gatherer

- **GIVEN** a `source` object that is referenced by a Synchronization which is run
  by a Job on an interval
- **WHEN** its role is classified
- **THEN** it is a bulk-ingestion gatherer and its records land as many objects in
  a register/schema target

#### Scenario: A source without a Synchronization or Job is leaf transport

- **GIVEN** a `source` object (for example the BRP HaalCentraal seed) referenced
  by an OpenRegister leaf provider for a single live per-object call, with no
  Synchronization and no Job
- **WHEN** its role is classified
- **THEN** it is transport-only and does not gather or persist bulk rows itself

### Requirement: Gathered objects react through OpenRegister events

Gathered records MUST land as ordinary OpenRegister objects so that
OpenRegister's existing object-created and object-updated events fire on write,
letting downstream flows and agents react through the flow-engine's
Nextcloud-native trigger set without any coupling from the flow engine back into
the gather layer. The gather layer MUST NOT depend on, import, or embed any
flow-engine trigger, node, or runtime to emit these events. An on-demand fetch
MAY be requested through the existing synchronization run endpoint
(`POST /apps/openconnector/api/synchronizations/{id}/run`); wiring that endpoint
to a flow trigger is out of scope for this change and is named only as a forward
hook.

#### Scenario: A gathered row emits an object event a flow can react to

- **GIVEN** a Synchronization that upserts a new record into its register/schema
  target
- **WHEN** the object is written
- **THEN** OpenRegister emits an object-created event for it
- **AND** a downstream flow may react to that event with no change to the
  connector

#### Scenario: The run endpoint is the deferred on-demand hook

- **GIVEN** the existing `POST /synchronizations/{id}/run` endpoint
- **WHEN** an on-demand "gather now" capability is wanted by the flow engine
- **THEN** that endpoint is the sanctioned hook-in point
- `@e2e exclude` forward-hook note; the flow-side wiring is or#2068's scope, not built here

### Requirement: A connector blocked on an unbuilt capability ships disabled

A connector MUST ship with its Source and its Job `isEnabled: false` and a
`$comment` documenting the gap whenever its source shape needs a capability
OpenConnector does not yet have — for example CSV parsing, multi-member `.zip`
archives, offset or cursor pagination beyond page one, incremental
since-watermark fetch, N+1 two-hop detail fetch, wildcard `resultsPosition`, a
post-fetch loop transform, JS-rendered SPA rendering, or streaming of very large
files — rather than shipping enabled and silently yielding zero objects forever.
The missing capability SHALL be tracked as its own follow-up change before the
connector is enabled. A connector whose upstream is not reachable by any
unattended service account (for example a national-eID login wall, or a dead
legacy API) MUST NOT be shipped enabled and SHOULD be documented as a dead
tendril.

#### Scenario: A CSV-only source ships disabled with a documented gap

- **GIVEN** a connector whose only feed is a CSV file, which the engine cannot
  parse today
- **WHEN** the connector is shipped
- **THEN** its Source and Job are `isEnabled: false`
- **AND** its `$comment` records the CSV-parser gap and the follow-up change that
  will close it

#### Scenario: A live-verified connector may ship enabled

- **GIVEN** a keyless public connector whose fetch, map, upsert, and re-run dedup
  have been verified end-to-end
- **WHEN** it is shipped
- **THEN** it may ship with its Job `isEnabled: true`
