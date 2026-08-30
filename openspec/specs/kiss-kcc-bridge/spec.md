# kiss-kcc-bridge Specification

## Purpose
TBD - created by archiving change kiss-kcc-bridge. Update Purpose after archive.
## Requirements
### Requirement: Klantinteracties provider abstraction with log and REST bindings (REQ-001)

Integriq MUST define a `KlantinteractiesProviderInterface`
(`lib/Service/Kiss/KlantinteractiesProviderInterface.php`) with
`getProviderId()`, `getConfigSchema()`, `listKlantcontacten(sourceConfiguration,
since, pageSize)`, `createKlantcontact(sourceConfiguration, payload)`, and
`linkOnderwerpobject(sourceConfiguration, klantcontactId, caseReference,
caseObjectType)`. A source's `configuration.provider` (`log`|`rest`) selects
the binding at runtime — mirroring `SmsProviderInterface` /
`PeppolAccessPointProviderInterface`. `log` MUST remain usable with no
configuration and MUST be the default when `configuration.provider` is
absent. `rest` (`KlantinteractiesClient`) MUST authenticate every request
with `Authorization: <scheme> <token>` (default scheme `Token`), decrypting
`configuration.authentication.encryptedToken` via `OCP\Security\ICrypto`
in-process for the instant needed to build the header — never logged, never
persisted decrypted.

#### Scenario: the interface is the single seam for adding a compatible alternative backend
- GIVEN a future alternative VNG-Klantinteracties-compatible backend
- WHEN it implements `KlantinteractiesProviderInterface`
- THEN it SHALL be selectable via `configuration.provider` with no change to `KissSyncService` or `KissController`
- @e2e exclude backend provider seam — covered by PHPUnit

#### Scenario: the log provider pulls nothing without a network call or secret
- GIVEN a KISS source with `configuration.provider: log` (or absent)
- WHEN `listKlantcontacten()` is called
- THEN an empty page (`items: [], nextCursor: null`) SHALL be returned with no outbound HTTP call and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the REST provider sends the expected auth header and expansion params
- GIVEN a KISS source with `configuration.provider: rest` and a valid `encryptedToken`
- WHEN a klantcontacten list call is dispatched
- THEN the request SHALL carry `Authorization: Token <decrypted-token>` and query params `expand=betrokkenen,onderwerpobjecten`, `sorteer=registratiedatum`
- @e2e exclude backend provider binding — covered by PHPUnit

### Requirement: Pull sync of klantcontacten with a persisted cursor (REQ-002)

The system MUST run a scheduled `KissPullJob` (hourly `TimedJob`,
`allowParallelRuns=false`) that, for every active KISS source
(`type=kiss`, `isEnabled=true`), pulls klantcontacten changed since a
persisted cursor (`source.configuration.cursor.lastRegistratiedatum`) and
upserts each as a `kiss_klantcontact` OR record keyed by the KISS `uuid` —
idempotent create-or-update, never a duplicate for an already-seen id. A
single malformed/unpersistable klantcontact within a page MUST be logged and
skipped WITHOUT aborting the rest of the page or preventing the cursor from
advancing to the page's maximum `registratiedatum`. A source without an
active KISS configuration MUST be a clean no-op — the job MUST NOT throw or
log an error.

#### Scenario: new and changed klantcontacten are upserted and the cursor advances
- GIVEN a KISS source with a persisted cursor
- WHEN the pull sweep runs and the provider returns a page of new/changed klantcontacten
- THEN each SHALL be upserted as a `kiss_klantcontact` record (new `uuid` → create, already-seen `uuid` → update in place)
- AND the source's cursor SHALL advance to the page's maximum `registratiedatum`
- @e2e exclude backend cron sweep — covered by PHPUnit

#### Scenario: one failing record does not abort the sweep or block the cursor
- GIVEN a page containing one klantcontact that fails to persist (e.g. a missing `uuid`)
- WHEN the pull sweep processes the page
- THEN the failing record SHALL be logged and skipped
- AND every other record in the page SHALL still be processed
- AND the cursor SHALL still advance to the page's maximum `registratiedatum`
- @e2e exclude backend per-record isolation — covered by PHPUnit

#### Scenario: an unconfigured KISS bridge no-ops cleanly
- GIVEN no active KISS source is configured
- WHEN `KissPullJob` runs
- THEN it SHALL complete with 0 records processed, no thrown exception, and no error-level log entry
- @e2e exclude backend no-op path — covered by PHPUnit

### Requirement: Mapping onderwerpobjecten to a case reference (REQ-003)

Every persisted `kiss_klantcontact` MUST derive `caseReference` and
`caseObjectType` from the klantcontact's expanded `onderwerpobjecten`: the
first entry whose `onderwerpobjectidentificator.codeObjecttype`
case-insensitively contains the substring `zaak` MUST populate
`caseReference` from its `objectId` and `caseObjectType` from its raw
`codeObjecttype`. When no onderwerpobjecten are present, or none match, both
fields MUST be persisted `null` — the raw `onderwerpobjecten` array MUST
still be preserved verbatim regardless. Any `betrokkenen[].
partijIdentificator` whose `codeSoortObjectId` is `bsn` (case-insensitive)
MUST have its `objectId` SHA-256-hashed before storage — a raw BSN MUST
never be persisted.

#### Scenario: a valid zaak onderwerpobject maps to a case reference
- GIVEN a klantcontact whose onderwerpobjecten include one with `codeObjecttype: "zaak"` and `objectId: "<uuid>"`
- WHEN the record is persisted
- THEN `caseReference` SHALL equal that `objectId` and `caseObjectType` SHALL equal `"zaak"`
- @e2e exclude backend mapping logic — covered by PHPUnit

#### Scenario: a missing onderwerpobject leaves the case reference null
- GIVEN a klantcontact with no onderwerpobjecten
- WHEN the record is persisted
- THEN `caseReference` and `caseObjectType` SHALL both be `null`
- @e2e exclude backend mapping logic — covered by PHPUnit

#### Scenario: a foreign onderwerpobject is not misattributed as a case
- GIVEN a klantcontact whose only onderwerpobject has `codeObjecttype: "partij"`
- WHEN the record is persisted
- THEN `caseReference` and `caseObjectType` SHALL both be `null`
- AND the raw onderwerpobjecten array SHALL still be preserved on the record
- @e2e exclude backend mapping logic — covered by PHPUnit

#### Scenario: a raw BSN is never stored
- GIVEN a klantcontact whose betrokkenen include a `partijIdentificator` with `codeSoortObjectId: "bsn"` and a raw BSN value
- WHEN the record is persisted
- THEN the stored `objectId` SHALL be the SHA-256 hash of the raw value, never the raw value itself
- @e2e exclude backend AVG guard — covered by PHPUnit

### Requirement: Push endpoint registering a klantcontact and linking a case (REQ-004)

The system MUST expose `POST /api/kiss/klantcontacten` as an authenticated
NC-session endpoint (mirrors `NotifyNlController::send()`) that: validates
`onderwerp` and `kanaal` are present (400 `missing_fields` otherwise), calls
the active KISS source's provider to create the klantcontact, and — when the
request includes a `caseReference` — links it to that case via
`linkOnderwerpobject()`. The endpoint MUST persist a local `kiss_klantcontact`
mirror record (`direction=pushed`) and return `{id, localUuid}` where `id` is
the KISS-assigned klantcontact id. When no active KISS source is configured
the endpoint MUST return HTTP 503 `not_configured` — never a 500 crash. Any
other provider failure MUST return HTTP 502 with a secret-free message.

#### Scenario: a valid push with a case reference creates and links
- GIVEN an authenticated caller and an active KISS `rest` source
- WHEN `POST /api/kiss/klantcontacten` is called with `{onderwerp, kanaal, caseReference: "<case-uuid>"}`
- THEN the provider's `createKlantcontact` SHALL be called, followed by `linkOnderwerpobject` with that case reference
- AND the response SHALL be `{id: "<kiss-id>", localUuid: "<local-uuid>"}`
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: a push without a case reference never calls the linking method
- GIVEN an authenticated caller and an active KISS source
- WHEN `POST /api/kiss/klantcontacten` is called without `caseReference`
- THEN the klantcontact SHALL still be created
- AND `linkOnderwerpobject` SHALL NOT be called
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: an unconfigured KISS bridge reports 503 cleanly
- GIVEN no active KISS source is configured
- WHEN `POST /api/kiss/klantcontacten` is called with valid required fields
- THEN the response SHALL be HTTP 503 with `error: "not_configured"` — never a 500 crash
- @e2e exclude backend not-configured path — covered by PHPUnit

#### Scenario: missing required fields are rejected before any provider call
- GIVEN an authenticated caller
- WHEN `POST /api/kiss/klantcontacten` is called without `onderwerp` or `kanaal`
- THEN the response SHALL be HTTP 400 with `error: "missing_fields"`
- AND no provider call SHALL be made
- @e2e exclude backend input validation — covered by PHPUnit

