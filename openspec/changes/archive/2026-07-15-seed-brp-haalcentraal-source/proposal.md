---
kind: config
status: proposed
---

## Why

OpenRegister ships a BRP person-lookup integration leaf — `BrpPersoonProvider`
— that routes every upstream call **through an OpenConnector source**
(`BrpPersoonProvider::SOURCE_ID = 'brp-haalcentraal'`,
`configuredVia: openconnector`). The `ExternalIntegrationRouter` resolves that
source via the OpenRegister-backed `SourceMapper` adapter
(`OCA\OpenConnector\Db\SourceMapper::find('brp-haalcentraal')`, register
`openconnector`, schema `source`).

**That source does not exist.** With zero matching `source` object seeded,
every OR BRP call returns `503 openconnector-source-missing`. The leaf is
dead-on-arrival not because of a code gap but because the connection row was
never seeded.

This blocks centralising pipelinq's bespoke `HaalCentraalClient` onto the
canonical OR/OpenConnector path (ADR-022): OR can't resolve a base URL it has
no source for. Crucially, the RvIG HaalCentraal Personen API needs two
transport capabilities that OpenConnector's `CallService` already provides:

- **OAuth2 client_credentials** — `AuthenticationService::fetchOAuthTokens`
  (`grant_type=client_credentials`) acquires + the bearer is injected per-call
  via the `{{ oauthToken(source) }}` Twig placeholder.
- **mutual TLS (PKIoverheid client certificate)** —
  `CallService::getCertificate` writes `configuration.cert` /
  `configuration.ssl_key` to temp files for the Guzzle TLS handshake, then
  cleans them up after the call.

So no PHP change is needed in OpenConnector — only the dormant source row.

## What Changes

- **Seed a pre-built `brp-haalcentraal` source** as an OpenRegister object
  (register `openconnector`, schema `source`), carrying:
  - `location` — the production HaalCentraal v2 base URL
    (`https://api.haalcentraal.nl/brp/v2.0`),
  - `auth: "oauth"`,
  - `configuration.headers.Authorization: "Bearer {{ oauthToken(source) }}"`
    plus `Accept: application/hal+json`,
  - `configuration.authentication` — `grant_type: client_credentials`,
    `authentication: body`, the RvIG `tokenUrl`, and **empty** `scope` /
    `client_id` / `client_secret`,
  - `configuration.cert` / `configuration.ssl_key` — **empty** PEM placeholders
    for the operator's PKIoverheid client certificate + private key,
  - `isEnabled: false` so a fresh install ships the connection **dormant** —
    the OR provider degrades gracefully (`{unavailable, cause}`) until an
    operator pastes the OAuth credentials + mTLS cert and enables it.
- Ship it as an **ADR-037 register fragment** at
  `lib/Settings/register.d/brp-haalcentraal-source.json` (a
  `components.objects` array), so `InitializeRegister` folds it into
  `openconnector_register.json` on `occ app:enable`/upgrade and OpenRegister's
  `ImportHandler` materialises it idempotently by `@self.slug`. No edit to the
  base descriptor, no concurrent-build conflicts.

This is **kind: config** — a declarative seed fragment. No PHP changes in
OpenConnector: the `SourceMapper` adapter, `CallService` (OAuth2 + mTLS), and
`AuthenticationService` already do all the work; they were just missing the row.

## Capabilities

### Modified Capabilities
- `source-management`: gains a requirement that OpenConnector seeds a pre-built,
  dormant `brp-haalcentraal` source on install so the OpenRegister BRP
  person-lookup integration leaf resolves a base URL out of the box, with the
  OAuth2 + mTLS configuration shape `CallService` consumes.

## Impact

- **Config:** `lib/Settings/register.d/brp-haalcentraal-source.json` (new
  fragment).
- **Behaviour:** after `occ app:enable openconnector` (or upgrade), a `source`
  object with slug `brp-haalcentraal` exists; OR's BRP person-lookup endpoint
  resolves it and returns a degraded `{unavailable, cause:
  'upstream-service-down'}` (dormant placeholder, no credentials/cert) rather
  than `openconnector-source-missing`, until an operator sets the OAuth
  client_id/secret + PKIoverheid cert and enables it.
- **Consumers:** OpenRegister `BrpPersoonProvider` (coded in the paired OR
  change); pipelinq (future) re-points `HaalCentraalClient` at OR's lookup
  endpoint.
- **Secrets:** none — placeholder URLs + empty credential/cert fields.
- **Privacy:** BSN travels in the POST body only (never the path → no SSRF /
  no BSN in access logs); the consuming app masks BSNs before logging; the
  source never logs the request body (`logBody` defaults off).
