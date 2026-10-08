# rod-adapter Specification

## Purpose
Integriq gains a DUO ROD (Register Onderwijsdeelnemers) provider seam over
Edukoppeling transport so learniq's `bron-rod` DataExchangeJob can dispatch
inschrijving/uitschrijving/verblijfsgegevens/schooladvies messages and
receive DUO's acknowledgement and signaalcodes back, without embedding a DUO
client of its own. Per D3 (`decisions.md`) and ADR-022, integrations live in
integriq; learniq keeps the job type, payload mapping and lifecycle gate.
ROD is one of the four DUO-certificate-gated families named in M3(c) — the
adapter ships now, live traffic waits on the certificate (M3(c), open).

## Requirements

### Requirement: REQ-001: Rod provider abstraction with log and Edukoppeling bindings

Integriq MUST define a `RodProviderInterface`
(`lib/Service/Rod/RodProviderInterface.php`) with `getProviderId()`,
`getConfigSchema()`, and
`send(sourceConfiguration, berichtsoort, kenmerk, payload)`. A source's
`configuration.provider` (`log`|`edukoppeling`) selects the binding at
runtime, mirroring `IwmoIjwProviderInterface`. `log` MUST remain usable with
no configuration and MUST be the default when `configuration.provider` is
absent. `edukoppeling` (`RodEdukoppelingClient`) MUST resolve its signing
certificate by reference through `PkiOverheidCredentialResolver` — never a
PEM by value — and MUST refuse closed, naming what is missing, when no
`certificateRef` resolves.

#### Scenario: the log provider sends nothing over the network and returns a synthetic ref
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `send()` is called with `berichtsoort: inschrijving`
- THEN a synthetic `MOCK-ROD-<n>` ref SHALL be returned with no outbound HTTP call
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the Edukoppeling provider refuses closed without a certificate reference
- GIVEN a source with `configuration.provider: edukoppeling` and no `certificateRef`
- WHEN `send()` is called
- THEN `RodProviderException` SHALL be raised naming the missing certificate reference, and no envelope SHALL be built
- @e2e exclude backend fail-closed guard — covered by PHPUnit

#### Scenario: a future alternative DUO-compatible transport is a drop-in binding
- GIVEN a future transport implementing `RodProviderInterface`
- WHEN it is registered
- THEN it SHALL be selectable via `configuration.provider` with no change to `RodService` or `RodController`
- @e2e exclude backend provider seam — covered by PHPUnit

### Requirement: REQ-002: Outbound envelope translation with a literal-leak guard

The system MUST translate a `berichtsoort` (`inschrijving`|`uitschrijving`|
`verblijfsgegevens`|`schooladvies`) plus its field payload into an
Edukoppeling envelope via `RodEnvelopeTranslator::translate()`. Any required
field for that `berichtsoort` (design.md's field table) that is missing,
null, or empty MUST raise `RodTranslationException` naming the field BEFORE
any envelope is built — the envelope MUST NEVER contain an empty tag or an
unresolved template marker. **The envelope shape here follows the
Edukoppeling/StUF convention already used by `DigikoppelingAdapter` and
`iwmo-ijw-adapter`, not a verified DUO ROD berichtdefinitie** (no XSD or
message spec for ROD itself was in the corpus read for this change) — this
is stated in design.md as an explicit assumption, isolated behind this one
translator so a future correction is localized.

#### Scenario: a complete inschrijving translates to a valid envelope
- GIVEN a payload with `bsn`, `inschrijvingsdatum`, `leerjaar`, `groep` all populated
- WHEN `RodEnvelopeTranslator::translate()` is called with `berichtsoort: inschrijving`
- THEN an envelope SHALL be returned carrying all four fields in its body
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a missing required field never reaches the envelope
- GIVEN a payload missing `leerjaar` for `berichtsoort: inschrijving`
- WHEN `translate()` is called
- THEN `RodTranslationException` SHALL be raised naming `leerjaar`, and no envelope SHALL be returned or sent
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: schooladvies carries no leerjaar/groep fields
- GIVEN a payload with only `schooladviesWaarde` and `schooladviesDatum` for `berichtsoort: schooladvies`
- WHEN translated
- THEN the envelope SHALL carry those two fields and MUST NOT require `leerjaar` or `groep`
- @e2e exclude backend translator — covered by PHPUnit

### Requirement: REQ-003: DUO acknowledgement and signaalcode translation to a typed event

The system MUST translate a DUO acknowledgement/retour into a
`RodAcknowledgementReceivedEvent` (ADR-041) via
`RodAcknowledgementTranslator::translate()`, carrying `kenmerk`,
`signaalcode`, `signaalOmschrijving`, and `accepted` (bool). A retour with
an empty or missing `kenmerk` MUST be rejected
(`RodTranslationException`) BEFORE any event is dispatched or `rod_message`
row updated — the system MUST NEVER guess or fall back to an unrelated
message.

#### Scenario: an accepted acknowledgement dispatches an event with accepted true
- GIVEN a DUO retour with `signaalcode: 0` (accepted) and a valid `kenmerk`
- WHEN `RodAcknowledgementTranslator::translate()` is called
- THEN `RodAcknowledgementReceivedEvent` SHALL be dispatched with `accepted: true`
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: a rejection signaalcode dispatches an event with accepted false and the reason
- GIVEN a DUO retour with a non-zero `signaalcode` and a description
- WHEN translated
- THEN the event SHALL carry `accepted: false`, the `signaalcode`, and `signaalOmschrijving` unchanged
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: a retour with no kenmerk is rejected before any event
- GIVEN a retour with an empty `kenmerk`
- WHEN translated
- THEN `RodTranslationException` SHALL be raised and no event SHALL be dispatched
- @e2e exclude backend literal-leak guard (inbound) — covered by PHPUnit

### Requirement: REQ-004: Push endpoint and signed retour receiver

`POST /api/rod/berichten` MUST let an authenticated NC session register a
ROD message, returning `{ref, berichtsoort, status}` on success, HTTP 400 on
a missing required field, and HTTP 503 `not_configured` when no active
`type=rod` source exists or the selected binding cannot resolve its
certificate — never a 500 crash. `POST /api/rod/retour` MUST verify the
inbound request's HMAC signature via `WebhookSignatureService` BEFORE any
processing; an unsigned or tampered request MUST return HTTP 401 with no
state change. A verified retour MUST always acknowledge `{received: true}`,
even when translation fails internally (logged, never a 500).

#### Scenario: a valid push request returns a ref and status
- GIVEN an authenticated session and a configured `log` or `edukoppeling` ROD source
- WHEN `POST /api/rod/berichten` is called with a complete inschrijving payload
- THEN HTTP 200 SHALL be returned with `{ref, berichtsoort: "inschrijving", status: "sent"}`
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: a push request with no active source returns not_configured
- GIVEN no active `type=rod` source
- WHEN `POST /api/rod/berichten` is called
- THEN HTTP 503 `not_configured` SHALL be returned, no envelope built
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: an unsigned retour is rejected before any processing
- GIVEN a `POST /api/rod/retour` request with a missing or invalid signature header
- WHEN received
- THEN HTTP 401 SHALL be returned and no `rod_message` record SHALL be created or updated
- @e2e exclude backend webhook signature gate — covered by PHPUnit

#### Scenario: a verified retour always acknowledges receipt
- GIVEN a correctly signed retour whose `kenmerk` does not resolve to any known local message
- WHEN received
- THEN the endpoint SHALL still respond `{received: true}` (never a 500) and log the unresolved reference
- @e2e exclude backend never-500-on-verified-callback — covered by PHPUnit

### Requirement: REQ-005: Per-message audit persistence and isolated retry

Every outbound send attempt and every inbound retour MUST persist one
`rod_message` OR record (`direction`, `berichtsoort`, `status`, `ref`,
`kenmerk`, `signaalcode`, `error`, `syncedAt`). `RodRetryJob` (hourly
`TimedJob`, `allowParallelRuns=false`) MUST re-attempt every `rod_message`
row with `status: failed` or `pending` through the same send path, with
per-message isolation: one message's retry exception MUST be logged and
skipped without aborting the sweep. A sweep with no eligible rows MUST be a
clean no-op.

#### Scenario: a successful outbound send persists a sent record with its ref
- GIVEN a complete inschrijving push against the `log` provider
- WHEN `RodService::sendBericht()` completes
- THEN a `rod_message` record SHALL be persisted with `direction: outbound`, `status: sent`, and the provider-returned `ref`
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: a failed outbound send persists a failed record and is retried later
- GIVEN an `edukoppeling` provider that raises `RodProviderException` on send
- WHEN `sendBericht()` is called
- THEN a `rod_message` record SHALL be persisted with `status: failed` and `error` set
- AND WHEN `RodRetryJob` next runs THEN `retryFailed()` SHALL re-attempt that record
- @e2e exclude backend retry job — covered by PHPUnit

#### Scenario: one failing retry does not abort the sweep
- GIVEN two failed `rod_message` rows, one of which raises on retry
- WHEN `retryFailed()` runs
- THEN the failing row SHALL be logged and skipped while the other row is still retried
- @e2e exclude backend per-message isolation — covered by PHPUnit

### Requirement: REQ-006: BSN hygiene — raw on the wire, hashed at rest

The outbound envelope MUST carry the pupil's raw BSN when the `berichtsoort`
requires it (legally required for DUO to identify the leerling). The
persisted `rod_message` audit record MUST NEVER contain the raw BSN — it
MUST be SHA-256-hashed before the record is saved, consistent with
`AvgBsnPolicyRule`/`iwmo-ijw-adapter` REQ-006 precedent.

#### Scenario: the sent envelope carries the raw BSN but the audit record does not
- GIVEN an inschrijving push with a raw BSN
- WHEN `sendBericht()` runs
- THEN the envelope handed to the provider SHALL contain the raw BSN
- AND the persisted `rod_message` record SHALL contain only a SHA-256 hash of it, never the raw value
- @e2e exclude backend AVG hygiene — covered by PHPUnit

### Requirement: REQ-007: the persoonsgebonden nummer goes in DUO's choice element
Every ROD message MUST carry `persoonsgebondenNummer` with exactly one child named by
`persoonsgebondenNummerType`: `burgerservicenummer` or `onderwijsnummer`. The number MUST be
nine digits. A payload with only the legacy `bsn` key MUST be read as a burgerservicenummer.
A missing number, an unknown type or a malformed number MUST fail translation before any XML
is built.

#### Scenario: an onderwijsnummer
- GIVEN a learner record with `persoonsgebondenNummerType` `onderwijsnummer`
- WHEN an inschrijving is translated
- THEN the body holds `<persoonsgebondenNummer><onderwijsnummer>` with the number
- AND no `bsn` element

#### Scenario: an unknown type
- GIVEN `persoonsgebondenNummerType` `paspoort`
- WHEN any message is translated
- THEN translation fails naming `persoonsgebondenNummerType`

#### Scenario: the learner mapping maps the number, not the ECK iD
- GIVEN the seeded mapping `learniq-bron-rod-export-learner`
- THEN it maps `persoonsgebondenNummer` and `persoonsgebondenNummerType` from the same input keys
- AND it still maps `eckId`

### Requirement: REQ-008: the school advice is sent as AanleverenAdviesVO_Request
A `bron-rod` message with berichtsoort `schooladvies` MUST render an
`AanleverenAdviesVO_Request` holding, in order: persoonsgebonden nummer, `adviesvolgnummer`,
`onderwijsaanbieder` and `onderwijslocatie` when not null, `vestigingscode`, `adviesjaar`,
`advies1` and `advies2` each as a combined `advies` plus `adviesdatum` and left out when null.
At least one advice MUST be given. Formats: adviesvolgnummer letters and digits up to 20;
onderwijsaanbieder `nnnAnnn`; onderwijslocatie `nnnXnnn`; vestigingscode six letters or digits;
adviesjaar four digits; advice dates `Y-m-d`; an advice value in DUO's value-list format.
The seeded mapping `learniq-bron-rod-export-schooladvies` MUST exist, and a learniq `bron-rod`
job without a mapping slug and with scope `berichtsoort: schooladvies` MUST use it.

#### Scenario: advice with a reconsidered definitive advice
- GIVEN `advies1` `VMBO_KB` on 2026-01-20 and `advies2` `VMBO_GL/TL` on 2026-05-15
- WHEN the schooladvies is translated
- THEN `advies1` and `advies2` each hold their `advies` and `adviesdatum`

#### Scenario: advice without a second advice
- GIVEN `advies2` null
- WHEN the schooladvies is translated
- THEN no `advies2` element is rendered

#### Scenario: a malformed onderwijsaanbieder
- GIVEN `onderwijsaanbieder` `100B200`
- WHEN the schooladvies is translated
- THEN translation fails naming `onderwijsaanbieder`

#### Scenario: default mapping for a schooladvies job
- GIVEN learniq requests a `bron-rod` export with scope `berichtsoort: schooladvies` and no mapping
- WHEN the job is created
- THEN its mapping is `learniq-bron-rod-export-schooladvies`

### Requirement: REQ-009: the persoonsgebonden nummer never reaches a log or a stored error
The ROD path MUST NOT write the persoonsgebonden nummer to a log line, an exception message,
a `rod_message` error or an exchange rejection. Provider and transport messages MUST be
redacted (the known number and any standalone run of nine digits) before they are logged,
thrown or stored. Translation failures MUST name fields, never values.

#### Scenario: a DUO fault echoes the number
- GIVEN a provider failure message containing the number
- WHEN the send fails
- THEN the thrown message, the stored `error` and every log line lack the number

#### Scenario: an exchange rejection
- GIVEN a `bron-rod` job whose send fails
- WHEN the dispatcher records the rejection
- THEN the rejection holds a code and field names only

### Requirement: The retour acts as the ROD connection's account (REQ-020)

`POST /api/rod/retour` MUST authenticate a delivery against the `rod-webhook` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run every OpenRegister write as that consumer's account. A bad signature MUST answer 401. A missing connection, account or right MUST answer 503 and write nothing. A write OpenRegister refuses MUST answer 503, never 200. Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the connection's account

- GIVEN a `rod-webhook` consumer whose account may write `rod_message`
- WHEN DUO (ROD) posts a correctly signed delivery without a session
- THEN it is stored
- AND every write runs as that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account answers 503

- GIVEN a `rod-webhook` consumer without an account
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature answers 401

- GIVEN a `rod-webhook` consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
