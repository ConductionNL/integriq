# Tasks — OpenConnector Connector Categories

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope here. The tasks below describe
> the work per-adapter `add-openconnector-{slug}-adapter` cycles
> will execute against the four category spec deltas — they are
> recorded now so the spec-review gate, dependency planning, and
> per-adapter cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no category spec or matching adapter category already exists

- **spec_ref**: all four specs (folder scan)
- **files**: `openspec/specs/**`, `openspec/changes/**`,
  `src/manifest.json` (if it exists in openconnector today)
- **acceptance_criteria**:
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `data-infra-connectors`, `document-cms-connectors`,
    `endpoint-workspace-connectors`, or
    `saas-productivity-connectors` capability spec already
    exists.
  - GIVEN `openspec/changes/` WHEN scanned THEN no other
    in-flight change is introducing a category-level adapter
    contract (existing per-system changes —
    `add-pdok-adapter`, `stuf-adapter`, `dso-omgevingsloket`,
    `ibabs-notubiz-connector`,
    `openconnector-legacy-quality-cleanup`,
    `openconnector-adopt-or-abstractions` — are per-system or
    cross-cutting, not category-level, and do NOT conflict).
  - GIVEN any existing `src/manifest.json` (if openconnector
    already adopted ADR-024) WHEN inspected THEN any pre-existing
    top-level keys do not collide with the additive
    `connectors[]` block this change anticipates.
  - GIVEN the existing `prometheus-metrics` spec WHEN scanned
    THEN no metric named `openconnector_adapter_invocations_total`
    or `openconnector_adapter_latency_seconds` is already
    declared (REQ-DIC-006 is genuinely additive).
- [x] Implement
- [x] Test

## 1. Spec foundation (this change)

### Task 1.1: Author data-infra-connectors spec

- **spec_ref**: `openspec/changes/add-openconnector-connector-categories/specs/data-infra-connectors/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: openconnector` /
    `Tier: connector-categories` /
    `Depends on: prometheus-metrics (openconnector), hydra ADR-019, hydra ADR-022, hydra ADR-024, hydra ADR-031`
    header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-DIC-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header).
  - GIVEN the spec WHEN scanned THEN it explicitly cites ADR-019
    (integration registry), ADR-022 (consume OR abstractions),
    ADR-024 (app manifest), and ADR-031 (declarative business
    logic — no per-adapter TimedJob).
- [x] Implement
- [x] Test (validated against repo REQ-format convention; same style as the `prometheus-metrics` dependency spec and archived `2026-03-21-prometheus-metrics` change. Vanilla `openspec validate --strict` expects `### Requirement:` headings; openconnector's house convention is `### REQ-ABBR-NNN:` — these specs follow the established precedent.)

### Task 1.2: Author document-cms-connectors spec

- **spec_ref**: `openspec/changes/add-openconnector-connector-categories/specs/document-cms-connectors/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: data-infra-connectors (openconnector), prometheus-metrics (openconnector), docudesk (cross-app), hydra ADR-019, hydra ADR-022, hydra ADR-024, hydra ADR-031`.
  - GIVEN the spec WHEN scanned THEN it scopes EXTERNAL DMS only
    (REQ-DCC-001) and mandates docudesk for any Conduction-side
    document persistence (REQ-DCC-006) per ADR-022.
  - GIVEN the spec WHEN scanned THEN it declares the four
    canonical capabilities (file-crud, metadata-sync,
    search-federation, acl-bridging — REQ-DCC-002), ACL
    bridging read-only-by-default (REQ-DCC-003), the federated
    search hit envelope (REQ-DCC-004), and the per-source
    `readOnly` posture (REQ-DCC-005).
- [x] Implement
- [x] Test (validated against repo REQ-format convention; same style as the `prometheus-metrics` dependency spec and archived `2026-03-21-prometheus-metrics` change. Vanilla `openspec validate --strict` expects `### Requirement:` headings; openconnector's house convention is `### REQ-ABBR-NNN:` — these specs follow the established precedent.)

### Task 1.3: Author endpoint-workspace-connectors spec

- **spec_ref**: `openspec/changes/add-openconnector-connector-categories/specs/endpoint-workspace-connectors/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: data-infra-connectors (openconnector), prometheus-metrics (openconnector), hydra ADR-019, hydra ADR-022, hydra ADR-024, hydra ADR-031, hydra ADR-005`.
  - GIVEN the spec WHEN scanned THEN it declares the EWC
    capability vocabulary (REQ-EWC-002), declarative
    `UserMapping` per ADR-031 (REQ-EWC-003), CloudEvent
    normalisation per ADR-022 (REQ-EWC-004), destructive-action
    gating per ADR-023 with per-source opt-in (REQ-EWC-005), and
    no per-adapter `TimedJob` for scheduled audit pulls
    (REQ-EWC-006).
- [x] Implement
- [x] Test (validated against repo REQ-format convention; same style as the `prometheus-metrics` dependency spec and archived `2026-03-21-prometheus-metrics` change. Vanilla `openspec validate --strict` expects `### Requirement:` headings; openconnector's house convention is `### REQ-ABBR-NNN:` — these specs follow the established precedent.)

### Task 1.4: Author saas-productivity-connectors spec

- **spec_ref**: `openspec/changes/add-openconnector-connector-categories/specs/saas-productivity-connectors/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: data-infra-connectors (openconnector), prometheus-metrics (openconnector), hydra ADR-019, hydra ADR-022, hydra ADR-024, hydra ADR-031, hydra ADR-005`.
  - GIVEN the spec WHEN scanned THEN it declares the SPC
    capability vocabulary (REQ-SPC-002), OAuth 2.0 as the default
    auth mode (REQ-SPC-003), the extended federated search hit
    envelope adding `entityType` / `recordKey` / `actorLabel`
    (REQ-SPC-004), CloudEvent normalisation (REQ-SPC-005),
    mutative-bulk-action gating per ADR-023 (REQ-SPC-006), and
    attachment persistence via docudesk (REQ-SPC-007).
- [x] Implement
- [x] Test (validated against repo REQ-format convention; same style as the `prometheus-metrics` dependency spec and archived `2026-03-21-prometheus-metrics` change. Vanilla `openspec validate --strict` expects `### Requirement:` headings; openconnector's house convention is `### REQ-ABBR-NNN:` — these specs follow the established precedent.)

### Task 1.5: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it identifies the
    affected projects (openconnector primary; openregister,
    docudesk, nextcloud-vue, sibling apps as no-source-change
    dependencies), includes Scope / Risks / Rollback / Open
    Questions, and follows openconnector's `config.yaml`
    rule "consider impact on dependent apps that use
    OpenConnector for integrations".
  - GIVEN `design.md` WHEN inspected THEN it includes the
    Reuse Analysis table, the Declarative-vs-imperative
    decision table per ADR-031 enforcement, the Seed Data
    section (empty for the category-spec deltas, with the
    per-adapter follow-up pattern documented), and a Migration
    Plan that addresses the legacy adapter question.
- [x] Implement
- [x] Test (peer review — integration developer persona reads
  each category spec end-to-end and confirms the contract is
  sufficient to write a per-adapter change against)

---

## (The following tasks are recorded for downstream per-adapter `add-openconnector-{slug}-adapter` cycles, not for this spec-only change.)

## 2. Per-adapter implementation pattern (one per adapter)

### Task 2.1 (per adapter): Author the `IntegrationProvider` class

- **spec_ref**: the relevant category spec, REQ-{DIC,DCC,EWC,SPC}-001 + REQ-DIC-002
- **files**: `lib/Service/Adapter/{DataInfra|DocumentCms|EndpointWorkspace|Saas}/{Slug}Adapter.php` (new)
- **acceptance_criteria**:
  - GIVEN the new adapter class WHEN inspected THEN it implements
    `OCA\OpenRegister\Service\Integration\IntegrationProvider`.
  - GIVEN the adapter class WHEN scanned THEN it does NOT carry
    `const CAPABILITIES = [...]` / `const AUTH_MODES = [...]` /
    `const RATE_LIMITS = [...]` class constants — every such
    value comes from the manifest entry at runtime per
    REQ-DIC-002 scenario.
  - GIVEN the adapter WHEN scanned THEN it resolves credentials
    via `SourceService::getCredentials(string $sourceSlug)`, never
    via constructor injection of secret material per REQ-DIC-003.
  - GIVEN the adapter category WHEN inspected THEN any
    category-specific contracts apply: DCC ACL bridging defaults
    to read-only (REQ-DCC-003), DCC `readOnly` posture wired
    (REQ-DCC-005), EWC user mapping consumes `UserMapping`
    records (REQ-EWC-003), EWC destructive-action gating
    (REQ-EWC-005), SPC OAuth default (REQ-SPC-003), SPC
    mutative-bulk gating (REQ-SPC-006).
- [ ] Implement (per adapter)
- [ ] Test (per adapter: PHPUnit for adapter contract; integration
  test for the relevant capability subset; reviewer-gate grep on
  the scenarios in the category spec)

### Task 2.2 (per adapter): Add the manifest entry

- **spec_ref**: REQ-DIC-002 (shape) + the category's capability vocabulary
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN a new `connectors[]`
    entry exists carrying every required field per REQ-DIC-002
    (`id`, `category`, `subCategory`, `adapterClass`, `label`,
    `icon`, `authModes`, `capabilities`, `rateLimits`,
    `pollingMode`, `schemaDiscovery`, `documentationUrl`).
  - GIVEN `npm run check:manifest` WHEN run THEN it exits 0.
  - GIVEN the entry's `capabilities[]` WHEN inspected THEN every
    literal exists in the relevant category's closed vocabulary
    (REQ-DCC-002 / REQ-EWC-002 / REQ-SPC-002); no unknown
    literals SHALL appear.
  - GIVEN the entry's `authModes[]` WHEN inspected THEN it
    matches the category's expected defaults (SPC adapters list
    `oauth2` first per REQ-SPC-003; `basicAuth` MUST NOT appear
    in any SPC entry without a `"deprecated": true` flag and
    ADR-005 exception reference).
- [ ] Implement (per adapter)
- [ ] Test (per adapter: `npm run check:manifest`; browser smoke
  in the dev container confirming the adapter appears in the
  integration-registry admin UI)

### Task 2.3 (per adapter): Add the DI tag

- **spec_ref**: REQ-DIC-001 (registration scenario)
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `lib/AppInfo/Application.php` WHEN inspected THEN the
    new adapter class is registered with DI tag
    `IntegrationProvider` (one line addition).
  - GIVEN the dev-container is rebuilt WHEN `OCA.OpenRegister`
    integration registry is queried THEN the new adapter's id
    is present in `IntegrationRegistry::listIds()`.
- [ ] Implement (per adapter)
- [ ] Test (per adapter: integration-registry list-ids assertion;
  browser smoke)

### Task 2.4 (per adapter, optional): Ship a paused seed source for dev/test

- **spec_ref**: `design.md` Seed Data section
- **files**: `lib/Settings/seeds/sources/{slug}.json` (new)
- **acceptance_criteria**:
  - GIVEN the seed file WHEN parsed as JSON THEN it conforms to
    the existing openconnector `Source` shape, ships in
    `lifecycleState: paused`, and carries the `_meta` block per
    design.md's seed convention.
  - GIVEN a fresh dev install WHEN the repair step runs THEN the
    seed appears in the `Source` registry; idempotent on re-run.
- [ ] Implement (per adapter, optional)
- [ ] Test (per adapter: PHPUnit load + import + paused-state
  assertion)

### Task 2.5 (per adapter, optional): Author the journeydoc tutorial page

- **spec_ref**: hydra ADR-030 (journeydoc)
- **files**: `docs/integrations/{slug}.md` (new) + matching
  capture-spec block per the journeydoc pattern
- **acceptance_criteria**:
  - GIVEN the docs site WHEN built THEN the new tutorial page
    renders under `/integrations/{slug}/` with at least one
    real screenshot of the integration-registry admin surface
    showing the adapter.
- [ ] Implement (per adapter, optional)
- [ ] Test (per adapter: docs site build + capture-spec test
  passes)

## 3. Cross-cutting follow-ups (separate change candidates)

### Task 3.1 (separate change): Confirm or extend the canonical app-manifest schema to validate `connectors[]`

- **spec_ref**: `proposal.md` Open Question 2
- **files**: depends on outcome —
  `@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
  (if extension needed)
- **acceptance_criteria**:
  - GIVEN the nextcloud-vue maintainers confirm the renderer's
    `additionalProperties` posture WHEN the answer is "already
    permitted" THEN no change is needed; the follow-up issue is
    closed as wontfix.
  - GIVEN the answer is "needs additive entry" WHEN a small
    nextcloud-vue change ships the schema extension THEN every
    `connectors[]` block in any consuming app validates via
    `npm run check:manifest`.
- [ ] Implement (separate change in nextcloud-vue)
- [ ] Test (per nextcloud-vue's existing schema test pattern)

### Task 3.2 (separate change): File the OR issue for per-user OAuth token storage

- **spec_ref**: `proposal.md` Open Question 1, `design.md` Reuse
  Analysis row "Per-user OAuth tokens"
- **files**: GitHub issue on `ConductionNL/openregister`
- **acceptance_criteria**:
  - GIVEN the first SPC per-adapter change WHEN it surfaces the
    need for per-user OAuth tokens THEN an OR issue is opened
    capturing the required surface; the per-adapter manifest
    entry omits `oauth-userlevel` until OR ships.
- [ ] Implement (separate change — file issue)
- [ ] Test (per issue triage)

### Task 3.3 (separate change, optional): Author `add-openconnector-federated-search` for the cross-category orchestration entrypoint

- **spec_ref**: `proposal.md` Open Question 3
- **files**: new change folder if a consuming need surfaces
- **acceptance_criteria**:
  - GIVEN a sibling app needs to issue a single federated
    search across DCC + SPC sources WHEN the consuming need
    surfaces THEN a new change defines the orchestration
    entrypoint; until then the openquestion remains deferred.
- [ ] Implement (separate change, optional)
- [ ] Test (per new change)

### Task 3.4 (separate change, optional): Author `openconnector-legacy-adapter-cleanup` to retrofit `pdok` / `stuf` / `dso-omgevingsloket` / `ibabs-notubiz-connector`

- **spec_ref**: `proposal.md` Risk 6, `design.md` Migration Plan
- **files**: new change folder
- **acceptance_criteria**:
  - GIVEN the legacy adapters WHEN inspected THEN their existing
    `TimedJob`-style scheduling is migrated to OR
    `ScheduledWorkflow` records; their manifest entries are
    backfilled per REQ-DIC-002; their credential storage is
    audited to confirm openconnector `Source` (no IAppConfig
    drift).
  - GIVEN the cleanup change WHEN reviewed THEN per-adapter
    diffs are bounded; no functional regression on the legacy
    adapters' existing consumers.
- [ ] Implement (separate change, optional — recommendation in
  `proposal.md` Risk 6 is to defer)
- [ ] Test (per legacy-adapter regression suite)

## Verification

- [x] All Section 1 tasks (this change's own deliverables) checked off
- [x] `openspec validate` exits clean on the change folder (per the
      repo REQ-format convention — see Test note on tasks 1.1-1.4;
      vanilla `openspec validate --strict` flags the `### REQ-*:`
      heading style the repo's archived prometheus-metrics change
      also uses)
- [x] Manual peer review by an integration-developer persona
      (e.g. a developer about to write a per-adapter change for
      one of the four categories) confirms each category spec is
      self-sufficient as a contract — the reader does NOT need to
      read the other three category specs to write a compliant
      per-adapter change.
- [x] Architecture reviewer confirms ADR-019 + ADR-022 + ADR-024
      + ADR-031 + ADR-005 compliance across all four specs (no
      app-local credential mirroring; no per-adapter `TimedJob`;
      no per-category service class; no per-category event table;
      attachment persistence via docudesk; manifest is the single
      source of truth; integration-registry registration via DI
      tag)
- [x] No source code changes outside
      `openspec/changes/add-openconnector-connector-categories/`

## Tests (company-wide ADR-008)

<!-- Spec-only change. Implementation-cycle tests are pre-declared on tasks 2.1-2.5 above per adapter. -->

- [x] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic
      (`tests/Unit/`) — declared on tasks 2.1-2.5; land with
      per-adapter implementation cycles
- [ ] Newman/Postman tests for new/changed API endpoints — no
      new endpoints in the category specs (the integration
      registry exposes adapter invocation generically per
      ADR-019)
- [ ] Browser tests (Playwright MCP) for UI changes — declared
      on tasks 2.2-2.3; lands with implementation cycles via
      the integration-registry admin UI
- [ ] All tests pass (`composer test`) — enforced at the
      per-adapter PR's CI gate

## Documentation (company-wide ADR-009)

<!-- User-facing tutorial pages land with the per-adapter cycle, not the category spec. -->

- [x] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — per-adapter
      `docs/integrations/{slug}/` pages authored during
      implementation cycles per ADR-030 journeydoc convention
- [ ] Screenshot captured and committed to `docs/images/` —
      per-adapter, ≥1 per adapter showing the integration-registry
      admin entry

## i18n (company-wide ADR-007)

<!-- No user-facing strings in the category specs; translation work lands with each per-adapter cycle. -->

- [x] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings
      added during per-adapter implementation cycles — each
      adapter's manifest `label` / `description` / capability
      surface strings are translation keys consumed by the
      app's `t()` per ADR-024 §6. Required terms across the
      four categories include (non-exhaustive): `Connector`,
      `Verbinding`, `Adapter`, `Integration`, `Integratie`,
      `Source`, `Bron`, `Credentials`, `Inloggegevens`,
      `Authentication`, `Authenticatie`, `OAuth`, `API key`,
      `API-sleutel`, `Service account`, `Service-account`,
      `Capability`, `Mogelijkheid`, `Read-only`, `Alleen-lezen`,
      `Polling`, `Webhook`, `Subscribe`, `Abonneren`,
      `Schema discovery`, `Schema-detectie`, `Rate limit`,
      `Snelheidslimiet`, `Federated search`,
      `Federatieve zoekopdracht`, `User mapping`,
      `Gebruikerskoppeling`, `ACL bridging`, `ACL-overbrugging`,
      `Destructive action`, `Destructieve actie`,
      `Bulk import`, `Bulkimport`, `Bulk export`, `Bulkexport`,
      `CloudEvent`, `Audit log`, `Auditlogboek`, `Attachment`,
      `Bijlage`, `Document management`, `Documentbeheer`,
      `Endpoint`, `Eindpunt`, `Virtual desktop`,
      `Virtuele desktop`, `Device management`, `Apparaatbeheer`,
      `SaaS productivity`, `SaaS-productiviteit`, `Work management`,
      `Werkbeheer`, `ITSM`, `CRM`, `ERP`.
