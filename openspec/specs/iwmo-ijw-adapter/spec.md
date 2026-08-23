# iwmo-ijw-adapter Specification

## Purpose

Integriq gains an iWMO/iJW (StUF iStandaarden Wmo 3.0 / Jeugdwet 3.0)
bridge so sibling apps (e.g. procest's social-domain case module) can
register a Wmo/Jeugdwet care assignment (toewijzing) or invoice (declaratie)
and receive the standard's retour outcomes back onto the OR case, without
embedding a client of their own. iWMO/iJW are legally required
interoperability standards between Dutch municipalities and care providers.
Per ADR-022 integrations live in integriq, not as nc-vue leaves and not
per-app. A narrow `IwmoIjwProviderInterface` (send) lets a compatible
alternative transport be added later; this change ships a `log`/sandbox
binding and a `rest` binding (`IStandaardenClient`) so the whole
send/retour path is demonstrable end-to-end without a live GGk/VECOZO
credential.

## Requirements
### Requirement: IwmoIjw provider abstraction with log and REST bindings (REQ-001)

Integriq MUST define an `IwmoIjwProviderInterface`
(`lib/Service/IwmoIjw/IwmoIjwProviderInterface.php`) with `getProviderId()`,
`getConfigSchema()`, and `send(sourceConfiguration, berichttype, envelopeXml)`.
A source's `configuration.provider` (`log`|`rest`) selects the binding at
runtime — mirroring `SmsProviderInterface`/`KlantinteractiesProviderInterface`.
`log` MUST remain usable with no configuration and MUST be the default when
`configuration.provider` is absent. `rest` (`IStandaardenClient`) MUST
authenticate every request with `Authorization: Bearer <token>`, decrypting
`configuration.authentication.encryptedToken` via `OCP\Security\ICrypto`
in-process for the instant needed to build the header — never logged, never
persisted decrypted.

#### Scenario: the interface is the single seam for adding a compatible alternative transport
- GIVEN a future alternative GGk/VECOZO-compatible transport (e.g. one supporting client-certificate auth)
- WHEN it implements `IwmoIjwProviderInterface`
- THEN it SHALL be selectable via `configuration.provider` with no change to `IwmoIjwSyncService` or `IwmoIjwController`
- @e2e exclude backend provider seam — covered by PHPUnit

#### Scenario: the log provider sends nothing over the network and returns a synthetic ref
- GIVEN a source with `configuration.provider: log` (or absent)
- WHEN `send()` is called with a Toewijzing envelope
- THEN a synthetic `MOCK-IWMO-<n>` ref SHALL be returned with no outbound HTTP call and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the REST provider sends the expected bearer auth header
- GIVEN a source with `configuration.provider: rest` and a valid `encryptedToken`
- WHEN `send()` is dispatched
- THEN the request SHALL carry `Authorization: Bearer <decrypted-token>` and the envelope XML as the raw request body
- @e2e exclude backend provider binding — covered by PHPUnit

### Requirement: Outbound berichttype translation with a literal-leak guard (REQ-002)

The system MUST translate an OR social-domain case object into a Wmo303/
Jw303 (Toewijzing) or Wmo321/Jw321 (Declaratie) XML envelope via
`OutboundBerichtTranslator::translate()`, selected by `kind`
(`toewijzing`|`declaratie`) and `domain` (`wmo`|`jw`). Any required field
(per design.md's outbound field table) that is missing, null, or empty MUST
raise `IwmoIjwTranslationException` BEFORE any XML is built — the envelope
MUST NEVER contain an empty tag, a null literal, or an unresolved template
marker for a required field. The fully rendered envelope MUST also be
scanned for leftover `{{`/`}}`/`%%UNRESOLVED%%` markers as defense in depth
and rejected if any survive.

#### Scenario: a complete toewijzing translates to a valid Wmo303 envelope
- GIVEN an OR case object with every required toewijzing field populated and `domain: wmo`
- WHEN `OutboundBerichtTranslator::translate()` is called with `kind: toewijzing`
- THEN a `Wmo303` envelope SHALL be returned carrying `bsn`, `productcode`, `ingangsdatum`, `omvang`, `leveringsvorm` in the body
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: the same case object translates to Jw303 when domain is jw
- GIVEN the same OR case object with `domain: jw`
- WHEN translated
- THEN the returned berichtcode SHALL be `Jw303`, not `Wmo303`
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a complete declaratie translates to a valid Wmo321/Jw321 envelope
- GIVEN an OR case object with every required declaratie field populated (`toewijzingReferentie`, `factuurnummer`, `bedrag`, `periodeStart`, `periodeEind`)
- WHEN `OutboundBerichtTranslator::translate()` is called with `kind: declaratie`
- THEN a `Wmo321`/`Jw321` envelope SHALL be returned carrying every declaratie field
- @e2e exclude backend translator — covered by PHPUnit

#### Scenario: a missing required field never reaches the XML — literal-leak guard
- GIVEN an OR case object missing `productcode`
- WHEN `OutboundBerichtTranslator::translate()` is called
- THEN `IwmoIjwTranslationException` SHALL be raised naming `productcode`, and no XML SHALL be returned or sent
- @e2e exclude backend literal-leak guard — covered by PHPUnit

### Requirement: Inbound retour translation to an OR case status update (REQ-003)

The system MUST translate a retour XML envelope (berichttype 302, 304, 305,
306, 307, 308, or 322) into an OR case status update via
`InboundRetourTranslator::translate()` — see design.md's inbound retour
field table for the full status mapping. A retour with an empty or missing
`stuurgegevens.kenmerk` MUST be rejected (raise
`IwmoIjwTranslationException`) BEFORE any OR write is attempted — the
system MUST NEVER guess or fall back to an unrelated case.

#### Scenario: a Wmo304 acceptance retour maps to status accepted
- GIVEN a Wmo304 retour with `resultaat: akkoord` and a valid `kenmerk`
- WHEN `InboundRetourTranslator::translate()` is called
- THEN the returned update SHALL carry `status: accepted`
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: a Wmo302 retour with no explicit resultaat defaults to rejected
- GIVEN a Wmo302 retour with no `resultaat` field
- WHEN translated
- THEN the returned update SHALL carry `status: rejected` (never silently accepted)
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: Wmo305/Wmo307 map to care_started/care_stopped with their timestamps
- GIVEN a Wmo305 retour with `startdatumWerkelijk` and a Wmo307 retour with `einddatumWerkelijk`
- WHEN each is translated
- THEN the updates SHALL carry `status: care_started`/`careStartedAt` and `status: care_stopped`/`careStoppedAt` respectively
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: a Wmo322 declaratie retour maps betaalstatus to invoice_processed/invoice_rejected
- GIVEN a Wmo322 retour with `betaalstatus: akkoord` and `betalingReferentie`
- WHEN translated
- THEN the update SHALL carry `status: invoice_processed` and `paymentReference` set from `betalingReferentie`
- @e2e exclude backend inbound translator — covered by PHPUnit

#### Scenario: a retour with no kenmerk is rejected before any OR write
- GIVEN a retour XML with an empty `stuurgegevens.kenmerk`
- WHEN translated
- THEN `IwmoIjwTranslationException` SHALL be raised and no OR case update SHALL be attempted
- @e2e exclude backend literal-leak guard (inbound) — covered by PHPUnit

### Requirement: Push endpoint and signed inbound retour receiver (REQ-004)

`POST /api/iwmo-ijw/berichten` MUST let an authenticated NC session (a
sibling app's own adapter) register a toewijzing or declaratie, returning
`{ref, berichttype}` on success, HTTP 400 on missing required fields, and
HTTP 503 `not_configured` when no active `type=iwmo-ijw` source exists —
never a 500 crash. `POST /api/iwmo-ijw/retour` MUST verify the inbound
request's HMAC signature (mirrors `PeppolController::inbound()`'s
`WebhookSignatureService` usage) BEFORE any processing; an unsigned or
tampered request MUST return HTTP 401 with no state change. A verified
retour MUST always acknowledge `{received: true}`, even when translation or
persistence fails internally (logged, never a 500) — a real GGk delivery
pipeline may retry on any non-2xx response.

#### Scenario: a valid push request returns a ref and berichttype
- GIVEN an authenticated session and a configured `rest` or `log` iWMO/iJW source
- WHEN `POST /api/iwmo-ijw/berichten` is called with a complete toewijzing payload
- THEN HTTP 200 SHALL be returned with `{ref, berichttype: "Wmo303"|"Jw303"}`
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: a push request with no active source returns not_configured
- GIVEN no active `type=iwmo-ijw` source
- WHEN `POST /api/iwmo-ijw/berichten` is called
- THEN HTTP 503 `not_configured` SHALL be returned, no HTTP attempted
- @e2e exclude backend push endpoint — covered by PHPUnit

#### Scenario: an unsigned retour is rejected before any processing
- GIVEN a `POST /api/iwmo-ijw/retour` request with a missing or invalid signature header
- WHEN received
- THEN HTTP 401 SHALL be returned and no `iwmo_ijw_message` record SHALL be created, no OR case updated
- @e2e exclude backend webhook signature gate — covered by PHPUnit

#### Scenario: a verified retour always acknowledges receipt
- GIVEN a correctly signed retour whose `kenmerk` does not resolve to any known local message
- WHEN received
- THEN the endpoint SHALL still respond `{received: true}` (never a 500) and log the unresolved reference
- @e2e exclude backend never-500-on-verified-callback — covered by PHPUnit

### Requirement: Per-message audit persistence and isolated retry (REQ-005)

Every outbound send attempt and every inbound retour MUST persist one
`iwmo_ijw_message` OR record (`direction`, `berichttype`, `domain`,
`status`, `ref`, `kenmerk`, `caseReference`, `error`, `syncedAt`) —
observability for both legs, never merged into a single mutable row.
`IwmoIjwRetryJob` (hourly `TimedJob`, `allowParallelRuns=false`) MUST
re-attempt every `iwmo_ijw_message` row with `status: failed` or `pending`
through the same send path, with per-message isolation: one message's retry
exception MUST be logged and skipped without aborting the sweep. A sweep
with no eligible rows MUST be a clean no-op — no exception, no error log.

#### Scenario: a successful outbound send persists a sent record with its ref
- GIVEN a complete toewijzing push against the `log` provider
- WHEN `sendBericht()` completes
- THEN an `iwmo_ijw_message` record SHALL be persisted with `direction: outbound`, `status: sent`, and the provider-returned `ref`
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: a failed outbound send persists a failed record with the error and is retried later
- GIVEN a `rest` provider that raises `IwmoIjwProviderException` on send
- WHEN `sendBericht()` is called
- THEN an `iwmo_ijw_message` record SHALL be persisted with `status: failed` and `error` set
- AND WHEN `IwmoIjwRetryJob` next runs THEN `retryFailed()` SHALL re-attempt that record via the provider
- @e2e exclude backend retry job — covered by PHPUnit

#### Scenario: one failing retry does not abort the sweep
- GIVEN two failed `iwmo_ijw_message` rows, one of which raises on retry
- WHEN `retryFailed()` runs
- THEN the failing row SHALL be logged and skipped while the other row is still retried
- @e2e exclude backend per-message isolation — covered by PHPUnit

### Requirement: AVG/BSN hygiene — raw on the wire, hashed at rest (REQ-006)

The outbound Toewijzing envelope MUST carry the citizen's raw BSN (legally
required for a care provider to identify the client). The persisted
`iwmo_ijw_message` audit record MUST NEVER contain the raw BSN — it MUST be
SHA-256-hashed before the record is saved, consistent with
`AvgBsnPolicyRule`/`kiss-kcc-bridge` precedent.

#### Scenario: the sent envelope carries the raw BSN but the audit record does not
- GIVEN a toewijzing push with a raw BSN
- WHEN `sendBericht()` runs
- THEN the envelope handed to the provider SHALL contain the raw BSN
- AND the persisted `iwmo_ijw_message` record SHALL contain only a SHA-256 hash of it, never the raw value
- @e2e exclude backend AVG hygiene — covered by PHPUnit

