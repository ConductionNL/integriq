# Rename brief: openconnector -> integriq (phase 3, app id)

Repo: /home/rubenlinde/iq-rename/integriq  (branch feat/rename-openconnector-to-integriq)

## HARD RULES
- **NEVER use sed/awk/perl/python/scripts to modify files.** Use the Edit tool
  (`replace_all: true` is fine) or write the complete file with Write.
  Bash is for READING/SEARCHING only.
- Never touch /home/rubenlinde/nextcloud-docker-dev/workspace/...
- Do not "fix" unrelated pre-existing issues, dead @spec tags, or reformat.

## SUBSTITUTIONS (apply only where NOT frozen, see below)
| old | new |
| --- | --- |
| `openconnector` | `integriq` |
| `OpenConnector` | `Integriq` |
| `OPENCONNECTOR` | `INTEGRIQ` |
| `Openconnector` | `Integriq` |
| `Open Connector` | `Integriq` |

`openconnector` is NOT a substring of any real word or of any other app's id,
so there is no "procest inside procestermijn" hazard here. The only hazards
are the frozen VALUES listed below.

## FROZEN — never rename these (leave the literal on `openconnector`)

1. **OpenRegister register slug `openconnector`.** Recognise it as:
   - `register: 'openconnector'` / `"register": "openconnector"` (named arg or JSON key)
   - the segment after `objects/` in `/apps/openregister/api/objects/openconnector/<schema>`
   - `registers: ['openconnector']`, `components.registers.openconnector`,
     its `"slug"`, `"tablePrefix"`, `"folder"`
   - `targetId: "openconnector/<schema>"`, `objectType: 'openconnector-*'`,
     channel names `openconnector-<schema>`
   - the FIRST positional/named string arg to OR ObjectService/mapper calls
     (`find`, `findAll`, `getObject`, `getObjects`, `saveObject`, `count`, ...)
   - `x-openregister.app` in lib/Settings/*register*.json — leave it, it pairs
     with the frozen slug (a separate coordinated pass moves it).
   Reason: OpenRegister matches registers by slug. Renaming it makes the
   import create a fresh EMPTY register and orphans every stored object,
   silently.

2. **Database table + index names** `openconnector_*` and `oc_openconnector_*`
   anywhere under `lib/Migration/`, and any prose naming them.
   Reason: migrations are executed history recorded by version; rewriting a
   table name in an already-run migration makes a fresh install create
   differently-named tables than an upgraded one. No rename migration is
   written in this PR.

3. **Flow node type ids** — the `NODE_ID` constants and every literal of:
   `openconnector.source-call`, `openconnector.source-paginate`,
   `openconnector.apply-mapping`, `openconnector.contract-commit`,
   `openconnector.contract-sweep`, `openconnector.synchronization-run`,
   `openconnector.fetch-file`
   (in lib/Flow/*.php, lib/Service/*FlowGenerator*.php,
   lib/Service/SynchronizationService.php, lib/Service/CallService.php,
   lib/Service/SynchronizationActionRules.php, lib/Flow/SourceCallConfigGuard.php,
   lib/Exception/FlowNodeException.php, src/main.js,
   src/modals/v2/SynchronizationNodeEditor.vue, tests/Unit/Flow/*)
   Reason: these `type` values are written into stored flow documents
   (OpenRegister objects). Renaming them makes every existing flow reference a
   node type nothing answers to. Same class as OpenRegister's own
   `openregister.trigger-manual` / `openregister.explode` ids alongside them.

4. **`lib/Service/StUFXMLBuilder.php`** default
   `$stuurgegevens['zenderApplicatie'] ?? 'OpenConnector'`.
   Reason: StUF `zender/applicatie` is this app's identity as a municipal
   zaaksysteem knows it. Renaming it here does not rename it there — messages
   get rejected until the municipality re-provisions.

5. **`lib/Service/EudiCredentialOfferService.php`**
   `'credential_issuer' => 'openconnector'`.
   Reason: OpenID4VCI issuer identifier held by already-issued wallet
   credentials and offers.

6. **Docs subdomain `openconnector.conduction.nl`** in
   `docs/static/CNAME`, `.github/workflows/documentation.yml` (`cname:`),
   `docs/docusaurus.config.js`, `docs/static/llms.txt`,
   `src/manifest.json` documentationUrl entries.
   Reason: live DNS record + Pages CNAME; moves in a separate DNS pass.
   `integriq.conduction.nl` does not resolve yet.

7. **`openspec/changes/archive/**`** — do not touch at all. History.
   Also do not rewrite any `@spec openspec/changes/archive/...` path.

8. **`CHANGELOG.md` historical entries** — do not rewrite past releases.

10. **Webhook header names `X-OpenConnector-Signature` / `X-OpenConnector-Event-Id`.**
   Reason: HTTP header names on every outbound webhook delivery — subscribers
   verify the signature BY HEADER NAME — and the `??` default used to verify
   INBOUND webhooks from payment providers, NotifyNL and municipal StUF
   brokers. Renaming breaks verification on both sides, failing closed with
   nothing on our side to indicate a rename caused it.

11. **`BrokeredCallService::APP_ID`, `InlineSecretMigrationPlanner::APP_ID`,
   `InlineSecretMigrationExecutor::APP_ID`** (all `'openconnector'`), and the
   user-facing hints that name it (`Add "openconnector" to the credential's
   allowedApps`).
   Reason: NOT this app's own Nextcloud app id. It is the identity
   **OpenRegister's credential broker** matches against a stored credential's
   `allowedApps`, with a strict `in_array($appId, $allowedApps, true)`. Every
   credential already minted carries `allowedApps: ["openconnector"]`, so
   renaming it makes every brokered credential FAIL CLOSED at call time — as
   an authorisation refusal, not as a rename bug. A cross-app runtime lookup:
   it moves only when OpenRegister's stored credentials are re-provisioned.

9. **Other apps' ids/namespaces** — `procest`, `decidesk`, `docudesk`,
   `shillinq`, `scholiq`, `pipelinq`, `softwareCatalog`, `Doriath`,
   `OpenCatalogi`, `OpenZaak`, `OpenKlant`, `openregister`/`OCA\OpenRegister`,
   `OCA\DAV`, `OCA\Forms`, `OCA\Tables`. Leave every one untouched.

## RENAME (non-exhaustive, for recognition)
- PHP namespace `OCA\OpenConnector\...` -> `OCA\Integriq\...` (incl. `@package`,
  `use`, `{@see}`, `class_exists('OCA\\OpenConnector\\...')`, phpdoc)
- `Application::APP_ID` value, `appName:`/`appId:` args naming THIS app
- route names `openconnector.<controller>.<method>` -> `integriq....`
- URL paths `/apps/openconnector/...`, `/index.php/apps/openconnector/...`
  (but NOT `/apps/openregister/api/objects/openconnector/...`)
- l10n domain: `OC.L10N.register("openconnector"` and every `t('openconnector', ...)`
- occ command prefixes `openconnector:<cmd>`
- `/settings/admin/openconnector`
- cache prefixes `createDistributed('openconnector.*')`
- class names `OpenConnectorAdmin`, `OpenConnectorMetricsProvider`, file names
- CI `app-name: openconnector`, `apps/openconnector/...` paths in workflows
- prose/display text "OpenConnector"/"Open Connector" -> "Integriq"

## Reporting
Report: files changed, any literal you were unsure about (leave it and LIST it),
and any cross-app reference you found that is not already in the list above.
