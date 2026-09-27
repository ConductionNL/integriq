# rod-adapter Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-rod

## Purpose

Integriq gains a DUO ROD (Register Onderwijsdeelnemers) provider seam over
Edukoppeling transport so learniq's `bron-rod` DataExchangeJob can dispatch
inschrijving/uitschrijving/verblijfsgegevens/schooladvies messages and
receive DUO's acknowledgement and signaalcodes back, without embedding a DUO
client of its own. Per D3 (`decisions.md`) and ADR-022, integrations live in
integriq; learniq keeps the job type, payload mapping and lifecycle gate.
ROD is one of the four DUO-certificate-gated families named in M3(c) — the
adapter ships now, live traffic waits on the certificate (M3(c), open).

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** the `log` binding responds synchronously with no network
  call; no latency SLA is made for `edukoppeling` until a live DUO
  connection exists.
- **Accessibility:** no user-facing UI beyond the existing Adapters
  catalogue card and source configuration form, which already meet WCAG AA
  (ADR-017).
- **Internationalization:** Dutch and English MUST be supported for the
  catalogue card label/description and the source configuration form
  (hydra ADR-007).

## Acceptance Criteria

- [ ] `RodProviderInterface` has two bindings (`log`, `edukoppeling`), both
      unit-tested against fixtures
- [ ] No PEM string appears in any method signature, source configuration,
      or app-config key added by this change
- [ ] No raw BSN appears in any persisted `rod_message` record
- [ ] `POST /api/rod/retour` never returns 500 and never processes an
      unsigned request

## Notes

- The DUO software-vendor certificate ("1 certificaat per softwareleverancier",
  parnassys#13.1) is a governance question open in `decisions.md` M3(c) —
  it gates `edukoppeling` activation, not this spec's completeness.
- The Edukoppeling envelope shape is a structural assumption pending real
  DUO test-environment access; see design.md.
