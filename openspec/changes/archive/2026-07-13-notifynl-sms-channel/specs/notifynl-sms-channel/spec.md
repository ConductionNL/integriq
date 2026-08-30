# notifynl-sms-channel Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- notifynl-sms-channel

## Purpose

OpenConnector gains a generic SMS channel so sibling apps (e.g. procest) can
send citizen notifications by SMS without embedding a gateway client of their
own. Per ADR-022 integrations live in openconnector, not as nc-vue leaves and
not per-app. A narrow `SmsProviderInterface` (send + delivery-status lookup)
lets multiple gateway vendors implement the same contract; this change ships a
`log`/sandbox binding and a `notifynl` binding (NotifyNL — the NL government
notification service, a GOV.UK Notify fork) so MessageBird/Twilio adapters can
follow later without touching the dispatch service or the REST surface.

## ADDED Requirements

### Requirement: Generic SMS provider contract (REQ-001)

OpenConnector MUST define an `SmsProviderInterface`
(`lib/Service/Sms/SmsProviderInterface.php`) with `getProviderId()`,
`getProviderName()`, `getConfigSchema()`, `send(sourceConfiguration, to, body,
options): DeliveryResult`, and `fetchStatus(sourceConfiguration,
providerMessageId): DeliveryResult`. `DeliveryResult` is an immutable value
object (`providerMessageId`, `status`, `detail`) whose `status` is one of
`queued|sent|delivered|failed` — the vocabulary every binding normalises its
own vendor-specific statuses onto, so the dispatch service and REST surface
never depend on a specific vendor's status strings. A source's
`configuration.provider` selects the binding at runtime, mirroring
`PeppolAccessPointProviderInterface` / `Psd2AggregatorProviderInterface`.

#### Scenario: the interface is the single seam for adding a new gateway vendor

- GIVEN a future MessageBird or Twilio adapter
- WHEN it implements `SmsProviderInterface`
- THEN it SHALL be selectable via `configuration.provider` with no change to
  `SmsDispatchService` or `NotifyNlController`
- @e2e exclude backend provider seam — covered by PHPUnit

### Requirement: Log and NotifyNL REST provider bindings (REQ-002)

The connector MUST ship two bindings: `LogSmsProvider` (`log`), a sandbox
binding that performs no real network call, needs no credential, and returns a
synthetic `MOCK-SMS-<n>` message id from `send()`; and `RestNotifyNlProvider`
(`notifynl`), a REST binding against NotifyNL's `POST /v2/notifications/sms`
and `GET /v2/notifications/{id}` endpoints. `log` MUST remain usable with no
configuration and MUST be the default when `configuration.provider` is absent.

#### Scenario: the log provider sends without a network call or secret

- GIVEN an SMS source with `configuration.provider: log`
- WHEN a message is sent
- THEN a synthetic `MOCK-SMS-<n>` id SHALL be returned with no outbound HTTP
  call and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

### Requirement: NotifyNL REST provider with JWT-signed requests (REQ-003)

`RestNotifyNlProvider` MUST authenticate every request the way NotifyNL's real
API (a GOV.UK Notify fork) requires: a fresh HS256 JWT per request, payload
`{iss: serviceId, iat: now}`, signed with the API key's second UUID segment,
sent as `Authorization: Bearer <jwt>`. `send()` MUST post
`{phone_number, template_id, personalisation}` (plus `sms_sender_id` when
`configuration.senderId` is set) to `/v2/notifications/sms` and return the
provider's `id` as `providerMessageId` with `status=queued`. `templateId` MAY
be a logical name resolved via `configuration.templateMapping`. A non-2xx
upstream response or a transport failure MUST raise a descriptive
`SmsProviderException`, never a 500 crash. No `notifynl`/`gov-uk-notify` SDK
dependency is added — the two-endpoint contract is implemented directly against
`guzzlehttp/guzzle` and `web-token/jwt-framework` (both already app
dependencies).

#### Scenario: the rest provider signs a fresh JWT per request

- GIVEN a NotifyNL source with a valid `authentication.encryptedApiKey`
- WHEN a message is sent
- THEN the request SHALL carry `Authorization: Bearer <jwt>` with `alg=HS256`
  and `iss` equal to the API key's service-id segment
- @e2e exclude backend JWT signing — covered by PHPUnit

#### Scenario: an upstream error is mapped, never a crash

- GIVEN NotifyNL responds with a non-2xx status
- WHEN `send()` or `fetchStatus()` processes the response
- THEN a descriptive `SmsProviderException` SHALL be raised, not an unhandled 500

### Requirement: NotifyNL credential handling — encrypted at rest, never plaintext (REQ-004)

The NotifyNL API key MUST NOT be stored as plaintext in source configuration,
exports, logs, or error messages. It is stored at
`configuration.authentication.encryptedApiKey`, encrypted via Nextcloud's
`OCP\Security\ICrypto`, and decrypted in-process only for the instant needed to
sign each request's JWT. This is a deliberate, documented divergence from the
Peppol/PSD2 `credentialRef`/`BrokeredCallService` precedent: verified against
OpenRegister's `CredentialBrokerService::injectAuth()` at HEAD, the broker's
auth injection is a static single-placeholder substitution that unconditionally
discards any caller-supplied `Authorization` header — it can forward a raw,
static secret verbatim but cannot compute a derived, time-bound value such as a
per-request JWT. This is the same structural limit that already excludes
SOAP/async/TLS-client-cert sources from `credentialRef`'s "v1 scope"
(`BrokeredCallService::assertScopeGuards()`). See design.md for the full
analysis. A missing or undecryptable credential MUST fail closed with an
actionable, secret-free error; there is no plaintext-key fallback.

#### Scenario: the API key never appears in config, exports, or logs

- GIVEN a NotifyNL source with `authentication.encryptedApiKey` set
- WHEN a message is sent or its status polled
- THEN the raw API key SHALL NOT appear in source configuration reads,
  exports, logs, or error messages
- @e2e exclude backend credential hygiene — covered by PHPUnit

#### Scenario: a missing credential fails closed with no plaintext fallback

- GIVEN a NotifyNL source with no `authentication.encryptedApiKey`
- WHEN a send is attempted
- THEN it SHALL fail with an actionable config error and no plaintext-key
  fallback SHALL be used
- @e2e exclude backend credential hygiene — covered by PHPUnit

### Requirement: E.164 phone validation with NL default region (REQ-005)

The connector MUST provide a pure, dependency-free `PhoneNumberValidator`
(`toE164()`, `isValidE164()`) that normalises a raw recipient number to E.164,
defaulting a bare national-format number (leading `0`) to a configurable
calling code (NL `31` by default). It MUST also accept already-E.164 numbers,
`00`-international-prefixed numbers, and the common `+31 (0)6...` display
convention (trunk-prefix hint in parentheses). A number that cannot be
normalised to valid E.164 MUST cause `SmsDispatchService::sendMessage()` to
fail before any provider call or persistence.

#### Scenario: a national-format NL number is normalised to E.164

- GIVEN the raw number `0612345678`
- WHEN it is normalised with the default NL calling code
- THEN the result SHALL be `+31612345678`
- @e2e exclude backend pure validator — covered by PHPUnit

#### Scenario: an unnormalisable number is rejected before any send

- GIVEN a recipient value that yields no valid E.164 candidate
- WHEN `sendMessage()` is called
- THEN it SHALL fail with a descriptive error and no `sms_message` record or
  provider call SHALL be made
- @e2e exclude backend input validation — covered by PHPUnit

### Requirement: Send endpoint consumable by sibling apps (REQ-006)

OpenConnector MUST expose `POST /api/notifynl/messages` as an authenticated
NC-session endpoint that sibling apps (e.g. procest) call directly — mirroring
`PeppolController::participants()`'s consumption pattern for shillinq. The
endpoint accepts `{to, body, templateId, personalisation, sourceApp,
objectUri}`, validates `to` to E.164, dispatches through the resolved
provider, persists an `sms_message` record, and returns it. It MUST be gated
by `ActionAuthService::requireAction()` (`sms.send`, default-deny to admin) per
ADR-023. A provider/config failure MUST return a descriptive 4xx/502 error
envelope, never a 500.

#### Scenario: a sibling app sends a templated SMS over REST

- GIVEN an authenticated session and a configured SMS source
- WHEN `POST /api/notifynl/messages` is called with `{to, templateId,
  personalisation}`
- THEN the response SHALL include the created `sms_message`'s `id`,
  `providerMessageId`, and `status`
- @e2e exclude backend send endpoint — covered by PHPUnit

### Requirement: Delivery-status polling and callback (REQ-007)

OpenConnector MUST expose `GET /api/notifynl/messages/{id}` (authenticated,
`sms.status` action) which polls the provider's `fetchStatus()` and persists
any status change, and `POST /api/notifynl/inbound`, a signed webhook (same
HMAC scheme as `webhook_signature`, mirroring `PeppolController::inbound()`)
that applies a verified provider delivery-status callback to the matching
`sms_message`. Every status change MUST emit
`nl.conduction.sms.delivery.status` via `EventService`. An unsigned/tampered
callback MUST be rejected 401 before any state change. A callback for an
unknown `providerMessageId` MUST be recorded and MUST NOT 500.

#### Scenario: polling picks up a delivered status

- GIVEN a message in `sent` state whose provider reports `delivered`
- WHEN `GET /api/notifynl/messages/{id}` is called
- THEN the message SHALL be persisted `status=delivered` and one
  `nl.conduction.sms.delivery.status` event SHALL be emitted
- @e2e exclude backend status poll — covered by PHPUnit

#### Scenario: an unsigned inbound callback is rejected before any side effect

- GIVEN an inbound callback whose signature is missing or does not verify
- WHEN it arrives at `POST /api/notifynl/inbound`
- THEN the response SHALL be HTTP 401 and no message SHALL change status
- @e2e exclude backend signature gate — covered by PHPUnit
