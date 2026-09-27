# oso-adapter Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-oso

## Purpose

Integriq gains an OSO (Overstapservice Onderwijs, Kennisnet) provider seam,
both directions, so learniq's `oso` DataExchangeJob can export an
overstapdossier (already gated by learniq's own `OsoDossierReviewGuard`
parent-review lifecycle, enforced before the job leaves `queued` per
`DataExchangeRunGuard::GATED_TARGETS`) and receive an inbound
overstapdossier from another school, without embedding an OSO client of
its own. Per D3 (`decisions.md`) and ADR-022, integrations live in
integriq; learniq keeps the job type, export lifecycle gate, and (via the
sibling `oso-inbound-contract` change) the `OsoImportDossier` schema and
its own review lifecycle for imports. OSO is one of the four
certificate/aansluiting-gated families named in M3(c) — the adapter ships
now, live traffic waits on Kennisnet's OSO aansluiting approval.

## ADDED Requirements

### Requirement: REQ-001: OSO export provider abstraction with log and Kennisnet bindings

Integriq MUST define an `OsoProviderInterface`
(`lib/Service/Oso/OsoProviderInterface.php`) with `getProviderId()`,
`getConfigSchema()`, and `sendExport(sourceConfiguration, kenmerk,
payload)`. A source's `configuration.provider` (`log`|`kennisnet`) selects
the binding at runtime, mirroring `RodProviderInterface`. `log` MUST
remain usable with no configuration and MUST be the default. `kennisnet`
(`OsoKennisnetClient`) MUST resolve its signing certificate by reference
through `PkiOverheidCredentialResolver` and MUST refuse closed, naming
what is missing, when no `certificateRef` resolves.

#### Scenario: the log provider sends nothing over the network and returns a synthetic ref
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `sendExport()` is called with a complete export payload
- THEN a synthetic `MOCK-OSO-<n>` ref SHALL be returned with no outbound HTTP call
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the Kennisnet provider refuses closed without a certificate reference
- GIVEN a source with `configuration.provider: kennisnet` and no `certificateRef`
- WHEN `sendExport()` is called
- THEN `OsoProviderException` SHALL be raised naming the missing certificate reference, and no envelope SHALL be built
- @e2e exclude backend fail-closed guard — covered by PHPUnit

### Requirement: REQ-002: Export envelope translation with a literal-leak guard

The system MUST translate an export payload (`learnerEckId`,
`targetSchoolBrin`, `categories[]`, `attachmentRefs[]`) into an OSO
envelope via `OsoExportEnvelopeTranslator::translate()`. Any missing/empty
required field MUST raise `OsoTranslationException` naming the field
BEFORE any envelope is built. This translator MUST NOT re-check
pending-parent-review — a dispatched job already cleared
`DataExchangeRunGuard`.

#### Scenario: a complete export payload translates to a valid envelope
- GIVEN a payload with `learnerEckId`, `targetSchoolBrin`, and at least one `categories` entry
- WHEN `translate()` is called
- THEN an envelope SHALL be returned carrying the learner id, target school, and every category
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a missing required field never reaches the envelope
- GIVEN a payload missing `targetSchoolBrin`
- WHEN `translate()` is called
- THEN `OsoTranslationException` SHALL be raised naming `targetSchoolBrin`, and no envelope SHALL be returned or sent
- @e2e exclude backend literal-leak guard — covered by PHPUnit

### Requirement: REQ-003: Import parsing into learniq's OsoImportDossier field shape

The system MUST parse an inbound OSO XML overstapdossier via
`OsoImportTranslator::translate()` into
`{sourceSchoolBrin, learnerEckId, categories, draftProfile, attachmentRefs}`
— the field names `oso-inbound-contract`'s `OsoImportDossier` expects — and
dispatch `OsoDossierReceivedEvent` (ADR-041) carrying them, for learniq's
own listener to materialise. Integriq MUST NEVER write learniq's
`OsoImportDossier` object directly. Parsing MUST be XXE-hardened via the
shared `StufXmlParser`.

#### Scenario: a complete inbound dossier dispatches OsoDossierReceivedEvent
- GIVEN a well-formed OSO XML overstapdossier naming a sending school BRIN and a learner ECK iD
- WHEN `POST /api/oso/import` is received and verified
- THEN `OsoDossierReceivedEvent` SHALL be dispatched carrying `sourceSchoolBrin` and `learnerEckId`
- @e2e exclude backend import translator — covered by PHPUnit

#### Scenario: a malformed inbound dossier is logged and never dispatched
- GIVEN a malformed or empty XML body
- WHEN `POST /api/oso/import` is received and verified
- THEN no `OsoDossierReceivedEvent` SHALL be dispatched and the failure SHALL be logged, never a 500
- @e2e exclude backend malformed-input handling — covered by PHPUnit

### Requirement: REQ-004: Push export, signed inbound import, and signed export retour

`POST /api/oso/export` MUST let an authenticated NC session register an
export, returning `{ref, direction, status}` on success, HTTP 400 on a
missing required field, HTTP 503 `not_configured` when no active
`type=oso` source exists. `POST /api/oso/import` and `POST /api/oso/retour`
MUST verify the inbound request's HMAC signature via
`WebhookSignatureService` BEFORE any processing; an unsigned or tampered
request MUST return HTTP 401 with no state change. A verified request to
either endpoint MUST always acknowledge `{received: true}`, even when
translation fails internally.

#### Scenario: a valid export request returns a ref and status
- GIVEN an authenticated session and a configured `log` OSO source
- WHEN `POST /api/oso/export` is called with a complete export payload
- THEN HTTP 200 SHALL be returned with `{ref, direction: "export", status: "sent"}`
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: an unsigned import request is rejected before any processing
- GIVEN a `POST /api/oso/import` request with a missing or invalid signature header
- WHEN received
- THEN HTTP 401 SHALL be returned and no `oso_message` record SHALL be created
- @e2e exclude backend webhook signature gate — covered by PHPUnit

#### Scenario: a verified import request always acknowledges receipt
- GIVEN a correctly signed but unparseable import body
- WHEN received
- THEN the endpoint SHALL still respond `{received: true}` and log the parse failure
- @e2e exclude backend never-500-on-verified-callback — covered by PHPUnit

### Requirement: REQ-005: Per-message audit persistence and isolated retry

Every export attempt and every inbound import/retour MUST persist one
`oso_message` OR record (`direction` — `export`|`import`, `status`, `ref`,
`kenmerk`, `error`, `syncedAt`). `OsoRetryJob` (hourly `TimedJob`,
`allowParallelRuns=false`) MUST re-attempt every `oso_message` row with
`status: failed` or `pending` and `direction: export`, with per-message
isolation.

#### Scenario: a successful export persists a sent record with its ref
- GIVEN a complete export payload pushed against the `log` provider
- WHEN `OsoService::sendExport()` completes
- THEN an `oso_message` record SHALL be persisted with `direction: export`, `status: sent`, and the provider-returned `ref`
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: one failing retry does not abort the sweep
- GIVEN two failed export `oso_message` rows, one of which raises on retry
- WHEN `retryFailed()` runs
- THEN the failing row SHALL be logged and skipped while the other row is still retried
- @e2e exclude backend per-message isolation — covered by PHPUnit

### Requirement: REQ-006: Data minimisation is a pass-through, not a integriq decision

The system MUST transmit exactly the `categories` array learniq's payload
supplies, marking `included: false` entries as excluded rather than
omitting or reinterpreting them. Integriq MUST NOT decide which categories
are sent — that decision is learniq's (data-minimisation, parent inzage),
per `M3-integrations.md` row I3.

#### Scenario: an excluded category is transmitted as excluded, not omitted
- GIVEN an export payload with a category marked `included: false`
- WHEN the envelope is built
- THEN the envelope SHALL still reference that category with its excluded state, never silently drop it
- @e2e exclude backend data-minimisation pass-through — covered by PHPUnit

## Non-Functional Requirements

- **Performance:** the `log` binding responds synchronously with no
  network call.
- **Accessibility:** no user-facing UI beyond the Adapters catalogue card.
- **Internationalization:** Dutch and English MUST be supported for the
  catalogue card label/description.

## Acceptance Criteria

- [ ] `OsoProviderInterface` has two bindings, both unit-tested
- [ ] `OsoImportTranslator`'s output field names match `OsoImportDossier` exactly
- [ ] No PEM string appears in any method signature, source configuration, or app-config key added by this change
- [ ] `POST /api/oso/import` and `POST /api/oso/retour` never return 500 and never process an unsigned request

## Notes

- Kennisnet's OSO aansluiting approval (M3(c), open) gates `kennisnet`
  binding activation.
- `OsoImportDossier`'s field shape is read from a sibling lane's committed
  (not yet merged) branch — see proposal.md Risk 1.
