---
kind: docs
depends_on: []
---

## Why

OpenConnector is Technical Core and already routed at `/connext`. Before beta
release, its four public-facing surfaces — `appinfo/info.xml`, `src/manifest.json`
nav, the `conduction.nl/apps/openconnector` product page (EN+NL), and the
`openconnector.conduction.nl` docs — must agree on feature vocabulary, version,
and dependency declarations. They did not:

- `info.xml` had a single no-lang `<summary>` ("Gateway and Service bus
  functionality") and an emoji-bulleted `<description>` describing a generic
  "ESB-framework" with no real feature names, no Dutch translation, and a
  `php min-version="8.0"` that contradicts `composer.json`'s `"php": "^8.3"`.
- The product page (both EN and NL) and the docs `intro.md` / `docusaurus.config.js`
  tagline claimed **"REST, SOAP, GraphQL, file drops (FTP/SFTP), message queues
  (RabbitMQ/Kafka), and database connectors (MySQL/PostgreSQL/MSSQL)"** as "six
  protocols, one config UI," plus a dedicated Showcase panel claiming named
  **LLM adapters (Claude, Mistral, Ollama, OpenAI)** and a **Windmill/n8n**
  workflow bridge.
- The fleet review (2026-07-07) had already flagged that ~40 connector adapters
  spec'd in `openspec/specs/data-infra-connectors`, `document-cms-connectors`,
  `saas-productivity-connectors`, and `endpoint-workspace-connectors` (Postgres,
  MongoDB, Kafka, RabbitMQ, S3, Snowflake, SharePoint, Slack, Salesforce, etc.)
  do not exist in `lib/Service/Adapter/` — this change independently re-verified
  that finding against HEAD and traced it into the marketing surfaces.

## What Changes

Verified every connector/adapter/standard claim against `lib/` at HEAD, then
reconciled all four surfaces to the actually-shipped feature set. No code was
added; only metadata, product copy, and docs text were corrected. Full
verification trail:

### Verified as real (kept / clarified in copy)

| Claim | Evidence |
|---|---|
| REST (JSON/XML) source calls | `lib/Service/CallService.php` — Guzzle-backed `$this->client->request()`, source `type` enum `["json","xml","soap","ftp","sftp"]` (`docs/schema/Source.json`) |
| SOAP source calls | `lib/Service/SOAPService.php`, `CallService::callSource()` branches on `$sourceType === 'soap'` |
| Endpoints (inbound API surface, `/api/endpoint/{path}`) | `lib/Controller/EndpointsController.php`, `lib/Service/EndpointService.php`, `openspec/specs/endpoint-runtime/spec.md` |
| Consumers (endpoint-auth credentials) | `lib/Controller/ConsumersController.php` |
| Mappings (dot-notation + Twig, delegates to OR per ADR-022) | `lib/Service/MappingService.php`, `lib/Service/SourceMappingService.php`, `openspec/specs/mapping-and-search/spec.md` |
| Rules / endpoint rule pipeline | `lib/Service/RuleService.php`, `openspec/specs/rule-pipeline/spec.md` |
| Synchronizations + per-run contracts | `lib/Service/SynchronizationService.php`, `SynchronizationContractService.php`, `openspec/specs/synchronization-engine` (features.json) |
| Jobs (cron-style scheduling, manual run/test) | `lib/Cron/JobTask.php`, `openspec/specs/job-scheduling/spec.md` |
| CloudEvents dispatch, webhook delivery, **dead-letter capture + replay + discard** | `lib/Service/EventService.php`, `lib/Controller/EventsController.php`, `lib/Cron/EventRetryJob.php` (registered in `info.xml` `<background-jobs>`), `openspec/specs/dead-letter-replay` (archived) |
| Per-object audit trail | `lib/Service/EndpointService.php:1755-1790` reads `objectService->getOpenRegisters()->getLogs($objectId)` |
| PDOK Locatieserver adapter (geocoding, WFS, WMS) | `lib/Sources/Pdok/*SourceAdapter.php`, `lib/Adapters/Pdok/*Client*.php`, `openspec/specs/pdok-adapter/spec.md` |
| StUF-ZKN / StUF-BG adapter | `lib/Service/StUFZKNService.php`, `StUFBGService.php`, `StUFFieldMapper.php`, `StUFXMLBuilder.php`, `openspec/specs/stuf-adapter` |
| DSO / Omgevingsloket adapter | `lib/Service/DSOAdapterService.php`, `DSOParserService.php`, `DSOStatusService.php`, `DSOSamenwerkingService.php`, `lib/Controller/DSOController.php`, `openspec/specs/dso-omgevingsloket` |
| Berichtenbox adapter | `lib/Sources/Berichtenbox/BerichtenboxSourceAdapter.php`, `lib/Adapters/Berichtenbox/BerichtenboxClient*.php` |
| iBabs / NotuBiz connector | `lib/Service/IBabsConnectorService.php`, `lib/Service/NotuBizConnectorService.php`, `openspec/specs/ibabs-notubiz-connector` |
| Prometheus metrics + health (ADR-040 AppHost adoption) | `src/manifest.json` `observability` block, `openspec/specs/prometheus-metrics` |

### Corrected / removed (unverified or fabricated)

| Removed claim | Why |
|---|---|
| **GraphQL** as a supported protocol | Not in the Source `type` enum (`docs/schema/Source.json`); zero GraphQL library dependency in `composer.json`; zero `graphql` hits anywhere in `lib/`. |
| **Message queues (RabbitMQ/Kafka)** | Zero code. Only appears in the unimplemented `data-infra-connectors` spec (REQ-DIC-001, aspirational "MUST be implemented" language, not retrofit). No `RdKafka`/`PhpAmqpLib` dependency, no registered `IntegrationProvider` for either. |
| **Database connectors (MySQL/PostgreSQL/MSSQL) as source types** | Those are the app's *own storage backend* options (`<dependencies><database>` in `info.xml`), not source adapters. No source-side DB client code exists. |
| **File drops (FTP/SFTP)** as a working feature | `ftp`/`sftp` exist as enum values on the Source schema, but `CallService::callSource()` only branches on `soap` vs. everything-else-via-Guzzle-HTTP — no FTP/SFTP client code, no `phpseclib`/`ssh2` dependency. Corrected to describe only what actually executes (REST/SOAP over HTTP); the enum values are a known gap, not a shipped capability. |
| **LLM adapters (Claude, Mistral, Ollama, OpenAI)**, entire Showcase panel with a fake `AgentTrace` call log | Zero hits for `mistral`, `ollama`, `openai`, `claude`, or `anthropic` anywhere in `lib/`. No LLM integration code exists in openconnector at all — this was 100% fabricated marketing copy. Removed and replaced with the three real government-standard adapters. |
| **Windmill/n8n workflow bridge**, named-product Showcase panel | Zero hits for `windmill` or `n8n` in `lib/` (the only "n8n" grep hits were false positives inside vendor lockfiles / unrelated spec slugs, e.g. "software-catalogus-events"). No dedicated bridge code exists; openconnector's generic REST Endpoints capability *could* be called by any workflow engine, but claiming a named, dedicated integration was unverified. Removed. |
| **Mail/Files sidebar routing**, third Showcase panel | Zero `OCA\Mail` / `OCA\Files` references, no sidebar registration code in `lib/AppInfo/Application.php` beyond the one real `IntegrationProvider` (`SynchronizationContractProvider`, which surfaces sync-contract data generically on OR objects — not a dedicated Mail/Files routing feature). Removed. |
| "Tamper-evident" + "WOO and BIO compliance evidence shipped with the install" | The audit trail is real (OR's `getLogs()`), but the hash-chain that would make it tamper-evident is tracked elsewhere as **not yet wired** (see project memory: OR audit-hash-chain never wired). Softened to a factual "keeps an audit trail per record" claim; removed the unverifiable WOO/BIO compliance-evidence assertion. |

### Metadata fixes (`appinfo/info.xml`)

- `<summary>` split into `lang="en"` / `lang="nl"` (was a single no-lang English
  string) — real Dutch, not a translated copy: *"API-gateway en
  data-synchronisatielaag voor Nextcloud."*
- `<description>` rewritten (EN + NL) to name the actual shipped capabilities
  (sources/endpoints/consumers, mappings, sync jobs, dead-letter replay, rules,
  audit trail) instead of the generic "ESB-framework" copy.
- `<dependencies><app>openregister</app></dependencies>` added — `src/manifest.json`
  already declares `"dependencies": ["openregister"]` and the mapping/organisation-bridge
  code (`MappingService`, `OrganisationBridgeService`) calls into OpenRegister at
  runtime (soft-failing when absent); the app-level dependency was undeclared.
  Followed the precedent already shipped in `portaliq`, `larpingapp`, `launchpad`,
  and `scholiq`'s `info.xml`.
- `php min-version` corrected from `8.0` to `8.3` to match `composer.json`'s
  `"php": "^8.3"` — a pre-existing metadata/build mismatch, fixed in the same pass.
- Icon (`img/app.svg`) checked against the brand convention (white fill, 24×24
  viewBox) — already compliant, no change needed.

### Product page (`conduction-website/src/pages/apps/openconnector.mdx` +
NL translation)

- Version bumped from the stale `v1.2` to `v0.2.16` to match `info.xml` (source
  of truth), labelled Beta (unchanged).
- Hero tagline, intro paragraph, "six protocols" FeatureItem, "any source"
  RotatingCard, and the entire Showcase block rewritten per the table above.
- Fixed a dead-docs-link bug found while reconciling: NL page's `secondaryCta`
  pointed at `docs.conduction.nl/openconnector` (does not exist); corrected to
  `openconnector.conduction.nl` to match the EN page and the actual docs deploy
  topology.

### Docs (`openconnector/docs/`)

- `docs/intro.md` frontmatter description and "Sources" bullet list corrected
  (removed "Databases"/"File systems", named the real gov-standard adapters).
- Fixed a copy-paste leftover: the bottom of `intro.md` was headed "# Open
  Register Documentation" (wrong app name) — corrected to "# OpenConnector
  Documentation".
- `docs/docusaurus.config.js` site tagline corrected to match the product page.

## Impact

- Affected files: `appinfo/info.xml`; `conduction-website/src/pages/apps/openconnector.mdx`;
  `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/openconnector.mdx`;
  `openconnector/docs/intro.md`; `openconnector/docs/docusaurus.config.js`.
- No code changes. No behavior changes. No new dependencies.
- `src/manifest.json` nav/menu labels were read as the canonical feature-name
  source and were already accurate — no edit needed there.
