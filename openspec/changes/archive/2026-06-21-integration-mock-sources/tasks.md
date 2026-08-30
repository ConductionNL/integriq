# Tasks — integration-mock-sources

## 1. Mock fixtures on the 8 source fragments

- [x] 1.1 `kvk-source.json` — `configuration.mock:true` + `mockResponse`
      (`{ resultaten: [...] }`, 3 Dutch companies) + `isEnabled:true`.
- [x] 1.2 `opencorporates-source.json` — `mock:true` + `mockResponse`
      (`{ results: { companies: [...] } }`, jurisdiction `nl`) + `isEnabled:true`.
- [x] 1.3 `brp-haalcentraal-source.json` — `mock:true` + `mockResponse`
      (`{ personen: [...] }`, fake BSN `999990019`) + `mockMeta` + `isEnabled:true`.
- [x] 1.4 `cmcom-sms-source.json` — `mock:true` + `mockResponse` (CM.com accepted
      shape, `MOCK-SMS-CMCOM-0001`) + `isEnabled:true`.
- [x] 1.5 `messagebird-sms-source.json` — `mock:true` + `mockResponse`
      (MessageBird shape, `MOCK-SMS-MB-0001`, status `sent`) + `isEnabled:true`.
- [x] 1.6 `twilio-sms-source.json` — `mock:true` + `mockResponse` (Twilio
      Messages.json, `MOCK-SMS-TWILIO-0001`, status `queued`) + `isEnabled:true`.
- [x] 1.7 `whatsapp-cloud-api-source.json` — `mock:true` + `mockResponse` (Meta
      Cloud-API, `wamid.MOCK0001`) + `isEnabled:true`.
- [x] 1.8 `whatsapp-bsp-source.json` — `mock:true` + `mockResponse` (Meta-shaped,
      `wamid.MOCK-BSP-0001`) + `isEnabled:true`.

## 2. Documentation + safety

- [x] 2.1 Each fragment's `$comment` documents mock mode + the go-live steps
      (set the real credential, remove `configuration.mock`).
- [x] 2.2 No secret added; BSN is the fake RvIG test value `999990019`.

## 3. Verify

- [x] 3.1 All 8 fragments are valid JSON with `configuration.mock===true`,
      a `mockResponse`, and `isEnabled===true`.
- [x] 3.2 End-to-end mock behaviour proven by the OpenRegister
      `IntegrationMockModeTest` (each leaf returns its canned fixture with no
      real call).
