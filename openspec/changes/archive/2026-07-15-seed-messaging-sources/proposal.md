---
kind: config
status: proposed
---

## Why

Pipelinq ships outbound-messaging adapters — `SmsAdapter` (CM.com /
MessageBird / Twilio) and `WhatsAppAdapter` (Meta Cloud API + a BSP
fallback) — whose per-provider transport clients
(`TwilioSmsClient`, `MessageBirdSmsClient`, `CmComSmsClient`,
`WhatsAppProviderClient`) are designed to **never hold a vendor SDK**: each
delegates the raw HTTP send to OpenConnector, keyed by an OpenConnector
`source` id carried on the pipelinq `channelProvider` row (ADR-005 /
ADR-022). The companion OpenRegister outbound-messaging dispatch leaf
(`MessageDispatchProvider`, coded in the paired OR change) routes the POST
through the `ExternalIntegrationRouter` → `CallService::call`, resolving the
source via `OCA\OpenConnector\Db\SourceMapper`.

**Those sources do not exist.** With zero matching `source` objects seeded,
every messaging dispatch resolves to `openconnector-source-missing`. The
adapters are dead-on-arrival not because of a code gap but because the
connection rows were never seeded — exactly the gap the `kvk` /
`opencorporates` / `brp-haalcentraal` seeds already closed for the read-side
lookup leaves.

This is the OpenConnector half of the OR/OpenConnector groundwork for
pipelinq's outbound messaging: centralise only the provider **credentials +
base URL** in admin-owned OpenConnector sources. Provider selection, the
STOP/opt-out webhook receiver, WhatsApp template-approval, the 24h session
window, dedupe and delivery-status reconciliation all stay in pipelinq — this
change ships only the dormant transport rows.

## What Changes

- **Seed five pre-built `source` objects** as OpenRegister objects (register
  `openconnector`, schema `source`), all `isEnabled: false` (dormant), all
  carrying the production base URL + the vendor's auth shape **without** a
  secret:
  - `cmcom-sms` — CM.com Business Messaging (`https://gw.cmtelecom.com/v1.0`),
    `auth: apikey` via an `X-CM-PRODUCTTOKEN` header.
  - `messagebird-sms` — MessageBird/Bird REST
    (`https://rest.messagebird.com`), `auth: apikey` via an
    `Authorization: AccessKey {key}` header.
  - `twilio-sms` — Twilio Messaging API (`https://api.twilio.com`),
    `auth: apikey` via HTTP Basic (`authentication.username` = AccountSID,
    `authentication.password` = AuthToken); the AccountSID also rides the
    operator-composed send path.
  - `whatsapp-cloud-api` — Meta WhatsApp Cloud API Graph
    (`https://graph.facebook.com/v19.0`), `auth: apikey` via an
    `Authorization: Bearer {token}` header; the Phone-Number-ID rides the
    operator-composed send path.
  - `whatsapp-bsp` — generic WhatsApp BSP relay (default base CM.com WhatsApp
    `https://api.whatsapp.cm.com`), `auth: apikey`; operators repoint the
    `location` + credential header at their chosen BSP.
- Ship them as **ADR-037 register fragments** under
  `lib/Settings/register.d/*-source.json` (each a `components.objects`
  array), so `InitializeRegister` folds them into `openconnector_register.json`
  on `occ app:enable`/upgrade and OpenRegister's `ImportHandler` materialises
  them idempotently by `@self.slug`.

This is **kind: config** — declarative seed fragments. No PHP changes in
OpenConnector: `SourceMapper` + `CallService` already do the work; they were
just missing the rows.

## Capabilities

### Modified Capabilities
- `source-management`: gains a requirement that OpenConnector seeds pre-built,
  dormant outbound-messaging sources (`cmcom-sms`, `messagebird-sms`,
  `twilio-sms`, `whatsapp-cloud-api`, `whatsapp-bsp`) on install so the
  OpenRegister outbound-messaging dispatch leaf — and pipelinq's per-provider
  clients — resolve a base URL out of the box.

## Impact

- **Config:** five new fragments under `lib/Settings/register.d/`.
- **Behaviour:** after `occ app:enable openconnector` (or upgrade), `source`
  objects with the five slugs exist; a dispatch attempt resolves the source
  and degrades to `{ unavailable, cause: 'upstream-service-down' }` (dormant,
  no key) rather than `openconnector-source-missing`, until an operator sets
  the credential and enables the source.
- **Consumers:** OpenRegister `MessageDispatchProvider` (paired OR change);
  pipelinq `SmsAdapter` / `WhatsAppAdapter` per-provider clients.
- **Secrets:** none — placeholder base URLs + `auth: apikey` without a key.
- **SSRF:** none — the base URL is admin-owned on each source; only the
  message body/recipient travels in the POST body (the operator composes any
  account-id/phone-number-id path segment).
