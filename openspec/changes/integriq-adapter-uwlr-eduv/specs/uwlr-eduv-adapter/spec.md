# uwlr-eduv-adapter Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-uwlr-eduv

## Purpose

Integriq gains a shared provider seam and four target-specific
translators so learniq's `uwlr`, `edu-v`, `basispoort` and
`entree-content` DataExchangeJobs can export/sync pupil, group, teacher
and staff data to Kennisnet-registered publishers, test suppliers and
method providers, without embedding a client of its own. Per D3
(`decisions.md`) and ADR-022, integrations live in integriq; learniq keeps
the four job types and their `DataMappingProfile` seeds (sibling change
`uwlr-eduv-basispoort-contract`). UWLR/Edu-V/Basispoort are three of the
four certificate/keurmerk/aansluiting-gated families named in M3(c) — the
adapter ships now, live traffic waits on each target's own certification.

## ADDED Requirements

### Requirement: REQ-001: Shared provider abstraction with log and uwlr-eduv bindings

Integriq MUST define a `UwlrEduVProviderInterface`
(`lib/Service/UwlrEduV/UwlrEduVProviderInterface.php`) with
`getProviderId()`, `getConfigSchema()`, and `send(sourceConfiguration,
target, subtype, envelopeXml)`. A source's `configuration.provider`
(`log`|`uwlr-eduv`) selects the binding at runtime, mirroring
`OsoProviderInterface`. `log` MUST remain usable with no configuration and
MUST be the default. `uwlr-eduv` (`UwlrEduVKennisnetClient`) MUST resolve
its signing certificate by reference through `PkiOverheidCredentialResolver`
and MUST refuse closed, naming what is missing, when no `certificateRef`
resolves.

#### Scenario: the log provider sends nothing over the network and returns a synthetic ref
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `send()` is called with a complete envelope for any of the four targets
- THEN a synthetic `MOCK-UWLREDUV-<n>` ref SHALL be returned with no outbound HTTP call
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the uwlr-eduv provider refuses closed without a certificate reference
- GIVEN a source with `configuration.provider: uwlr-eduv` and no `certificateRef`
- WHEN `send()` is called
- THEN `UwlrEduVProviderException` SHALL be raised naming the missing certificate reference, and no envelope SHALL be built
- @e2e exclude backend fail-closed guard — covered by PHPUnit

### Requirement: REQ-002: UWLR export envelope translation across three subtypes

Integriq MUST translate a `uwlr` export payload for exactly one of three
subtypes — `pupil`, `group`, `teacher` — into a wire envelope, each
carrying `eckId` per `uwlr-eduv-basispoort-contract`'s seed scenario. A
missing required field MUST raise `UwlrEduVTranslationException` naming
the field before any envelope is built (literal-leak guard).

#### Scenario: a complete pupil export payload translates to a valid envelope
- GIVEN a `uwlr` export payload with subtype `pupil`, `eckId` and `schoolBrin`
- WHEN `UwlrExportEnvelopeTranslator::translate()` is called
- THEN the envelope carries `eckId` and `schoolBrin`
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: a missing eckId never reaches the envelope
- GIVEN a `uwlr` export payload for any subtype with no `eckId`
- WHEN `translate()` is called
- THEN `UwlrEduVTranslationException` SHALL be raised naming `eckId`, and no envelope SHALL be returned
- @e2e exclude backend translation — covered by PHPUnit

### Requirement: REQ-003: Edu-V export envelope translation across three qualified data services

Integriq MUST translate an `edu-v` export payload for exactly one of
three qualified data services — `onderwijsdeelnemers`,
`onderwijsgroepen`, `onderwijsmedewerkers` — into a wire envelope naming
its own `targetSchema` (`EduV:Onderwijsdeelnemers`,
`EduV:Onderwijsgroepen`, `EduV:Onderwijsmedewerkers` respectively), per
`uwlr-eduv-basispoort-contract`'s three distinct seeds. Edu-V keurmerk
certification is per data service, not once per connection (M3(c)); the
three subtypes MUST remain independently translatable so certifying one
never depends on another.

#### Scenario: each Edu-V subtype names its own targetSchema
- GIVEN an `edu-v` export payload for each of the three data services
- WHEN `EduVExportEnvelopeTranslator::translate()` is called for each
- THEN each envelope names a distinct `targetSchema` matching its data service
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: an unknown data service is rejected before any envelope is built
- GIVEN an `edu-v` export payload naming a data service outside the three qualified ones
- WHEN `translate()` is called
- THEN `UwlrEduVTranslationException` SHALL be raised naming the unknown data service
- @e2e exclude backend translation — covered by PHPUnit

### Requirement: REQ-004: Basispoort sync translation with SSO hand-off

Integriq MUST translate a `basispoort` sync payload (PO pupil/group/staff
export) into a wire envelope carrying an SSO hand-off audience for
method/publisher content, per `uwlr-eduv-basispoort-contract`'s
`direction: sync` seed.

#### Scenario: a complete Basispoort sync payload carries the SSO audience
- GIVEN a `basispoort` sync payload with `eckId`, `schoolBrin` and `ssoAudience`
- WHEN `BasispoortSyncTranslator::translate()` is called
- THEN the envelope carries all three fields
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: a missing ssoAudience never reaches the envelope
- GIVEN a `basispoort` sync payload with no `ssoAudience`
- WHEN `translate()` is called
- THEN `UwlrEduVTranslationException` SHALL be raised naming `ssoAudience`
- @e2e exclude backend translation — covered by PHPUnit

### Requirement: REQ-005: Entree content SSO hand-off translation

Integriq MUST translate an `entree-content` sync payload (VO content
SSO hand-off to a third-party publisher) into a wire envelope, kept
structurally distinct from `entree-surfconext-sso-contract`'s own
login-federation concern — this requirement never touches learniq's own
authentication boundary.

#### Scenario: a complete Entree content payload carries the SSO audience
- GIVEN an `entree-content` sync payload with `eckId`, `schoolBrin` and `ssoAudience`
- WHEN `EntreeContentSyncTranslator::translate()` is called
- THEN the envelope carries all three fields
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: a missing schoolBrin never reaches the envelope
- GIVEN an `entree-content` sync payload with no `schoolBrin`
- WHEN `translate()` is called
- THEN `UwlrEduVTranslationException` SHALL be raised naming `schoolBrin`
- @e2e exclude backend translation — covered by PHPUnit

### Requirement: REQ-006: Shared acknowledgement translation and event dispatch

Integriq MUST translate an inbound acknowledgement envelope (shared
across all four targets) into a plain status update and dispatch
`UwlrEduVAcknowledgementReceivedEvent` (ADR-041). A retour missing its
`kenmerk` MUST raise `UwlrEduVTranslationException` before any event is
dispatched.

#### Scenario: an accepted acknowledgement dispatches the event as accepted
- GIVEN a retour envelope with `kenmerk` and an accepted status code
- WHEN `UwlrEduVAcknowledgementTranslator::translate()` is called
- THEN the resulting event reports `accepted: true`
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: a retour with no kenmerk is rejected before any dispatch
- GIVEN a retour envelope missing `kenmerk`
- WHEN `translate()` is called
- THEN `UwlrEduVTranslationException` SHALL be raised, and no event SHALL be dispatched
- @e2e exclude backend translation — covered by PHPUnit

### Requirement: REQ-007: Per-target audit persistence and isolated retry

Integriq MUST persist one `uwlr_eduv_message` record per send/sync
attempt, tagged with its `target` and (where applicable) `subtype`. An
hourly `UwlrEduVRetryJob` MUST retry only `status: failed` rows, and a
failure retrying one row MUST NOT prevent other rows from being retried
in the same sweep.

#### Scenario: a failed send persists and is retried in isolation
- GIVEN two failed `uwlr_eduv_message` rows across different targets, one of which raises again on retry
- WHEN `UwlrEduVRetryJob::run()` executes
- THEN the failing row is logged and skipped while the other row is retried
- @e2e exclude backend job — covered by PHPUnit

### Requirement: REQ-008: Push/sync endpoints and a shared signed retour endpoint

Integriq MUST expose `POST /api/uwlr-eduv/uwlr`, `/edu-v`, `/basispoort`
and `/entree-content` (all four `#[NoAdminRequired]`) and `POST
/api/uwlr-eduv/retour` (`#[PublicPage]`, HMAC-verified before any
processing, always acknowledging `{received: true}` once verified even
if internal processing fails).

#### Scenario: the uwlr export endpoint returns a ref on success
- GIVEN an authenticated session and a `log`-provider source
- WHEN `POST /api/uwlr-eduv/uwlr` is called with a complete pupil-subtype payload
- THEN HTTP 200 is returned with a `ref`, `target: "uwlr"`, `status: "sent"`
- @e2e exclude backend controller — covered by PHPUnit

#### Scenario: an unsigned retour is rejected before processing
- GIVEN a `POST /api/uwlr-eduv/retour` request with a missing or invalid HMAC header
- WHEN the controller receives it
- THEN HTTP 401 is returned and no `uwlr_eduv_message` record is created
- @e2e exclude backend controller — covered by PHPUnit

### Requirement: REQ-009: Catalogue descriptor (ADR-017 Rule 1)

Integriq MUST ship a `UwlrEduVAdapter` catalogue descriptor (id
`uwlr-eduv`, category government) with the `log`/`uwlr-eduv` config
schema and a `planned`-claim `GatewayCatalogue` entry, never a new menu
item or `/beheer` route. Its icon MUST already be registered in
`src/icons.js` before commit.

#### Scenario: the catalogue card carries no new navigation surface
- GIVEN the `UwlrEduVAdapter` descriptor
- WHEN the catalogue is rendered
- THEN no new menu item or `/beheer` route is introduced
- @e2e exclude static descriptor shape — covered by PHPUnit and gate-60/icon-vocabulary
