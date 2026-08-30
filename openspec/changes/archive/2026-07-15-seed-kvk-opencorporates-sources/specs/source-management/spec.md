---
status: proposed
---

# Source Management

## ADDED Requirements

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
