---
status: proposed
---

# Source Management

## ADDED Requirements

### Requirement: Pre-built outbound-messaging source seeds

OpenConnector SHALL seed five pre-built `source` objects (register
`openconnector`, schema `source`) on app install/upgrade — `cmcom-sms`,
`messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`, and `whatsapp-bsp` — so
the OpenRegister outbound-messaging dispatch leaf (which routes through
`OCA\OpenConnector\Db\SourceMapper::find(<slug>)` →
`ExternalIntegrationRouter` → `CallService::call`) and pipelinq's per-provider
SMS/WhatsApp transport clients resolve a base URL out of the box. Each seed
SHALL ship dormant (`isEnabled: false`) with the production REST base URL as
`location` and the vendor's auth shape (`auth: "apikey"` with an empty
credential placeholder), so a fresh install never carries a secret and the
dispatch leaf degrades gracefully until an operator configures the credential
and enables the source.

All five seeds SHALL be delivered as ADR-037 register fragments
(`lib/Settings/register.d/cmcom-sms-source.json`,
`messagebird-sms-source.json`, `twilio-sms-source.json`,
`whatsapp-cloud-api-source.json`, `whatsapp-bsp-source.json`, each a
`components.objects` array) so they are merged into the register descriptor by
`InitializeRegister` and materialised idempotently by `@self.slug` via
OpenRegister's `ImportHandler`. No end-user input SHALL reach the request base
URL — the base URL is admin-owned on the source and only the message
body/recipient travels in the POST body — so there is no SSRF surface.

#### Scenario: messaging sources materialise on install

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs `InitializeRegister`
- THEN `source` objects with `@self.slug` of `cmcom-sms`, `messagebird-sms`,
  `twilio-sms`, `whatsapp-cloud-api`, and `whatsapp-bsp` exist in register
  `openconnector`, schema `source`, each with `auth = "apikey"` and
  `isEnabled = false`
- @e2e exclude Backend seed materialisation — verified by Newman/PHPUnit against the OR object API, not a browser flow.

#### Scenario: seed re-import is idempotent

- GIVEN the five messaging sources already exist from a prior install
- WHEN `InitializeRegister` runs again
- THEN no duplicate messaging source is created (matched by `@self.slug`)
- @e2e exclude Backend idempotency — verified by Newman/PHPUnit, not a browser flow.

#### Scenario: OR messaging dispatch no longer reports source-missing

- GIVEN the seeded dormant messaging sources exist
- WHEN the OpenRegister outbound-messaging dispatch leaf resolves one of the
  five sources for a send
- THEN the resolution succeeds (the source is found) and the dispatch degrades
  to `{ unavailable: true, cause: 'upstream-service-down' }` rather than a
  `openconnector-source-missing` failure
- @e2e exclude Cross-app backend behaviour — verified against the OR dispatch endpoint, not a browser flow.
