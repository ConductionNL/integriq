# Source Management

## ADDED Requirements

### Requirement: External integration sources SHALL support mock-mode fixtures

A seeded source consumed by an OpenRegister external integration leaf SHALL support an opt-in mock mode so the leaf is demonstrably functional end-to-end without real upstream credentials.

A mock-mode source fragment SHALL set `configuration.mock: true` and
`configuration.mockResponse` to a body shaped exactly like the real upstream
response, and MAY set `configuration.isEnabled: true` (safe — mock means no
upstream call and no secret is read). The OpenRegister `ExternalIntegrationRouter`
short-circuits a `configuration.mock===true` source and returns the
`mockResponse` body without a real HTTP call. The production `location` SHALL
remain the real upstream endpoint, and the fragment's `$comment` SHALL document
the go-live steps (set the real credential, remove `configuration.mock`).

The 8 external integration sources — `kvk`, `opencorporates`, `brp-haalcentraal`,
`cmcom-sms`, `messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`,
`whatsapp-bsp` — SHALL ship mock-mode fixtures so a fresh install demonstrates
company lookup, person lookup, and SMS/WhatsApp dispatch out of the box.

#### Scenario: KvK source returns canned companies in mock mode

- **WHEN** the `kvk` source is flagged `configuration.mock:true` with a
  `{ resultaten: [...] }` `mockResponse`
- **THEN** the OpenRegister KvK leaf returns the canned Dutch companies without a
  real KvK API call

#### Scenario: BRP source returns a fake test person plus audit meta

- **WHEN** the `brp-haalcentraal` source is flagged mock with a `{ personen: [...] }`
  `mockResponse` (fake BSN `999990019`) and a `mockMeta`
- **THEN** the BRP leaf returns the canned person plus a synthesized Wet-BRP
  audit `meta` (`status`, `durationMs`, `correlationId`) without a real
  HaalCentraal call, and no real person's BSN is used

#### Scenario: SMS/WhatsApp sources return a canned send-success in mock mode

- **WHEN** a `cmcom-sms` / `messagebird-sms` / `twilio-sms` /
  `whatsapp-cloud-api` / `whatsapp-bsp` source is flagged mock with a
  vendor-shaped success `mockResponse`
- **THEN** the message-dispatch leaf returns `{ status: 'sent', source, response }`
  carrying the mock message id (`MOCK-SMS-…` / `wamid.MOCK…`) without sending a
  real message

#### Scenario: removing the mock flag restores the real upstream call

- **WHEN** an operator sets the real credential on a mock-flagged source and
  removes `configuration.mock`
- **THEN** the leaf performs the real upstream call against the production
  `location` with no other change required
