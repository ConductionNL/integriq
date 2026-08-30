# Design: notifynl-sms-channel

## Architecture Overview

```
                    sibling app (e.g. procest)
                              │
        POST /api/notifynl/messages   GET /api/notifynl/messages/{id}
                              │                     │
                              ▼                     ▼
                    NotifyNlController ──► SmsDispatchService ──► SmsProviderInterface
                              │                     │                  ├─ LogSmsProvider (sandbox)
                              │          persists   │                  └─ RestNotifyNlProvider ─► NotifyNL REST API
                              │        sms_message (OR)                    (JWT-signed, own Guzzle client)
                              │                     │
                              │       emits nl.conduction.sms.delivery.status
                              ▼
   POST /api/notifynl/inbound ──► webhook_signature verify ──► SmsDispatchService.handleStatusCallback
```

Send and status-poll are authenticated NC-session calls (mirrors
`PeppolController::participants()` — the production binding for shillinq).
Inbound delivery-status callbacks are signature-gated (mirrors
`PeppolController::inbound()`). The one structural difference from Peppol/PSD2
is credential dispatch — see "Credential storage: why not `credentialRef`"
below.

## API Design

### `POST /api/notifynl/messages`
**Request:**
```json
{ "to": "0612345678", "templateId": "reminder", "personalisation": {"name": "Jan"}, "sourceApp": "procest", "objectUri": "/objects/zaak/1" }
```
**Response:** the created `sms_message` (`id`, `status`, `providerMessageId`, ...).

### `GET /api/notifynl/messages/{id}`
**Response:** the (possibly updated) `sms_message` after polling the provider.

### `POST /api/notifynl/inbound`
Gated by the same HMAC `webhook_signature` scheme as
`PeppolController::inbound()`.
```json
{ "providerMessageId": "notify-id-1", "status": "delivered", "detail": "" }
```
**Response:** `{ "received": true }` (HTTP 200); HTTP 401 on signature failure.

## Database Changes

One new OR schema `sms_message` added declaratively to
`lib/Settings/openconnector_register.json` (register `openconnector`),
field-for-field mirroring `peppol_transmission`'s shape (`status` enum,
`attempts[]` audit trail, `x-openregister-notifications` on `failed`). No SQL
migration — persisted as an OpenRegister object.

## Credential storage: why not `credentialRef`/`BrokeredCallService`

The Peppol/PSD2 precedent stores the third-party API key via
`configuration.authentication.credentialRef`, resolved and injected in-process
by OpenRegister's `CredentialBrokerService` so the secret never enters
openconnector's process at all. This was the first design tried here too, but
it does not fit NotifyNL's auth scheme, for a reason verified directly against
code, not assumed:

**NotifyNL requires a freshly-computed, time-bound value per request.**
Verified against the documented GOV.UK Notify contract (which NotifyNL forks):
every request carries `Authorization: Bearer <jwt>` where the JWT is an HS256
token, payload `{iss: serviceId, iat: now}`, signed with the second half of the
API key, generated fresh for every single call.

**`CredentialBrokerService::injectAuth()` cannot produce that.** Read at HEAD
in `openregister/lib/Service/Credential/CredentialBrokerService.php`:

```php
private function injectAuth(array $provider, array $headers, string $secret): array
{
    $scheme     = ($provider['authScheme'] ?? []);
    $headerName = (string) ($scheme['header'] ?? 'Authorization');
    $template   = (string) ($scheme['template'] ?? '{secret}');
    // Discard any caller attempt to set the auth header or the Host header.
    ...
    $sanitised[$headerName] = str_replace('{secret}', $secret, $template);
    return $sanitised;
}
```

This is a **static, single-placeholder string substitution** that
unconditionally **discards any caller-supplied `Authorization` header** — it
can forward a raw, static secret verbatim into a header template
(`Bearer {secret}`), but it has no way to *compute* a value (an HMAC-signed
JWT with a fresh timestamp claim). There is also no other public method on
`CredentialBrokerService`/`BrokeredCallService` that returns the raw secret to
a caller for local computation — by design, per ADR-007, the secret is meant
to never enter the consuming app's process at all.

This is the **same structural limitation** `BrokeredCallService` already
documents and excludes for other auth shapes it cannot express — its own
`assertScopeGuards()` rejects SOAP, asynchronous dispatch, and TLS client
certificates from `credentialRef`'s "v1 scope" for the identical reason:
computed/derived auth material, not a static bearer substitution.

**Decision:** store the NotifyNL API key at
`configuration.authentication.encryptedApiKey`, encrypted via Nextcloud's
`OCP\Security\ICrypto` (AES + HMAC, keyed off the instance secret — the same
primitive Nextcloud core itself uses for comparable at-rest application
secrets). `RestNotifyNlProvider` decrypts it in-process only for the instant
needed to sign each request's JWT; the plaintext is never logged, never
persisted, and never returned from any method. This is a **better-than-
existing-precedent** choice: this app's own LTI signing keys are documented as
"plaintext-pending-encryption" tech debt (`LtiKeyService`, citing
`AuthenticationService::fetchJWTToken`'s status quo) — NotifyNL's key does not
repeat that debt.

**Alternatives considered and rejected:**
- *Reuse `credentialRef` anyway, sending only a static bearer key.* Would
  satisfy "never cleartext" but would not work against NotifyNL's real API,
  which rejects a raw key and requires the JWT — shipping a broken
  integration is worse than a documented, correct deviation.
- *Extend OpenRegister's provider catalogue with a computed `hmacJwt`
  authScheme mode.* The structurally "right" long-term fix, but it is a change
  to a different app/repo (openregister), out of scope for this change; noted
  as a follow-up, not a blocker (the sandbox provider makes this change
  self-contained without it).
- *Reuse the legacy embedded-secret `AuthenticationService`/`CallService`
  path* (used by LTI's own signing keys today). Rejected because that path
  stores the secret in plaintext in the OR object — directly violating the
  "never cleartext" requirement for this change.

## Alternatives Considered (feature shape)

- **Event-driven outbound (mirroring Peppol's
  `nl.conduction.peppol.outbound.requested` consumer)** was considered for
  send, but rejected for v1: NotifyNL's send call is a simple, fast
  request/response (unlike Peppol's AS4/AP hand-off), and sibling apps need
  the `sms_message` id back immediately to correlate/display send state — a
  direct authenticated REST call (mirroring `peppol#participants`, which is
  also synchronous) fits better than round-tripping through the event bus.
  `nl.conduction.sms.delivery.status` events are still emitted on every status
  change so subscribers can react asynchronously regardless.
- **A dedicated `SmsStatusPollJob` cron** (mirroring `BankfeedSyncJob`) was
  considered for delivery-status sweeps but deferred: NotifyNL supports a
  delivery-status webhook (used here via `POST /api/notifynl/inbound`, the
  primary status path) and an on-demand poll (`GET
  /api/notifynl/messages/{id}`) covers the "check now" case; a scheduled sweep
  can be added later without changing `SmsProviderInterface` or
  `SmsDispatchService`.
