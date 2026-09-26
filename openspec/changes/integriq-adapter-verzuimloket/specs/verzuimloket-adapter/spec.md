# verzuimloket-adapter Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-verzuimloket

## Purpose

Integriq gains a DUO Verzuimloket (VSV-M2M) provider seam over Edukoppeling
transport so learniq's `leerplicht` DataExchangeJob can dispatch the
16-uur/4-weken melding (Leerplichtwet art. 21a, statutory 5-werkdagen
deadline per `recon/legal-po-2026-09-25.md`) and receive DUO's
acknowledgement back, without embedding a DUO client of its own. Per D3
(`decisions.md`) and ADR-022, integrations live in integriq; learniq keeps
the job type, dossier composition (`AttendanceFlag`/`AttendanceThreshold`)
and any lifecycle handling. Verzuimloket is one of the four
DUO-certificate-gated families named in M3(c) — the adapter ships now, live
traffic waits on the certificate.

## ADDED Requirements

### Requirement: REQ-001: Verzuimloket provider abstraction with log and Edukoppeling bindings

Integriq MUST define a `VerzuimloketProviderInterface`
(`lib/Service/Verzuimloket/VerzuimloketProviderInterface.php`) with
`getProviderId()`, `getConfigSchema()`, and
`send(sourceConfiguration, meldingType, kenmerk, payload)`. A source's
`configuration.provider` (`log`|`edukoppeling`) selects the binding at
runtime, mirroring `RodProviderInterface`. `log` MUST remain usable with no
configuration and MUST be the default when `configuration.provider` is
absent. `edukoppeling` (`VerzuimloketEdukoppelingClient`) MUST resolve its
signing certificate by reference through `PkiOverheidCredentialResolver`
and MUST refuse closed, naming what is missing, when no `certificateRef`
resolves.

#### Scenario: the log provider sends nothing over the network and returns a synthetic ref
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `send()` is called with `meldingType: eerste-melding`
- THEN a synthetic `MOCK-VERZUIM-<n>` ref SHALL be returned with no outbound HTTP call
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the Edukoppeling provider refuses closed without a certificate reference
- GIVEN a source with `configuration.provider: edukoppeling` and no `certificateRef`
- WHEN `send()` is called
- THEN `VerzuimloketProviderException` SHALL be raised naming the missing certificate reference, and no envelope SHALL be built
- @e2e exclude backend fail-closed guard — covered by PHPUnit

### Requirement: REQ-002: Outbound envelope translation with a literal-leak guard

The system MUST translate a `meldingType` (`eerste-melding`|
`herhaalmelding`|`langdurig-relatief-verzuim`) plus its field payload into
an Edukoppeling envelope via `VerzuimloketEnvelopeTranslator::translate()`.
Any required field for that `meldingType` that is missing, null, or empty
MUST raise `VerzuimloketTranslationException` naming the field BEFORE any
envelope is built. The envelope shape follows the same Edukoppeling/StUF
convention as `integriq-adapter-rod`'s translator, not a verified DUO
Verzuimloket berichtdefinitie (none was in the corpus) — isolated behind
this one translator.

#### Scenario: a complete eerste-melding translates to a valid envelope
- GIVEN a payload with `bsn`, `windowStart`, `windowEnd`, `metricValue` all populated
- WHEN `translate()` is called with `meldingType: eerste-melding`
- THEN an envelope SHALL be returned carrying all four fields plus any `breachingRecords`/`interventions` present
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a missing required field never reaches the envelope
- GIVEN a payload missing `metricValue` for `meldingType: eerste-melding`
- WHEN `translate()` is called
- THEN `VerzuimloketTranslationException` SHALL be raised naming `metricValue`, and no envelope SHALL be returned or sent
- @e2e exclude backend literal-leak guard — covered by PHPUnit

#### Scenario: langdurig-relatief-verzuim requires no windowEnd
- GIVEN a payload with `bsn` and a `startDate` but no `windowEnd` for `meldingType: langdurig-relatief-verzuim`
- WHEN translated
- THEN the envelope SHALL be built successfully without requiring `windowEnd`
- @e2e exclude backend translator — covered by PHPUnit

### Requirement: REQ-003: DUO acknowledgement translation to a typed event

The system MUST translate a DUO acknowledgement/retour into a
`VerzuimloketAcknowledgementReceivedEvent` (ADR-041) via
`VerzuimloketAcknowledgementTranslator::translate()`, carrying `kenmerk`,
`signaalcode`, `signaalOmschrijving`, and `accepted` (bool), mirroring
`RodAcknowledgementTranslator`. A retour with an empty or missing
`kenmerk` MUST be rejected BEFORE any event is dispatched.

#### Scenario: an accepted acknowledgement dispatches an event with accepted true
- GIVEN a DUO retour with `signaalcode: 0` and a valid `kenmerk`
- WHEN `translate()` is called
- THEN `VerzuimloketAcknowledgementReceivedEvent` SHALL be dispatched with `accepted: true`
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: a retour with no kenmerk is rejected before any event
- GIVEN a retour with an empty `kenmerk`
- WHEN translated
- THEN `VerzuimloketTranslationException` SHALL be raised and no event SHALL be dispatched
- @e2e exclude backend literal-leak guard (inbound) — covered by PHPUnit

### Requirement: REQ-004: Push endpoint and signed retour receiver

`POST /api/verzuimloket/berichten` MUST let an authenticated NC session
register a verzuimloket melding, returning `{ref, meldingType, status}` on
success, HTTP 400 on a missing required field, and HTTP 503
`not_configured` when no active `type=verzuimloket` source exists or the
selected binding cannot resolve its certificate. `POST
/api/verzuimloket/retour` MUST verify the inbound request's HMAC signature
via `WebhookSignatureService` BEFORE any processing; an unsigned or
tampered request MUST return HTTP 401 with no state change. A verified
retour MUST always acknowledge `{received: true}`, even when translation
fails internally.

#### Scenario: a valid push request returns a ref and status
- GIVEN an authenticated session and a configured `log` verzuimloket source
- WHEN `POST /api/verzuimloket/berichten` is called with a complete eerste-melding payload
- THEN HTTP 200 SHALL be returned with `{ref, meldingType: "eerste-melding", status: "sent"}`
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: an unsigned retour is rejected before any processing
- GIVEN a `POST /api/verzuimloket/retour` request with a missing or invalid signature header
- WHEN received
- THEN HTTP 401 SHALL be returned and no `verzuim_message` record SHALL be created
- @e2e exclude backend webhook signature gate — covered by PHPUnit

#### Scenario: a verified retour always acknowledges receipt
- GIVEN a correctly signed retour whose `kenmerk` does not resolve to any known local message
- WHEN received
- THEN the endpoint SHALL still respond `{received: true}` and log the unresolved reference
- @e2e exclude backend never-500-on-verified-callback — covered by PHPUnit

### Requirement: REQ-005: Per-message audit persistence and isolated retry

Every outbound send attempt and every inbound retour MUST persist one
`verzuim_message` OR record (`direction`, `meldingType`, `status`, `ref`,
`kenmerk`, `signaalcode`, `error`, `syncedAt`). `VerzuimloketRetryJob`
(hourly `TimedJob`, `allowParallelRuns=false`) MUST re-attempt every
`verzuim_message` row with `status: failed` or `pending`, with
per-message isolation.

#### Scenario: a successful outbound send persists a sent record with its ref
- GIVEN a complete eerste-melding push against the `log` provider
- WHEN `VerzuimloketService::sendMelding()` completes
- THEN a `verzuim_message` record SHALL be persisted with `direction: outbound`, `status: sent`, and the provider-returned `ref`
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: one failing retry does not abort the sweep
- GIVEN two failed `verzuim_message` rows, one of which raises on retry
- WHEN `retryFailed()` runs
- THEN the failing row SHALL be logged and skipped while the other row is still retried
- @e2e exclude backend per-message isolation — covered by PHPUnit

### Requirement: REQ-006: BSN hygiene — raw on the wire, hashed at rest

The outbound envelope MUST carry the pupil's raw BSN. The persisted
`verzuim_message` audit record MUST NEVER contain the raw BSN — it MUST be
SHA-256-hashed before the record is saved.

#### Scenario: the sent envelope carries the raw BSN but the audit record does not
- GIVEN an eerste-melding push with a raw BSN
- WHEN `sendMelding()` runs
- THEN the envelope handed to the provider SHALL contain the raw BSN
- AND the persisted `verzuim_message` record SHALL contain only a SHA-256 hash of it
- @e2e exclude backend AVG hygiene — covered by PHPUnit

## Non-Functional Requirements

- **Performance:** the `log` binding responds synchronously with no
  network call.
- **Accessibility:** no user-facing UI beyond the Adapters catalogue card,
  which already meets WCAG AA.
- **Internationalization:** Dutch and English MUST be supported for the
  catalogue card label/description (hydra ADR-007).

## Acceptance Criteria

- [ ] `VerzuimloketProviderInterface` has two bindings, both unit-tested
- [ ] No PEM string appears in any method signature, source configuration, or app-config key added by this change
- [ ] No raw BSN appears in any persisted `verzuim_message` record
- [ ] `POST /api/verzuimloket/retour` never returns 500 and never processes an unsigned request

## Notes

- The DUO software-vendor certificate (M3(c), open) gates `edukoppeling`
  activation, shared with ROD and OSO — not new to this change.
- `meldingType` accepts DUO's fuller vocabulary (herhaalmelding, LRV) even
  though learniq's `AttendanceThreshold` only computes `eerste-melding`
  today — a documented, forward-compatible assumption.
