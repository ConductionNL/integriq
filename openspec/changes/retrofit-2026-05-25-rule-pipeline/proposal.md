# Retrofit — rule-pipeline

Describes the observed behaviour of 31 methods under the `rule-pipeline`
cluster as 5 new REQs. Code already exists — this change retroactively
specifies it. Source: `openspec/coverage-report.md` generated 2026-05-24,
Bucket 2b cluster `rule-pipeline` (no capability owner).

## Motivation

The endpoint rule engine is core platform behaviour with no spec coverage. Per
ADR-002 it is an integriq-local concept (no OpenRegister equivalent), so it
needs a foundational capability spec under `openspec/specs/`. This is the first
of two shared-`EndpointService` clusters; the endpoint dispatch / caching half
is covered separately by the `endpoint-runtime` cluster.

## Affected code units

`lib/Service/EndpointService.php` (rule-evaluation methods):
- `processRules()`, `checkRuleConditions()`, `updateRequestWithRuleData()` — REQ-RULE-001
- `processSaveObjectRule()`, `processMapping()`, `processMappingRule()`, `processExtendInputRule()`, `processOverrideRule()`, `processLockingRule()` — REQ-RULE-002
- `processAuthenticationRule()`, `processErrorRule()` — REQ-RULE-003
- `processWriteFileRule()`, `processSyncRule()`, `processFilePartRule()`, `processFilePartUploadRule()`, `processJavaScriptRule()`, `processDownloadRule()` — REQ-RULE-004
- `processCustomRule()` — REQ-RULE-005

`lib/Service/RuleService.php` (custom-rule + external-extension methods):
- `processCustomRule()`, `processSoftwareCatalogusRule()`, `getPublishPropertyId()`, `processPropertyDefinitionsAndMetadata()`, `setupOrganizationalFolders()`, `processVoorzieningenData()`, `createConnection()`, `processNodes()`, `createRelation()`, `processCustomConnectionsRule()`, `getExternalObject()`, `extendExternalUrl()` — REQ-RULE-005

(`getRuleById()` is a generic rule lookup used by the dispatch path and is
annotated under the sibling `endpoint-runtime` cluster, per the coverage
report's cluster assignment.)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes.
- Draft REQs that match behaviour (not aspirational).
- Notes sections surface observed-but-suspicious behaviour:
  - `processWriteFileRule` swallows per-file write exceptions silently (REQ-RULE-004).
  - `processJavaScriptRule` is a dispatchable no-op stub (REQ-RULE-004).
  - Authentication rule does not propagate the authenticated principal downstream (REQ-RULE-003).
  - `extendExternalUrl` auto-creates enabled Source rows as a side-effect of a read rule (REQ-RULE-005).
  - Software-catalogus rule carries hard-coded UUID pools / property ids / a test model name (REQ-RULE-005).
  - `getRuleById` silently drops unresolvable rule ids (REQ-RULE-001).

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
