# Tasks — seed-kvk-opencorporates-sources

## 1. Seed fragments

- [x] 1.1 Add `lib/Settings/register.d/kvk-source.json` — an ADR-037 register
      fragment with a `components.objects` array containing one `source` object:
      `@self.slug = "kvk"`, `name`, `description`, `type: "api"`,
      `location: "https://api.kvk.nl/api/v2"`, `auth: "apikey"`,
      `configuration.headers.Accept: "application/json"`, `isEnabled: false`,
      `version: "1.0.0"`.
- [x] 1.2 Add `lib/Settings/register.d/opencorporates-source.json` — same shape,
      `@self.slug = "opencorporates"`,
      `location: "https://api.opencorporates.com/v0.4"`, `auth: "apikey"`,
      `isEnabled: false`.
- [x] 1.3 Confirm both fragments are valid JSON and their signatures fold into
      the register version (so `InitializeRegister` re-imports them).

## 2. Verify

- [x] 2.1 Live: run the register import (occ maintenance:repair /
      app re-enable) and confirm `source` objects with slugs `kvk` and
      `opencorporates` exist via the OR object API.
- [x] 2.2 Live: confirm OR's KvK / OpenCorporates lookup endpoints stop
      returning `openconnector-source-missing` (now return degraded
      `upstream-service-down` because the dormant placeholder ships without an
      API key).
