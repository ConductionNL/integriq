---
kind: code
depends_on: []
---

# Proposal: notifynl-sms-channel

## Summary

Add a generic SMS channel to OpenConnector — a narrow `SmsProviderInterface`
(send + delivery-status lookup) plus two bindings: `LogSmsProvider`
(sandbox/dev) and `RestNotifyNlProvider` (NotifyNL, the NL government
notification service and a GOV.UK Notify fork). Sibling apps (e.g. procest)
call `POST /api/notifynl/messages` directly over an authenticated NC session —
mirroring how shillinq consumes `PeppolController::participants()` — instead of
embedding an SMS gateway client. Delivery status is available both by polling
(`GET /api/notifynl/messages/{id}`) and via a signed inbound callback
(`POST /api/notifynl/inbound`), each persisting an `sms_message` OR record and
emitting `nl.conduction.sms.delivery.status` CloudEvents.

## Motivation

A research audit found that procest's citizen notifications go via email and
Berichtenbox only — no SMS gateway exists anywhere in the fleet, while
multi-channel communication (email/SMS/MijnOverheid/portal) is a standard
municipal expectation and NotifyNL integration is increasingly required in
tenders. Per ADR-022 integrations belong in openconnector, not as nc-vue leaves
and not re-implemented per app.

## Capabilities

- `notifynl-sms-channel` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new `SmsProviderInterface` abstraction with
  `Log` + `RestNotifyNlProvider` bindings, `SmsDispatchService`,
  `NotifyNlController`, an `sms_message` OR schema, and a pure
  `PhoneNumberValidator`.
- [ ] Project: `procest` — no code change here; procest's own local SMS-send
  adapter would target the endpoint this change introduces (documented
  cross-app contract only — out of scope for this change).

## Scope

### In Scope

- Generic provider contract: `SmsProviderInterface` + `DeliveryResult` value
  object, so MessageBird/Twilio adapters can follow later without touching the
  dispatch service or REST surface.
- `LogSmsProvider` (sandbox, no network/secret) and `RestNotifyNlProvider`
  (NotifyNL REST, JWT-signed per request).
- Pure `PhoneNumberValidator` — E.164 normalisation with NL default region, no
  `libphonenumber` dependency (composer.json checked first — not shipped).
- `POST /api/notifynl/messages` (send), `GET /api/notifynl/messages/{id}`
  (status poll), `POST /api/notifynl/inbound` (signed delivery-status
  callback).
- `sms_message` OR schema tracking the `queued→sent→delivered|failed`
  lifecycle, mirroring `peppol_transmission`.
- Credential hygiene: the NotifyNL API key is never stored in plaintext (see
  design.md for why this is ICrypto-encrypted rather than
  `credentialRef`/`BrokeredCallService`).

### Out of Scope

- MessageBird/Twilio provider implementations — the interface is designed for
  them but they are not built here.
- A settings UI for entering/rotating the NotifyNL API key — out of scope for
  this backend-focused change; the encrypted value is set via the existing
  config-write surface (occ/repair-step), same as any other source
  configuration write.
- procest's own consuming adapter — a cross-app contract this change defines
  and documents, not implements in procest.

## Approach

Model the SMS connection as an openconnector `Source` (`type=sms`) whose
`configuration` selects a provider (`log`|`notifynl`) and carries
`authentication.encryptedApiKey`, `senderId`, `templateMapping`. A narrow
`SmsProviderInterface` (`send`, `fetchStatus`) is implemented by
`LogSmsProvider` and `RestNotifyNlProvider`. `SmsDispatchService` resolves the
active source + provider, normalises the recipient to E.164
(`PhoneNumberValidator`), persists an `sms_message` OR object
(`status=queued`), calls the provider, and updates the record + emits
`nl.conduction.sms.delivery.status` on every transition. `NotifyNlController`
stays a thin HTTP/auth shell (mirrors `PeppolController`), exposing send,
status-poll, and a signature-gated inbound callback. Details in design.md,
including the credential-storage deviation from the Peppol/PSD2
`credentialRef` precedent.

## New Dependencies

None. Reuses `guzzlehttp/guzzle` and `web-token/jwt-framework` (already app
dependencies, the latter already used for JWT signing in
`AuthenticationService`/`LtiKeyService`), `EventService`,
`WebhookSignatureService`, `ActionAuthService`, and `OCP\Security\ICrypto`
(standard Nextcloud service, not previously used in this app but zero new
Composer dependency).

## Impact

- New: `lib/Service/Sms/{SmsProviderInterface,DeliveryResult,LogSmsProvider,
  RestNotifyNlProvider,PhoneNumberValidator}.php`,
  `lib/Service/SmsDispatchService.php`, `lib/Controller/NotifyNlController.php`,
  `lib/Exception/SmsProviderException.php`, `appinfo/routes.php` entries, an
  `sms_message` schema in `lib/Settings/openconnector_register.json`.
- Reused: `EventService`, `WebhookSignatureService`, `ActionAuthService`.
- Fixed in passing (pre-existing, encountered while editing the register
  file): `bankfeed_connection` and `bankfeed_batch` were declared in
  `components.schemas` but missing from the register's `schemas` list (the
  same class of gap already documented as fixed for `peppol_transmission`) —
  added alongside `sms_message`.

## Cross-Project Dependencies

- procest is the intended production consumer of `POST /api/notifynl/messages`
  (contract owned here; no procest code change in this PR).

## Risks

### Risk 1: NotifyNL's real API shape may differ from the documented GOV.UK Notify contract

**Severity:** Medium — **Mitigation:** the REST provider is implemented
against the documented, verified GOV.UK Notify contract (JWT auth, `POST
/v2/notifications/sms`, `GET /v2/notifications/{id}`) that NotifyNL is a fork
of; the `log`/sandbox provider makes the whole send→status path demonstrable
end-to-end without a real credential, and the provider seam isolates any future
correction to `RestNotifyNlProvider` alone.

### Risk 2: Credential storage deviates from the Peppol/PSD2 precedent

**Severity:** Medium — **Mitigation:** documented explicitly in design.md and
REQ-004, with the concrete code-level evidence (`CredentialBrokerService::
injectAuth()`) for why `credentialRef` cannot express NotifyNL's per-request
computed JWT; `ICrypto` is a standard, audited Nextcloud primitive already used
fleet-wide for comparable at-rest secrets.

### Risk 3: Inbound callback authenticity

**Severity:** Medium — **Mitigation:** the inbound webhook MUST be gated by
the same HMAC `webhook_signature` scheme as `PeppolController::inbound()`
before any state change.

## Rollback Strategy

The connector is additive. Revert by removing the new controller/services/
routes and the `sms_message` schema entry; no existing source, sync, rule, or
event behaviour changes, so removal cannot regress current integrations.

## Open Questions

None blocking — the sandbox provider makes the change self-contained. A
MessageBird/Twilio binding and a settings UI for the NotifyNL credential are
explicitly deferred (see Out of Scope).
