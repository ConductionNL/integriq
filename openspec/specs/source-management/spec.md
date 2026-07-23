---
status: implemented
retrofit: true
---

# Source Management

## Purpose

OpenConnector provides a Sources section in its SPA where administrators can
browse, create, edit, and test external source connections (APIs, databases,
registers). A Source is the foundational entity that describes how to connect to
an external system — its URL, authentication type, headers, and rate-limit
configuration. This spec covers the **observable browser UI behaviour** of the
Sources section plus the backend call/rate-limit internals (covered by PHPUnit
and Newman). It is a retrofit spec: the code already exists.
## Requirements

### REQ-SRC-UI-001: Source Management UI

OpenConnector MUST provide a Sources section in its SPA where administrators can
browse, create, edit, delete, and test source connections.

#### Scenario: sources list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Sources section via the sidebar nav or direct URL `/apps/openconnector/sources`
- THEN the Sources index page renders inside the main content area with content visible

#### Scenario: add source button opens the creation modal

- GIVEN the Sources index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the source creation form fields

#### Scenario: source logs sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Source logs page at `/apps/openconnector/sources/logs`
- THEN the page mounts and renders the main content area

### REQ-SRC-001: External HTTP source call

The system SHALL dispatch HTTP calls to external sources via `CallService`,
applying source-configured authentication (bearer, basic, API key, JWT),
headers, rate-limit tracking, and response normalisation. All call records are
persisted as `call_log` objects in OpenRegister.

@e2e exclude backend CallService HTTP dispatch — covered by PHPUnit/Newman, not browser UI

#### Scenario: bearer auth header is applied

- **GIVEN** a source with bearer auth configured
- **WHEN** a call is dispatched
- **THEN** the `Authorization: Bearer <token>` header is included.

#### Scenario: rate-limit zero blocks the call

- **GIVEN** a source whose rate-limit remaining reaches zero
- **WHEN** a call is attempted
- **THEN** a 429 exception is raised and the call is not dispatched.

#### Scenario: dispatched call is logged

- **GIVEN** a dispatched call
- **WHEN** the response is received
- **THEN** a `call_log` record is persisted with the source id, status code, and duration.

### REQ-SRC-002: Source connection test

The system SHALL provide a `POST /api/sources/{id}/test` endpoint that
dispatches a single test call to the source and returns the response body and
status code without persisting a log record.

@e2e exclude backend source-test endpoint — covered by PHPUnit/Newman, not browser UI

#### Scenario: reachable source returns status and body

- **GIVEN** a source with a reachable URL
- **WHEN** the test endpoint is called
- **THEN** the response contains the upstream status code and body.

#### Scenario: unreachable source returns a descriptive error

- **GIVEN** a source with an unreachable URL
- **WHEN** the test endpoint is called
- **THEN** a descriptive error response is returned rather than a 500 crash.

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

### Requirement: Pre-built BRP HaalCentraal source seed

OpenConnector SHALL seed a pre-built `source` object with
`@self.slug = "brp-haalcentraal"` (register `openconnector`, schema `source`)
on app install/upgrade, so the OpenRegister BRP person-lookup integration leaf
— which routes through `OCA\OpenConnector\Db\SourceMapper::find('brp-haalcentraal')`
— resolves a base URL out of the box. The seed SHALL ship dormant
(`isEnabled: false`) with the production HaalCentraal Personen v2.0 base URL
(`https://api.haalcentraal.nl/brp/v2.0`) as `location` and `auth: "oauth"`, and
SHALL carry the OAuth2 client_credentials + mutual-TLS configuration shape that
`CallService` consumes — an `Authorization: Bearer {{ oauthToken(source) }}`
header, a `configuration.authentication` block
(`grant_type: client_credentials`, `authentication: body`, the RvIG `tokenUrl`)
with **empty** `scope` / `client_id` / `client_secret`, and **empty**
`configuration.cert` / `configuration.ssl_key` PEM placeholders — so a fresh
install never carries a secret or certificate and the OR provider degrades
gracefully until an operator configures the OAuth credentials + PKIoverheid
client certificate and enables the source.

The seed SHALL be delivered as an ADR-037 register fragment
(`lib/Settings/register.d/brp-haalcentraal-source.json`, a `components.objects`
array) so it is merged into the register descriptor by `InitializeRegister` and
materialised idempotently by `@self.slug` via OpenRegister's `ImportHandler`.

#### Scenario: brp-haalcentraal source materialises on install

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs `InitializeRegister`
- THEN a `source` object with `@self.slug = "brp-haalcentraal"` (location
  `https://api.haalcentraal.nl/brp/v2.0`) exists in register `openconnector`,
  schema `source`, with `auth = "oauth"` and `isEnabled = false`
- @e2e exclude Backend seed materialisation — verified by Newman/PHPUnit against the OR object API, not a browser flow.

#### Scenario: seed ships OAuth2 + mTLS config without secrets

- GIVEN the seeded `brp-haalcentraal` source
- WHEN an operator inspects its `configuration`
- THEN the `Authorization` header is `Bearer {{ oauthToken(source) }}`, the
  `configuration.authentication.grant_type` is `client_credentials` with an
  empty `client_id` / `client_secret` / `scope`, and `configuration.cert` /
  `configuration.ssl_key` are empty placeholders
- @e2e exclude Backend config-shape assertion — verified by PHPUnit / JSON fixture, not a browser flow.

#### Scenario: seed re-import is idempotent

- GIVEN the `brp-haalcentraal` source already exists from a prior install
- WHEN `InitializeRegister` runs again
- THEN no duplicate `brp-haalcentraal` source is created (matched by `@self.slug`)
- @e2e exclude Backend idempotency — verified by Newman/PHPUnit, not a browser flow.

#### Scenario: OR BRP lookup no longer reports source-missing

- GIVEN the seeded dormant `brp-haalcentraal` source exists
- WHEN OpenRegister's `BrpPersoonProvider` resolves the source for a person lookup
- THEN the resolution succeeds (the source is found) and the lookup degrades to
  `{ unavailable: true, cause: 'upstream-service-down' }` rather than a
  `openconnector-source-missing` failure
- @e2e exclude Cross-app backend behaviour — verified against the OR lookup endpoint, not a browser flow.

### Requirement: Pre-built KvK and OpenCorporates source seeds

OpenConnector SHALL seed a pre-built `source` object with `@self.slug = "kvk"`
and a pre-built `source` object with `@self.slug = "opencorporates"` (register
`openconnector`, schema `source`) on app install/upgrade, so the OpenRegister
company-lookup integration leaves — which route through
`OCA\OpenConnector\Db\SourceMapper::find('kvk')` /
`::find('opencorporates')` — resolve a base URL out of the box. Each seed SHALL
ship dormant (`isEnabled: false`) with the production REST base URL as
`location` and `auth: "apikey"` **without** a key, so a fresh install never
carries a secret and the OR providers degrade gracefully until an operator
configures the API key and enables the source.

Both seeds SHALL be delivered as ADR-037 register fragments
(`lib/Settings/register.d/kvk-source.json` and
`lib/Settings/register.d/opencorporates-source.json`, each a
`components.objects` array) so they are merged into the register descriptor by
`InitializeRegister` and materialised idempotently by `@self.slug` via
OpenRegister's `ImportHandler`.

#### Scenario: kvk and opencorporates sources materialise on install

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs `InitializeRegister`
- THEN a `source` object with `@self.slug = "kvk"` (location
  `https://api.kvk.nl/api/v2`) and a `source` object with
  `@self.slug = "opencorporates"` (location
  `https://api.opencorporates.com/v0.4`) exist in register `openconnector`,
  schema `source`, each with `auth = "apikey"` and `isEnabled = false`
- @e2e exclude Backend seed materialisation — verified by Newman/PHPUnit against the OR object API, not a browser flow.

#### Scenario: seed re-import is idempotent

- GIVEN the `kvk` and `opencorporates` sources already exist from a prior install
- WHEN `InitializeRegister` runs again
- THEN no duplicate `kvk` or `opencorporates` source is created (matched by `@self.slug`)
- @e2e exclude Backend idempotency — verified by Newman/PHPUnit, not a browser flow.

#### Scenario: OR company lookup no longer reports source-missing

- GIVEN the seeded dormant `kvk` and `opencorporates` sources exist
- WHEN OpenRegister's `KvkProvider` / `OpenCorporatesProvider` resolves the
  source for a company lookup
- THEN the resolution succeeds (the source is found) and the lookup degrades to
  `{ unavailable: true, cause: 'upstream-service-down' }` rather than a
  `openconnector-source-missing` failure
- @e2e exclude Cross-app backend behaviour — verified against the OR lookup endpoint, not a browser flow.

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

### Requirement: Pre-built xWiki source seed

OpenConnector SHALL seed a pre-built `source` object with `@self.slug = "xwiki"`
(register `openconnector`, schema `source`) on app install/upgrade, so the
OpenRegister xWiki integration leaf — which routes through
`OCA\OpenConnector\Db\SourceMapper::find('xwiki')` — resolves a base URL out of
the box. The seed SHALL ship dormant (`isEnabled: false`) with a placeholder
`location` and `auth: none`, so a fresh install never points at an unintended
host and the OR provider degrades gracefully until an operator configures it.

The seed SHALL be delivered as an ADR-037 register fragment
(`lib/Settings/register.d/xwiki-source.json`, a `components.objects` array) so it
is merged into the register descriptor by `InitializeRegister` and materialised
idempotently by `@self.slug` via OpenRegister's `ImportHandler`.

#### Scenario: xwiki source materialises on install

- GIVEN OpenRegister is installed and enabled
- WHEN `occ app:enable openconnector` (or an upgrade) runs `InitializeRegister`
- THEN a `source` object with `@self.slug = "xwiki"` exists in register
  `openconnector`, schema `source`, with `auth = "none"`, `isEnabled = false`,
  and a non-empty `location`
- @e2e exclude Backend seed materialisation — verified by Newman/PHPUnit against the OR object API, not a browser flow.

#### Scenario: seed re-import is idempotent

- GIVEN the `xwiki` source already exists from a prior install
- WHEN `InitializeRegister` runs again
- THEN no duplicate `xwiki` source is created (matched by `@self.slug`)
- @e2e exclude Backend idempotency — verified by Newman/PHPUnit, not a browser flow.

#### Scenario: OR xWiki search no longer reports source-missing

- GIVEN the seeded dormant `xwiki` source exists
- WHEN OpenRegister's `XwikiLinkService` resolves the source for a page search
- THEN the resolution succeeds (the source is found) and the search degrades to
  an empty result with a log line rather than a `openconnector-source-missing`
  failure
- @e2e exclude Cross-app backend behaviour — verified against the OR search endpoint, not a browser flow.

