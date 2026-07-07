---
kind: code
depends_on: []
---

# openconnector — correct the licence tag and the "standalone / OpenRegister-optional" claims

## Why

Three public-facing distribution artifacts misrepresent OpenConnector today, in
two ways that are both trivially verifiable against HEAD:

**1. The App Store licence tag is wrong.** `appinfo/info.xml` declares
`<licence>agpl</licence>`. Every other licence artifact in the repo says
EUPL-1.2: `LICENSE` (European Union Public Licence v. 1.2), `publiccode.yml`
(`license: EUPL-1.2`), and the README badge (`license-EUPL--1.2`). The
`info.xml` `<licence>` element is the value the Nextcloud App Store surfaces to
integrators and the value government procurement scans read first, so the one
artifact that is wrong is the most consequential one. This is a single-token
mismatch, not a licensing decision — the project licence is EUPL-1.2 (fleet
standard, `project_conduction-apps-eupl-license`).

**2. The "fully standalone, OpenRegister optional" claim is false.**
`README.md` states three times that OpenConnector needs no other app:

- line 22: *"OpenConnector is a fully standalone app. It does not require
  OpenRegister or any other Conduction app to function"*
- line 171: *"No additional Nextcloud apps are required. OpenConnector works as
  a standalone application."*
- line 291: *"OpenRegister … (optional; used as sync target when installed)"*

and `openspec/config.yaml` repeats it as a design rule:
*"OpenConnector operates independently of OpenRegister (no hard dependency)."*

The code says the opposite. `lib/Db/` no longer exists — every entity
(source, endpoint, mapping, synchronization, consumer, job, event, call_log)
is stored as an OpenRegister object. Ten controllers
(`SourcesController`, `EndpointsController`, `SynchronizationsController`,
`JobsController`, `MappingsController`, `EventsController`, `LogsController`,
`MetricsController`, `HealthController`, `SynchronizationContractsController`)
inject `OCA\OpenRegister\Service\ObjectService` as a **required, non-nullable
constructor dependency**. When OpenRegister is not installed, Nextcloud's DI
container cannot resolve that service, the controllers cannot be instantiated,
and every route 500s. The app's own `src/manifest.json` already declares
`"dependencies": ["openregister"]`, and the `openconnector-app-manifest` spec
already requires it (`REQ … Manifest MUST declare openregister as a runtime
dependency`). `mapping-and-search` spec: *"Persist requires OpenRegister."*

So the internal contract (manifest, spec, DI) correctly treats OpenRegister as
required, while the outward-facing README + config.yaml + the App Store licence
tag tell integrators the opposite. A prior audit (2026-06-11) already flagged
this "standalone" claim as false and recorded it as "README fixed" — it has
regressed / was never merged, and is false at HEAD.

Beyond the wording, the failure mode is bad: a user who installs OpenConnector
without OpenRegister gets raw HTTP 500s from unresolvable DI, and the
`InitializeRegister` repair step merely logs a warning and skips. There is no
clear "OpenRegister is required" signal to the admin.

## What Changes

- **Fix the licence tag**: `appinfo/info.xml` `<licence>agpl</licence>` →
  `<licence>eupl</licence>` (the App Store's accepted token for EUPL). No other
  behaviour changes.
- **Tell the truth about the OpenRegister dependency**: rewrite the three
  README passages and the `openspec/config.yaml` design rule to state that
  OpenRegister is a **required runtime dependency** (matching
  `src/manifest.json`, the `openconnector-app-manifest` spec, and the
  controller DI). Remove the phantom `lib/Db/` entry from the README directory
  structure (the directory does not exist) and the stale "ORM entities and
  mappers" description; the Architecture diagram's `<-->|Optional|` edge to
  OpenRegister becomes a required edge.
- **Fail loudly, not silently, when OpenRegister is absent**: OpenConnector
  MUST surface a clear, actionable admin notice ("OpenConnector requires the
  OpenRegister app — install and enable it") when OpenRegister is not
  installed/enabled, and the `/api/health` endpoint MUST report the missing
  dependency as an unhealthy/degraded reason rather than the app returning
  bare 500s. This closes the install-without-OR trap surfaced by the honesty
  fix.

## Impact

- Affected specs: NEW `app-distribution-metadata` capability (licence-tag
  correctness, README/config truthfulness about the OpenRegister requirement,
  and the missing-dependency admin signal). Adjacent to `repair-and-app-boot`
  (boot/install) and `openconnector-app-manifest` (which already mandates the
  dependency) — this change makes the *human-facing* metadata consistent with
  them; it does not restate their requirements.
- Affected code (implementation phase, not this change): `appinfo/info.xml`
  (`<licence>` token), `README.md` (three passages + directory structure +
  diagram edge), `openspec/config.yaml` (design rule), and a boot/health guard
  that emits the admin notice + health reason when `openregister` is not
  enabled (no new dependency on OR internals — a plain
  `IAppManager::isEnabledForUser('openregister')` check).
- Not affected: any runtime data path, the CallLog shape, the migration
  command, or the frontend manifest (already correct).
