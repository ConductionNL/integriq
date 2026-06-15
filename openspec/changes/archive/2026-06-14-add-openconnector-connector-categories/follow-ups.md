# Follow-ups — OpenConnector Connector Categories

This umbrella spec change is intentionally **spec-only** (see
`proposal.md` Scope). It ships four category specs (DIC, DCC, EWC, SPC)
that contract per-adapter and cross-cutting work. The 25 unchecked tasks
in `tasks.md` (Sections 2.x and 3.x) describe that downstream work and
are filed here for the next planner.

Status legend:

- **NOT-STARTED** — no per-adapter change folder exists.
- **STUBBED** — proposal/specs/tasks scaffold present.
- **DRAFTED** — proposal authored, awaiting strict-validate.
- **IN-REVIEW** — PR open.
- **MERGED** — change archived.

---

## Section 2 — Per-adapter implementation pattern

For each per-adapter change `add-openconnector-{slug}-adapter` the
author SHALL execute Tasks 2.1 → 2.5 against the relevant category
spec. Tasks 2.1, 2.2, 2.3 are mandatory; 2.4 and 2.5 are optional.

### Per-adapter task table

| # | Task | File(s) | Acceptance gate |
|---|------|---------|-----------------|
| 2.1 | `IntegrationProvider` class | `lib/Service/Adapter/{DataInfra\|DocumentCms\|EndpointWorkspace\|Saas}/{Slug}Adapter.php` | Implements OR `IntegrationProvider`; no `const CAPABILITIES`; credentials via `SourceService::getCredentials()`. |
| 2.2 | Manifest entry | `src/manifest.json` connectors[] | `npm run check:manifest` green; capabilities[] ⊂ category vocabulary; authModes[] ordered per category. |
| 2.3 | DI tag registration | `lib/AppInfo/Application.php` | One-line `IntegrationProvider` tag; `IntegrationRegistry::listIds()` includes the new id. |
| 2.4 | Paused seed source (optional) | `lib/Settings/seeds/sources/{slug}.json` | Loads on repair step; `lifecycleState: paused`. |
| 2.5 | Journeydoc tutorial (optional) | `docs/integrations/{slug}.md` + capture-spec | Docs site renders the page with ≥1 admin-UI screenshot. |

### Candidate adapter roster (Phase A — seed)

| Category | Slug suggestions | Notes |
|----------|------------------|-------|
| DIC (Data Infra) | `postgresql`, `mongodb`, `clickhouse`, `s3` | The four DIC scenarios in `specs/data-infra-connectors/spec.md` are written against these. |
| DCC (Document CMS) | `sharepoint`, `confluence`, `dropbox-business` | DCC ACL bridging defaults read-only — Sharepoint will exercise REQ-DCC-003. |
| EWC (Endpoint/Workspace) | `intune`, `jamf`, `entra-id` | EWC user mapping + destructive-action gating heavily exercised by Intune. |
| SPC (SaaS Productivity) | `salesforce`, `hubspot`, `topdesk`, `jira-cloud` | SPC OAuth2 default + bulk-action gating exercised by Salesforce + HubSpot. |

Each adapter becomes its own openspec change. The category spec is the
contract; the per-adapter change carries the implementation.

### Why deferred from this change

`proposal.md` Scope is explicit: "Spec-only change. Per-adapter changes
are out of scope; each ships separately." Bundling per-adapter
implementations here would inflate the PR diff to ~20K lines across 4×4
adapters and block the category-spec landing on per-adapter integration
test stability.

---

## Section 3 — Cross-cutting follow-ups (separate change candidates)

### 3.1 — Confirm or extend the canonical app-manifest schema

**Status:** NOT-STARTED.

**Owner repo:** `Conduction/nextcloud-vue`.

**Question for nextcloud-vue maintainers:** does the published
manifest JSON Schema (`src/schemas/app-manifest.schema.json`) permit
additional top-level keys via `additionalProperties: true` (or
unspecified), or does it reject unknown keys?

- If **permitted already**: close as wontfix, document the answer in
  this follow-up file, and proceed.
- If **needs additive entry**: file a small `nextcloud-vue` change
  adding `connectors: { type: 'array', items: { type: 'object',
  required: ['id', 'category', 'adapterClass', 'capabilities'], ... } }`
  to the schema, with a Vitest case asserting validation.

Triggered by: the first per-adapter change running `npm run check:manifest`.

### 3.2 — File the OR issue for per-user OAuth token storage

**Status:** NOT-STARTED.

**Owner repo:** `Conduction/openregister` on Codeberg.

**Trigger:** the first SPC per-adapter change that lists `oauth-userlevel`
in its `authModes[]`.

**Issue body sketch:**

> OpenConnector's SPC category spec (REQ-SPC-003) anticipates per-user
> OAuth tokens for SaaS adapters where each connected user has their own
> session (Salesforce, HubSpot, Jira Cloud). The current OR
> `SourceService::getCredentials()` returns app-level credentials only.
> 
> Required surface:
> 
> - `SourceService::getUserCredentials(string $sourceSlug, string $userUid): array<string, string>`
> - Storage in `oc_openregister_user_tokens` (new table) with columns
>   `source_slug`, `user_uid`, `access_token` (encrypted), `refresh_token`
>   (encrypted), `expires_at`, `scope`.
> - REST: `GET /api/source/{slug}/oauth/start` + callback handler.
> 
> Until OR ships, SPC adapters MUST omit `oauth-userlevel` from
> `authModes[]` and fall back to app-level OAuth (one shared token).

### 3.3 — `add-openconnector-federated-search` orchestration entrypoint

**Status:** NOT-STARTED. Conditional on consumer demand.

**Trigger:** a sibling app (decidesk, pipelinq) needs a single search
fan-out across multiple DCC + SPC sources.

**Scope (when triggered):**

- New `lib/Service/FederatedSearchService.php` with
  `search(string $query, ?array $sourceSlugs = null, int $perSourceLimit = 10): array<FederatedHit>`
- Internal use of the per-adapter `search-federation` capability.
- REST: `POST /api/federated-search` consuming the FederatedHit envelope
  from REQ-DCC-004 / REQ-SPC-004.

### 3.4 — `openconnector-legacy-adapter-cleanup`

**Status:** NOT-STARTED. Recommendation in `proposal.md` Risk 6 is to
DEFER until the new pattern has shipped ≥3 production adapters.

**Trigger:** a regression in the legacy `pdok` / `stuf` /
`dso-omgevingsloket` / `ibabs-notubiz-connector` adapters that forces a
rewrite, or a fleet-wide cleanup window.

**Scope (when triggered):**

- Migrate each legacy adapter's `TimedJob` to OR `ScheduledWorkflow`
  records.
- Backfill `connectors[]` manifest entry per REQ-DIC-002.
- Audit credential storage for `IAppConfig` drift; migrate to OR
  `Source` if found.
- Per-adapter regression suite must stay green.

---

## Cross-repo tracking

The OR per-user OAuth issue (3.2) and any nextcloud-vue schema PR (3.1)
should reference this follow-up doc by URL once the change is merged to
`development`.
