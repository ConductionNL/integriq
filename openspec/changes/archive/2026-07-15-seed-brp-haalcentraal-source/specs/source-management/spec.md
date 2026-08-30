---
status: proposed
---

# Source Management

## ADDED Requirements

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
