# dso-connector-adapter Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- dso-connector-adapter

## Purpose

Completes OpenConnector's DSO (Digitaal Stelsel Omgevingswet) connector: the
pre-existing STAM koppelvlak inbound webhook (signature verification +
payload parsing) is wired to actually persist every received Verzoek,
declare OpenRegister's shipped semantic-object-handoff engine (`ns#Case`,
ADR-051) as the case-intake path, and post status/besluit updates back to
DSO-LV. DSO is mandatory since January 2024 for Dutch municipalities
exchanging permit/Omgevingswet data. Per ADR-022, integrations live in
openconnector, not as an nc-vue leaf and not per-app. This spec covers the
CONNECTOR/translation layer only — DSO-LV certification and the
production/pre-productie endpoint are explicitly out of scope (see
design.md "Open Questions").

## ADDED Requirements

### Requirement: DSO outbound provider abstraction with log and REST bindings (REQ-001)

OpenConnector MUST expose a `DsoConnectorProviderInterface` seam through
which every outbound DSO message (`status` or `besluit`) is dispatched,
selected at runtime via the active `type=dso` source's
`configuration.provider` (`log`|`rest`), defaulting to the sandbox `log`
provider so an unconfigured deployment never dispatches a live call. The
`rest` binding (`DsoClient`) MUST send an `Authorization: Bearer <token>`
header built from `configuration.authentication.encryptedToken`, decrypted
via `OCP\Security\ICrypto` (never a plaintext fallback), and route
`type=status` to `{baseUrl}/statussen` and `type=besluit` to
`{baseUrl}/besluiten`.

#### Scenario: The log provider sends nothing over the network and returns a synthetic ref

- GIVEN a `type=dso` source with no `configuration.provider` set (or
  `provider` absent entirely)
- WHEN an outbound status/besluit dispatch is attempted
- THEN the sandbox `log` provider is used, no network call is made, and a
  synthetic `MOCK-DSO-<n>` reference is returned
- @e2e exclude backend provider seam — covered by PHPUnit

#### Scenario: The REST provider sends the expected Bearer auth header

- GIVEN a `type=dso` source with `configuration.provider=rest` and a valid
  `encryptedToken`
- WHEN an outbound status dispatch is attempted
- THEN the request carries `Authorization: Bearer <decrypted token>` and is
  POSTed to `{baseUrl}/statussen`
- @e2e exclude backend HTTP client — covered by PHPUnit (MockHandler)

#### Scenario: A missing credential fails before any request is dispatched

- GIVEN a `type=dso` source with `configuration.provider=rest` and no
  `authentication.encryptedToken`
- WHEN an outbound dispatch is attempted
- THEN `DsoProviderException` is thrown and no HTTP request is made
- @e2e exclude backend provider seam — covered by PHPUnit

### Requirement: Inbound Verzoek translation with a literal-leak guard (REQ-002)

OpenConnector MUST translate a parsed DSO Verzoek
(`DSOParserService::parseVerzoek()`'s output) into the normalised
`mappedTitle`/`mappedSummary`/`mappedChannel`/`mappedPriority` fields a
`dso_verzoek` record carries, via a FIXED (not admin-configurable)
translator. A Verzoek with a missing or empty `verzoekId` — the correlation
reference the outbound leg depends on — MUST raise
`DsoTranslationException` before any normalised mapping is returned; the
caller MUST NEVER fabricate or guess a `verzoekId`.

#### Scenario: A full aanvraag Verzoek translates to mapped

- GIVEN a parsed Verzoek with `type=aanvraag`, at least one activiteit
  carrying an `omschrijving`, a `projectbeschrijving`, and an `aanvrager.bsn`
- WHEN the Verzoek is translated
- THEN `mappedTitle` is the first activiteit's omschrijving, `mappedSummary`
  includes every activiteit label plus the projectbeschrijving,
  `mappedChannel` is `omgevingsloket`, `mappedPriority` is `hoog`, and
  `requester.bsn` is populated
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: A partial melding Verzoek still translates with fallbacks

- GIVEN a parsed Verzoek with `type=melding`, one activiteit with only a
  `code` (no `omschrijving`), no `projectbeschrijving`, no `aanvrager`
- WHEN the Verzoek is translated
- THEN `mappedTitle`/`mappedSummary` fall back to the activiteit code,
  `mappedPriority` is `normaal`, and `requester.bsn` is `null` — translation
  succeeds, it is not a failure for merely-thin (but valid) data
- @e2e exclude backend translation — covered by PHPUnit

#### Scenario: A Verzoek with no verzoekId is refused, not fabricated

- GIVEN a parsed Verzoek with no `verzoekId` key (or an empty string)
- WHEN the Verzoek is translated
- THEN `DsoTranslationException` is raised naming the missing `verzoekId`,
  and no normalised mapping is returned — the correlation reference is
  never guessed
- @e2e exclude backend literal-leak guard — covered by PHPUnit

### Requirement: `dso_verzoek` lifecycle with per-verzoek isolation (REQ-003)

OpenConnector MUST persist every DSO Verzoek received on the signed STAM
koppelvlak webhook as a `dso_verzoek` OR record with lifecycle
`received → mapped → handed_off | failed`, isolated per verzoek — a
translation failure on one verzoek MUST NOT affect another verzoek received
before or after it. This fixes the pre-existing gap where
`DSOController::receiveVerzoek()` logged and discarded every verzoek with no
persistence at all. A persistence/mapping failure MUST be logged and MUST
NOT turn the webhook's HTTP 202 Accepted acknowledgement into an error
response (the STAM koppelvlak's documented asynchronous-processing
contract).

#### Scenario: Successful ingest reaches mapped

- GIVEN a signed, valid DSO Verzoek payload with a populated activiteit
- WHEN the STAM koppelvlak receives it
- THEN a `dso_verzoek` record is persisted with `status=mapped` and the
  normalised `mappedTitle`/`mappedSummary`/`mappedChannel`/`mappedPriority`
  fields populated
- @e2e exclude backend persistence — covered by PHPUnit

#### Scenario: A translation failure isolates to one verzoek

- GIVEN one Verzoek missing `verzoekId` (fails translation) followed by a
  second, valid Verzoek
- WHEN both are ingested in sequence
- THEN the first `dso_verzoek` record has `status=failed` with
  `errorDetail` set, the second has `status=mapped`, and the two records
  have distinct uuids — the failure of the first never affects the second
- @e2e exclude backend isolation — covered by PHPUnit

#### Scenario: An ingest failure never breaks the webhook's 202 acknowledgement

- GIVEN a signed, schema-valid DSO Verzoek payload
- WHEN persistence/translation throws unexpectedly during ingest
- THEN the STAM koppelvlak still returns HTTP 202 Accepted (the failure is
  logged, not surfaced as a non-202 response) — the pre-existing
  asynchronous-processing contract is preserved
- @e2e exclude backend webhook contract — covered by PHPUnit

### Requirement: REST surface to list and complete mapped Verzoeken (REQ-004)

OpenConnector MUST expose an authenticated (NC-session, `ActionAuthService`-
gated) REST surface: `GET /api/dso/verzoeken` (optionally filtered by
`?status=`), `GET /api/dso/verzoeken/{id}` (single-record status read). Both
MUST return HTTP 401 for an unauthenticated caller.

#### Scenario: Listing verzoeken filters by status

- GIVEN two `dso_verzoek` records, one `status=mapped` and one
  `status=failed`
- WHEN an authenticated caller requests `GET /api/dso/verzoeken?status=mapped`
- THEN only the `mapped` record is returned
- @e2e exclude backend REST surface — covered by PHPUnit

#### Scenario: Unauthenticated access is rejected

- GIVEN no active NC user session
- WHEN `GET /api/dso/verzoeken` or `GET /api/dso/verzoeken/{id}` is called
- THEN the endpoint returns HTTP 401 with no data disclosed
- @e2e exclude backend auth gate — covered by PHPUnit

### Requirement: Declared `ns#Case` handoff, executed by a real authenticated actor (REQ-005)

`dso_verzoek` MUST declare `x-openregister-handoff` (`verzoek-to-case`)
targeting `https://openregister.app/ns#Case`, `trigger: manual`,
`whenUnavailable: queue`. `POST /api/dso/verzoeken/{id}/handoff` MUST
execute this handoff via OpenRegister's `HandoffService::execute()` under
the calling (real, authenticated) user's own RBAC — never a system-account
shortcut, matching HandoffService v1's documented no-system-user-privilege-
lane constraint (identical binding decision to `open-formulieren-intake`).
The endpoint MUST reject a verzoek not yet in `status=mapped`, and MUST mark
the verzoek `status=failed` (isolated to that verzoek) before rethrowing on
any handoff-execution failure.

#### Scenario: Authenticated handoff succeeds

- GIVEN a `dso_verzoek` with `status=mapped`
- WHEN an authenticated caller with the `dso.handoff` action posts
  `POST /api/dso/verzoeken/{id}/handoff`
- THEN a `ns#Case` object is created under the caller's own RBAC, the
  verzoek's `status` becomes `handed_off`, and `correlationId`/`targetCase`
  are recorded
- @e2e exclude backend handoff orchestration — covered by PHPUnit

#### Scenario: Handoff is rejected for a not-yet-mapped verzoek

- GIVEN a `dso_verzoek` with `status=received` (translation not yet
  complete, or still `failed`)
- WHEN the handoff endpoint is called
- THEN the endpoint returns HTTP 400 and no handoff is executed
- @e2e exclude backend handoff guard — covered by PHPUnit

#### Scenario: A handoff-execution failure isolates to the triggering verzoek

- GIVEN a `dso_verzoek` with `status=mapped` and OpenRegister's
  `HandoffService::execute()` throwing `HandoffException`
- WHEN the handoff endpoint is called
- THEN the verzoek's `status` becomes `failed` with `errorDetail` set, and
  the original exception propagates to the caller as an error response —
  no other verzoek is affected
- @e2e exclude backend failure isolation — covered by PHPUnit

### Requirement: Outbound status/besluit post with per-message audit (REQ-006)

OpenConnector MUST expose `POST /api/dso/verzoeken/{id}/status`
(authenticated, `dso.status-post` action) which resolves the active
`type=dso` source and its configured provider, dispatches a `status` or
`besluit` message via
`DsoConnectorProviderInterface::send()`, and persist a `dso_message` audit
row for every attempt — on success (`status=sent`, `ref` populated) and on
provider failure (`status=failed`, `error` populated) alike, correlated to
its `dso_verzoek` via `verzoekUuid`. A request with no active `type=dso`
source configured MUST return HTTP 503 without attempting a dispatch.

#### Scenario: Outbound dispatch succeeds via the default log provider

- GIVEN an enabled `type=dso` source with no `provider` configured
- WHEN an authenticated caller posts a status update for a `dso_verzoek`
- THEN the response reports `status=sent` with a synthetic `MOCK-DSO-<n>`
  ref, and a `dso_message` record is persisted with `type=status`,
  `status=sent`, and the correlated `verzoekUuid`
- @e2e exclude backend outbound dispatch — covered by PHPUnit

#### Scenario: No active DSO source fails closed with 503

- GIVEN no enabled `type=dso` source exists
- WHEN an authenticated caller posts an outbound status/besluit update
- THEN the endpoint returns HTTP 503 and no `dso_message` record is created
- @e2e exclude backend not-configured path — covered by PHPUnit

#### Scenario: A provider failure persists a failed dso_message and surfaces an error

- GIVEN an enabled `type=dso` source configured with `provider=rest`, and
  the REST provider raising `DsoProviderException`
- WHEN an authenticated caller posts an outbound besluit update
- THEN a `dso_message` record is persisted with `status=failed` and the
  `error` detail set BEFORE the exception is rethrown, and the endpoint
  returns HTTP 502
- @e2e exclude backend failure audit — covered by PHPUnit
