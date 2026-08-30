# Tasks — seed-messaging-sources

## 1. Seed fragments

- [x] 1.1 Add `lib/Settings/register.d/cmcom-sms-source.json` — ADR-037 register
      fragment, one `source` object: `@self.slug = "cmcom-sms"`, `type: "api"`,
      `location: "https://gw.cmtelecom.com/v1.0"`, `auth: "apikey"`,
      `configuration.headers` (Accept + Content-Type JSON), `isEnabled: false`,
      `version: "1.0.0"`.
- [x] 1.2 Add `lib/Settings/register.d/messagebird-sms-source.json` —
      `@self.slug = "messagebird-sms"`, `location: "https://rest.messagebird.com"`,
      `auth: "apikey"`, `Authorization: "AccessKey "` placeholder header,
      `isEnabled: false`.
- [x] 1.3 Add `lib/Settings/register.d/twilio-sms-source.json` —
      `@self.slug = "twilio-sms"`, `location: "https://api.twilio.com"`,
      `auth: "apikey"`, `configuration.authentication` HTTP Basic (empty
      username/password), `Content-Type: application/x-www-form-urlencoded`,
      `isEnabled: false`.
- [x] 1.4 Add `lib/Settings/register.d/whatsapp-cloud-api-source.json` —
      `@self.slug = "whatsapp-cloud-api"`,
      `location: "https://graph.facebook.com/v19.0"`, `auth: "apikey"`,
      `Authorization: "Bearer "` placeholder header, `isEnabled: false`.
- [x] 1.5 Add `lib/Settings/register.d/whatsapp-bsp-source.json` —
      `@self.slug = "whatsapp-bsp"`, `location: "https://api.whatsapp.cm.com"`,
      `auth: "apikey"`, `X-CM-PRODUCTTOKEN: ""` placeholder header,
      `isEnabled: false`.
- [x] 1.6 Confirm all five fragments are valid JSON and fold into the register
      version (so `InitializeRegister` re-imports them).

## 2. Verify

- [x] 2.1 Live: run the register import (occ maintenance:repair / app
      re-enable) and confirm `source` objects with the five slugs exist via the
      OR object API.
- [x] 2.2 Live: confirm the OR outbound-messaging dispatch leaf stops returning
      `openconnector-source-missing` for these sources (now resolves and
      degrades to `upstream-service-down`, dormant placeholder no key).
