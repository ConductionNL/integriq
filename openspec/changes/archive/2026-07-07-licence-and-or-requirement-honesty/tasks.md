# Tasks — licence tag and OpenRegister-requirement honesty

> Order: correct the metadata first (pure edits), then add the fail-loud guard.
> All edits are to human-facing / config surfaces plus one feature-detected
> boot guard; no runtime data path changes.

- [x] `appinfo/info.xml`: change `<licence>agpl</licence>` to `<licence>eupl</licence>`; confirm the file still validates against `info.xsd` and that `LICENSE`, `publiccode.yml`, and the README badge all read EUPL-1.2 (they already do)
- [x] `README.md`: rewrite the three "standalone / OpenRegister optional" passages (intro paragraph, Requirements section, Related Apps line) to state OpenRegister is a required runtime dependency; change the Architecture mermaid edge from `B <-->|Optional| G[OpenRegister]` to a required edge
- [x] `README.md`: remove the phantom `lib/Db/  # ORM entities and mappers` line from the Directory Structure block (the directory does not exist — entities are OpenRegister objects) and reword the Data Model table intro to note entities are persisted as OpenRegister objects
- [x] `openspec/config.yaml`: replace the design rule `"OpenConnector operates independently of OpenRegister (no hard dependency)"` with a rule stating OpenRegister is a required runtime dependency and app-local reimplementation of OR capabilities is forbidden (aligns with `openconnector-direct-or-usage`)
- [x] Add a feature-detected boot guard: when `IAppManager::isEnabledForUser('openregister')` is false, emit an admin-visible notice ("OpenConnector requires the OpenRegister app — install and enable it"); the guard MUST NOT reference any `OCA\OpenRegister\*` class so it is safe when OR is absent
- [x] Extend the `/api/health` check so a disabled/absent `openregister` app is reported as an unhealthy/degraded reason (503 per ADR-006), naming the missing dependency
- [x] Unit test: the boot guard raises the notice when `openregister` is disabled and is silent when enabled (mock `IAppManager`); the health check reports the dependency reason when OR is absent
- [x] Run `composer check:strict` and fix anything it flags in touched files

Acceptance criteria (plain bullets — verified by /opsx-verify):

- `appinfo/info.xml` `<licence>` reads `eupl`; no artifact in the repo still declares AGPL for the app itself
- `README.md` and `openspec/config.yaml` no longer claim OpenConnector is standalone or that OpenRegister is optional; both state OpenRegister is required
- `README.md` Directory Structure no longer lists a `lib/Db/` directory
- With `openregister` disabled, enabling OpenConnector produces a clear admin notice and `/api/health` returns a 503 naming the missing OpenRegister dependency — not a bare DI 500
- With `openregister` enabled, behaviour is unchanged (guard silent, health green)
