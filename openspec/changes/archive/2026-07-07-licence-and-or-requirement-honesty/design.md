# Design — licence tag and OpenRegister-requirement honesty

## Context

This is a truth-in-metadata change. Nothing about the runtime changes except a
new fail-loud guard; the bulk is making three human-facing artifacts agree with
what the code and the internal specs already assert.

Evidence at HEAD (verified during the 2026-07-07 audit):

| Artifact | Says | Reality |
|---|---|---|
| `appinfo/info.xml` | `<licence>agpl</licence>` | `LICENSE`, `publiccode.yml`, README badge = EUPL-1.2 |
| `README.md` L22/L171/L291 | "standalone", "no additional apps", OR "optional" | 10 controllers inject `OrObjectService` as a required ctor dep |
| `openspec/config.yaml` design rule | "no hard dependency" on OR | `lib/Db/` deleted; `mapping-and-search` spec: "Persist requires OpenRegister" |
| `src/manifest.json` | `"dependencies": ["openregister"]` | ✅ correct — the outlier is the human-facing prose |

## Decisions

### D1 — `<licence>eupl</licence>`, not a licence change
The project licence is and stays EUPL-1.2. Nextcloud's `info.xsd` accepts a
free-form short token in `<licence>`; the App Store and the fleet use `eupl`
(the `agpl` token was a scaffold leftover). This is a one-token correction, not
a relicensing. `LICENSE`/`publiccode.yml`/README already carry the canonical
SPDX `EUPL-1.2`, so no SPDX or dependency-allowlist work is implied.

### D2 — "required", stated once per surface, no restating other specs
The `openconnector-app-manifest` spec already owns the machine-readable
`manifest.dependencies` requirement. This change does not duplicate it; it fixes
the *prose* surfaces (README, config.yaml) and the App Store licence token. The
spec delta asserts consistency ("these human-facing artifacts MUST NOT contradict
the declared dependency"), so a future regression is caught by a doc-lint rather
than only by a runtime 500.

### D3 — Fail loud on missing OpenRegister, feature-detected, no OR import
When `openregister` is not enabled, the honest outcome is a clear admin notice
plus a health signal, not ten controllers throwing DI 500s. The guard is a plain
`IAppManager::isEnabledForUser('openregister')` check — it does NOT reference any
OpenRegister class, so it is safe to run when OR is absent. Two surfaces:

- **Admin notice**: emitted from app boot (`Application::register`/`boot`) via
  the Nextcloud notifications/`INotificationManager` or the settings section, so
  an operator sees *why* the app is broken.
- **Health**: `/api/health` (already `#[PublicPage]`, already returns 503 on a
  failed check per ADR-006) adds `openregister` enablement as a checked
  dependency and reports it as the unhealthy reason.

This intentionally overlaps the *edge* of `repair-and-app-boot` but adds a
distinct, missing behaviour (surfacing the dependency to a human) rather than
changing repair semantics. It is scoped so `openspec archive` will sync it as a
new `app-distribution-metadata` capability, keeping boot/repair untouched.

## Non-goals

- Not changing the project licence, SPDX headers, or the dependency allowlist.
- Not adding a machine-enforced `<dependencies>` element for OpenRegister in
  `info.xml` (Nextcloud has no first-class inter-app hard-dependency element;
  `src/manifest.json` + the runtime guard are the enforcement points).
- Not touching any runtime data path or the frontend manifest (already correct).
