---
status: proposed
---

# Source Management

## ADDED Requirements

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
