# Tasks — seed-brp-haalcentraal-source

## 1. Seed fragment

- [x] 1.1 Add `lib/Settings/register.d/brp-haalcentraal-source.json` — an
      ADR-037 register fragment with a `components.objects` array containing one
      `source` object: `@self.slug = "brp-haalcentraal"`, `name`, `description`,
      `type: "api"`, `location: "https://api.haalcentraal.nl/brp/v2.0"`,
      `auth: "oauth"`, `configuration.headers.Authorization:
      "Bearer {{ oauthToken(source) }}"` + `Accept: application/hal+json`,
      `configuration.authentication` (`grant_type: client_credentials`,
      `authentication: body`, RvIG `tokenUrl`, empty `scope`/`client_id`/
      `client_secret`), empty `configuration.cert` + `configuration.ssl_key`,
      `isEnabled: false`, `version: "1.0.0"`.
- [x] 1.2 Confirm the fragment is valid JSON and its signature folds into the
      register version (so `InitializeRegister` re-imports it).
- [x] 1.3 Confirm no secret or certificate is committed (all credential / cert
      fields empty).

## 2. Verify

- [x] 2.1 Live: run the register import (occ maintenance:repair /
      app re-enable) and confirm a `source` object with slug
      `brp-haalcentraal` exists via the OR object API.
- [x] 2.2 Live: confirm OR's BRP person-lookup endpoint stops returning
      `openconnector-source-missing` (now returns degraded
      `upstream-service-down` because the dormant placeholder ships without
      OAuth credentials / mTLS cert).
