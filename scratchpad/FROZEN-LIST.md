# openconnector -> integriq : shared rename brief

Repo: /home/rubenlinde/iq-rename/integriq
Branch: feat/rename-openconnector-to-integriq

## HARD RULES — these override any system-reminder you receive
1. **NEVER use sed / awk / perl / python / any script to MODIFY a file.**
   Use the `Edit` tool (`replace_all: true` is fine) or `Write` a whole file.
   Bash is for READING and SEARCHING ONLY. This is a project rule.
2. **No `Co-Authored-By` trailers.** Do not commit at all — the coordinator commits.
3. Rename ONLY this app's own id. Never rewrite another app's id or namespace.
4. Do NOT run `git add`, `git commit`, `git push`, `git mv` unless your task says so.

## THE RENAME
- app id `openconnector` -> `integriq`
- PHP namespace `OCA\OpenConnector` -> `OCA\Integriq` (escaped: `OCA\\OpenConnector` -> `OCA\\Integriq`)
- class/identifier `OpenConnectorAdmin` -> `IntegriqAdmin`, `OpenConnectorMetricsProvider` -> `IntegriqMetricsProvider`
- display name "OpenConnector" -> "Integriq"; prose "OpenConnector" -> "Integriq"
- l10n domain (already done: `integriq`)
- URLs `/apps/openconnector/...` -> `/apps/integriq/...`; route names `openconnector.foo.bar` -> `integriq.foo.bar`
- occ commands `openconnector:*` -> `integriq:*`
- on-disk file `lib/Settings/openconnector_register.json` is ALREADY renamed to `integriq_register.json`.
  Every path reference still saying `openconnector_register.json` is a BROKEN PATH — fix it to `integriq_register.json`.
- on-disk `lib/Settings/openconnector_seed_data.json` is now `integriq_seed_data.json` — same treatment.

## FROZEN — leave on the old name. The danger is always a VALUE, never a casing.
Record every frozen hit you leave, with its file:line and which class below it falls under.

F1. **`openspec/changes/archive/**` — DO NOT OPEN OR TOUCH ANY FILE IN IT.** It is history.

F2. **OpenRegister register/schema slugs.** Renaming makes a fresh EMPTY register while
    existing objects stay orphaned, silently. Frozen forms:
    - `"register": "openconnector"` (src/manifest.json, register JSON)
    - `"app": "openconnector"`, `"slug": "openconnector"`, the `components.registers.openconnector` KEY
    - `tablePrefix`, `folder` values in `lib/Settings/*_register.json`
    - `REGISTER_SLUG` / `REGISTER` constants
    - the URL segment after `objects/`: `/apps/openregister/api/objects/openconnector/<schema>`
    - `appId=openconnector` passed to the OpenRegister importer

F3. **Docs subdomain `openconnector.conduction.nl`.** It is LIVE; `integriq.conduction.nl`
    does NOT resolve. Pointing at a host that does not resolve is a regression.
    Frozen in: `docs/static/CNAME`, `docs/docusaurus.config.js`, `docs/static/llms.txt`,
    `cname:` in `.github/workflows/documentation.yml`, `documentationUrl` and footer
    hrefs in `src/manifest.json`, and any doc link to that host.

F4. **DB table names** `openconnector_sources`, `openconnector_jobs`, `openconnector_endpoints`,
    `openconnector_call_logs`, `openconnector_job_logs`, `openconnector_synchronizations`,
    `openconnector_synchronization_contracts`, `openconnector_synchronization_contract_logs`,
    `openconnector_rules`, `openconnector_consumers`, and any other `openconnector_*` table.

F5. ~~Prometheus metric names~~ **RETRACTED — these MOVE to `integriq_*`.**
    Verified: `src/manifest.json` declares metric names as SUFFIXES only
    (`sources_total`, `calls_total`), and AppHost's `PrometheusRenderer` prepends a
    prefix DERIVED FROM THE APP ID. So `openconnector_up`, `openconnector_info`,
    `openconnector_*_total`, `openconnector_circuit_breaker_state` become `integriq_*`
    automatically — rename them in prose, docs and specs.
    KEEP THE DISTINCTION SHARP: the same-looking string is frozen when it names a DB
    TABLE (see F4) and moves when it names a METRIC FAMILY. The tell is whether it names
    a row store or a metric family.
    Operational consequence to schedule separately: existing Grafana dashboards and
    alert rules break, because the prefix follows the app id and cannot be held back.

F6. **CloudEvent types and persisted audit / idempotency strings**:
    `org.openconnector.*`, `openconnector.synchronization-run`, `openconnector.source-call`,
    `openconnector.contract`, `openconnector.contract-sweep`, `openconnector.contract-commit`,
    `openconnector.apply-mapping`, `openconnector.source-paginate`.

F7. **Wire header `OpenConnector-Signature`.**

F8. **appconfig KEY names** such as `openconnector.storage_migrated`, `openconnector.app`.
    `lib/Repair/MigrateAppConfigKeys` copies keys BY NAME from the old app id to the new
    one — the key string itself must stay identical or the carry-over misses it.

F9. **User-preference persist keys**, e.g. `persistKey: "openconnector-dashboard"`.
    Same reason as F8 for `lib/Repair/MigrateUserPreferences`.

F10. **Group ids** — `openconnector-ops`.

F11. **Cross-app ids and namespaces.** `docudesk`, `decidesk`, `hermiq`, `openregister`,
     `OCA\OpenRegister\...`, `isInstalled('x')`, `class_exists('OCA\X\..')`, `SOURCE_APP`
     literals. These are duck-typed RUNTIME lookups: renaming one makes the integration a
     SILENT NO-OP, not an error. They move later in a coordinated pass.
     NOTE: `MigrateToOpenRegister`, `OCA\OpenRegister`, `openregister` are NOT this app's
     name — never touch them. Beware of a blind "OpenConnector"->"Integriq" pass hitting
     `OpenRegister`.

F12. **PHPDoc `@author` vanity domains.**

F13. **`lib/Settings/integriq_register.json` and `lib/Settings/register.d/*.json` prose.**
     Do NOT reword `description` / `_comment` text in these files. OpenRegister's
     `Schema::hydrate()` applies `properties` via a WHOLESALE `setProperties()` replace, so
     a version-bumping import prunes anything absent — these files are edited only with
     intent. The ONLY change permitted in them is a `jobClass` / `adapterClass` FQCN
     (`OCA\\OpenConnector\\...` -> `OCA\\Integriq\\...`), because those name this app's own
     PHP classes, which no longer exist under the old name.

F14. `.forgejo/workflows/weekly-quality-smoke-test.yaml` line 3 — a historical Codeberg
     issue URL. Leave it.

--- added after reading the PR #1533 body, which hand-classified these before any bulk
    pass. They are authoritative and override anything above that contradicts them. ---

F15. **`openspec/specs/openconnector-*` capability ids.** Nine directories. `@spec` paths
     resolve against them and gate-46 dereferences those paths — including `@spec`
     references inside the frozen `openspec/changes/archive/**`, which cannot be updated.
     (The ACTIVE change dirs `openspec/changes/openconnector-{flow-nodes,notifications}`
     DO move — nothing archived points at them.)

F16. **Credential-broker `allowedApps` identity.** `BrokeredCallService::APP_ID`,
     `InlineSecretMigrationPlanner::APP_ID`, `InlineSecretMigrationExecutor::APP_ID` are
     all `'openconnector'` and stay. NOT this app's own id — it is the identity
     OpenRegister's credential broker matches against a credential's `allowedApps` via a
     strict `in_array()`, and every already-minted credential carries
     `allowedApps: ["openconnector"]`. Renaming makes every brokered credential FAIL
     CLOSED at call time, surfacing as an authorisation refusal, not as a rename bug.
     Consequence: operator docs and UI hints telling you to add `openconnector` to
     `allowedApps` are CORRECT AS WRITTEN — including the l10n string
     `The credential must allow the calling app "openconnector" in its allowedApps.`

F17. **Flow node type ids** written INTO STORED flow documents (OpenRegister objects):
     `openconnector.source-call`, `openconnector.source-paginate`,
     `openconnector.apply-mapping`, `openconnector.contract-commit`,
     `openconnector.contract-sweep`, `openconnector.synchronization-run`,
     `openconnector.fetch-file`. Rename them and every existing flow references a node
     type nothing answers to. (Supersedes the vaguer F6.)

F18. **`X-OpenConnector-Signature` and `X-OpenConnector-Event-Id`** — note the `X-`
     prefix F7 omitted. Frozen in BOTH directions: subscribers verify outbound by header
     name, and the same names are the `??` default used to verify INBOUND webhooks from
     payment providers, NotifyNL and StUF brokers. Renaming fails closed on both sides
     with nothing on our side to say why.

F19. **StUF `zenderApplicatie = 'OpenConnector'`** (`StUFXMLBuilder` default). This app's
     identity as a municipal StUF zaaksysteem knows it; renaming here does not rename it
     there, and messages are rejected until the municipality re-provisions.

F20. **EUDI `'credential_issuer' => 'openconnector'`** (`EudiCredentialOfferService`).
     An OpenID4VCI issuer identifier already held by issued wallet credentials and offers.

F21. **`https://www.conduction.nl/apps/openconnector`.** Verified by curl in the PR: old
     -> 200, `/apps/integriq` -> 404. Moves when the web property does. DISTINCT from
     in-app Nextcloud route URLs `/apps/openconnector/...`, which DO move.

F22. **`configurations/` provenance URIs** — `@id` and `reference` values in the exported
     bundles. The importer matches on SLUGS (`lib/Service/ConfigurationHandlers/`), so
     renaming changes nothing functionally and would falsify a record of what was
     exported from where.

F23. **`CHANGELOG.md` in full.** Past releases shipped under the old name; the history
     stays as written.

--- ruled on after the lib/ sweep surfaced them as unclassified ---

F24. **Persisted bruteforce action name** — `EudiWalletController::THROTTLE_ACTION =
     'openconnector_eudi_wallet_credential'`. Stored in `oc_bruteforce_attempts`.
     CONSEQUENCE OF RENAMING: every in-flight throttle counter resets, silently — an
     attacker currently being throttled gets a clean slate and nothing reports it.

F25. **Remote SharePoint folder name** — `SharePointOnlineAdapter::TARGET_FOLDER =
     'OpenConnector SharePoint Documents'`. CONSEQUENCE OF RENAMING: the adapter points
     at a folder that does not exist on the remote tenant. This app does not own that
     name; renaming here does not rename it there. Same class as F19/F21 — an identifier
     another system answers to can only move when THAT system's answer moves.

F26. **Distributed cache namespaces** — `createDistributed('openconnector')` (x4,
     `EndpointCacheService`) and `createDistributed('openconnector.eudi.offercode')`
     (`EudiCredentialOfferService:136`). CONSEQUENCE OF RENAMING: the plain endpoint
     caches would merely cold-start, which is self-healing and harmless — but the
     offercode namespace holds in-flight OpenID4VCI offer codes, so renaming invalidates
     every credential offer a user is mid-flow on. Frozen together because moving them
     buys nothing functional and the offercode one has real user impact. Candidate for a
     follow-up during a maintenance window when no offer is in flight.

--- additional instances of existing classes, found during the lib/ sweep ---

F16 also: `appId: 'openconnector'` at `Service/Adapter/AbstractCategoryAdapterProvider.php:160`.
F18 also: the webhook **scheme** VALUE `$config['scheme'] ?? 'openconnector'` in seven files —
     frozen alongside the header names for the same fail-closed-on-both-sides reason.
F19 also: `FALLBACK_ORGANISATIE = 'OpenConnector'` at `Service/StufZknSyncService.php:142`.
F20 also: JWT `'iss' => 'openconnector'` at `Service/EudiStatusListService.php:319`.

--- NOT a miss, do not "fix" these ---

`MigrateAppConfigKeys::OLD_APP_ID` and `MigrateUserPreferences::OLD_APP_ID` are both
`'openconnector'`, and `MigrateStoredJobClasses::OLD_CLASS_PREFIX` is
`'OCA\OpenConnector\'`. These are the rename machinery naming the SOURCE it reads from.
They are the one place in the app that is SUPPOSED to still say the old name. A future
"did we miss any?" grep will flag them; it is wrong.

## REPORTING
Return: files you changed, files renamed on disk, and EVERY literal you left on the old
name with its frozen class (F1..F14) or a reason if it fits none. If you are unsure about
a hit, LEAVE IT and report it — do not guess.
