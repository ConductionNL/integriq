# Tasks — seed-xwiki-source

## 1. Seed fragment

- [x] 1.1 Add `lib/Settings/register.d/xwiki-source.json` — an ADR-037 register
      fragment with a `components.objects` array containing one `source` object:
      `@self.slug = "xwiki"`, `name`, `description`, `type: "api"`,
      `location: "http://xwiki:8080/xwiki"`, `auth: "none"`,
      `configuration.headers.Accept: "application/json"`, `isEnabled: false`,
      `version: "1.0.0"`.
- [x] 1.2 Confirm the fragment is valid JSON and its signature folds into the
      register version (so `InitializeRegister` re-imports it).

## 2. Verify

- [x] 2.1 Live: run the register import (occ maintenance:repair /
      app re-enable) and confirm a `source` object with slug `xwiki` exists via
      the OR object API.
- [x] 2.2 Live: confirm OR's `XwikiLinkService::getAvailablePages()` /
      search endpoint stops returning `openconnector-source-missing` (now returns
      empty/upstream-down because the dormant placeholder host is unreachable).
