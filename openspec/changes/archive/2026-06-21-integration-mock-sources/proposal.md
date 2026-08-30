---
kind: config
depends_on: []
---

# openconnector — mock-mode fixtures on the external integration sources

## Why

The 8 seeded OpenConnector sources consumed by the OpenRegister external
integration leaves — `kvk`, `opencorporates`, `brp-haalcentraal`, `cmcom-sms`,
`messagebird-sms`, `twilio-sms`, `whatsapp-cloud-api`, `whatsapp-bsp` — ship
**dormant** (`isEnabled:false`, empty credentials) so a fresh install carries no
secret. The trade-off is that **none of the leaves can be demonstrated** without
real credentials we don't have: every call degrades to a 503.

The paired OpenRegister change `integration-mock-mode` adds a foundation-safe,
opt-in **mock mode** to `ExternalIntegrationRouter`: when a source carries
`configuration.mock:true`, the router returns the canned
`configuration.mockResponse` body without a real HTTP call. This change makes the
8 sources **live-in-mock-mode** so the leaves are demonstrably functional
end-to-end out of the box.

## What Changes

For each of the 8 seeded `register.d/*-source.json` fragments:

- Add `configuration.mock: true`.
- Add `configuration.mockResponse` — a realistic body shaped EXACTLY like the
  real upstream response (so the leaf's existing extractor + the consuming app's
  mappers consume it unchanged):
  - **kvk** → `{ resultaten: [ {kvkNummer, naam, adres…} ×3 ] }` (real-looking
    Dutch companies).
  - **opencorporates** → `{ results: { companies: [ { company: {name,
    company_number, jurisdiction_code:'nl'…} } ×2 ] } }`.
  - **brp-haalcentraal** → `{ personen: [ { burgerservicenummer, naam, geboorte,
    verblijfplaats… } ] }` with a FAKE RvIG test BSN `999990019` (NOT a real
    person), plus a `configuration.mockMeta` (`status:200`, `durationMs`, a fake
    `correlationId`) for the Wet-BRP audit envelope.
  - **cmcom-sms / messagebird-sms / twilio-sms** → each vendor's send-success
    shape with a `MOCK-SMS-…` message id (`Accepted` / `sent` / `queued`).
  - **whatsapp-cloud-api / whatsapp-bsp** → Meta Cloud-API success with a mock
    `wamid.MOCK…` id.
- Flip `isEnabled` to `true` (safe: mock means NO upstream call and NO secret is
  read).
- Update each fragment's `$comment` to document mock mode + the exact go-live
  steps (set the real credential, remove `configuration.mock`).

## Impact

- Affected: the 8 `register.d/*-source.json` register fragments only — no PHP.
- The real go-live path is unchanged and one step away: set the real credential
  on the source and remove `configuration.mock` (the production `location` is
  already set on each fragment).
- Security: no secret is added; mock means no upstream call. The fake BSN
  `999990019` is in the RvIG test range and identifies no real person.
- Depends (runtime) on the OpenRegister `integration-mock-mode` router
  short-circuit; the fragments are inert (just extra `configuration` keys) on an
  OR build that predates it.
