# Coverage Report — integriq

Generated: 2026-05-24 12:00 UTC
Branch: feat/header-view-logs-v2
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 42 | — (already tagged) |
| plumbing | 65 | — (never tagged) |
| 1 — REQ matched | 9 | `/opsx-annotate integriq` |
| 2a — existing capability, no REQ | 0 (0 clusters) | — |
| 2b — no capability owner | 405 (22 clusters) | `/opsx-reverse-spec integriq --cluster <name>` (bias toward draft-change-aligned clusters first) |
| 3a — REQ broken (code removed) | 1 | Manual triage on metrics endpoint counter |
| 3b — REQ never implemented | 4 | Most map to in-flight changes; track via opsx instead of deferring |
| 4 — ADR conformance | 75 findings across 3 rules | Follow-up issue (most are missing `@spec` in file docblock) |

## Bucket 1 — Ready to annotate (via ghost change `retrofit-2026-05-24-annotate-openconnector`)

### capability: prometheus-metrics → tasks 1-10 (REQ-PROM-001…010)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/MetricsController.php | index() | REQ-PROM-001/002/003 | 0.97 | builds Prometheus text + sets content-type; spec names this file |
| lib/Controller/MetricsController.php | collectSourceMetrics() | REQ-PROM-004 | 0.97 | sources grouped by type; emits integriq_sources_total{type} |
| lib/Controller/MetricsController.php | collectCallMetrics() | REQ-PROM-005 | 0.97 | call_logs grouped by status; emits integriq_calls_total{status} |
| lib/Controller/MetricsController.php | collectSyncMetrics() | REQ-PROM-006 | 0.97 | emits integriq_synchronizations_total + sync_runs_total{status} |
| lib/Controller/MetricsController.php | collectEndpointMetrics() | REQ-PROM-007 | 0.78 (**NEEDS-REVIEW**) | emits gauge but missing per-endpoint hits counter scenario |
| lib/Controller/MetricsController.php | collectJobMetrics() | REQ-PROM-008 | 0.95 | emits integriq_jobs_total + job_runs_total{status} — spec's "Not implemented" note is stale |
| lib/Controller/MetricsController.php | collectMappingRuleMetrics() | REQ-PROM-009 | 0.97 | emits integriq_mappings_total + integriq_rules_total — spec's "Not implemented" note is stale |
| lib/Controller/MetricsController.php | countTable() | REQ-PROM-004/006/007/008/009 | 0.95 | Pass B helper used by all collect* methods |
| lib/Controller/HealthController.php | index() | REQ-PROM-010 | 0.96 | returns {status,checks} with database+sources_table probes |

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

_None._ The only main spec (prometheus-metrics) is fully covered; every other capability lives in `openspec/changes/*` as draft/proposed delta.

## Bucket 2b — No capability owner (reverse-spec --cluster)

22 behavioral clusters (no namespace-word warnings — every label is a behavior, not a directory name). Counts in parentheses.

| Cluster | Methods | Maps to in-flight change | Suggested next |
|---|---|---|---|
| endpoint-runtime | 32 | partial: openconnector-frontend-vue-rewrite, openconnector-comprehensive-tests | `--cluster endpoint-runtime` |
| rule-pipeline | 31 | partial: openconnector-comprehensive-tests | `--cluster rule-pipeline` |
| synchronization-engine | 96 | partial: openconnector-services-direct-or-usage, comprehensive-tests | `--cluster synchronization-engine` (big — chunk) |
| http-call-engine | 15 | — | `--cluster http-call-engine` |
| configuration-export-import | 35 | — | `--cluster configuration-export-import` |
| authentication-twig | 22 | — | `--cluster authentication-twig` |
| authorization-jwt | 9 | — | `--cluster authorization-jwt` |
| object-service-shim | 9 | likely retired by openconnector-services-direct-or-usage | revisit after that change merges |
| ibabs-notubiz-adapter | 4 | ibabs-notubiz-connector (proposed) | `--extend ibabs-notubiz-connector` once promoted |
| stuf-adapter | 5 | stuf-adapter (proposed) | `--extend stuf-adapter` once promoted |
| pdok-adapter | 25 | add-pdok-adapter (draft) | `--extend pdok-adapter` once promoted |
| events-cloudevents | 17 | — | `--cluster events-cloudevents` |
| user-management-and-login | 31 | — | `--cluster user-management-and-login` |
| job-scheduling | 13 | — | `--cluster job-scheduling` |
| logs-and-statistics | 14 | — | `--cluster logs-and-statistics` |
| mapping-and-search | 24 | — | `--cluster mapping-and-search` |
| storage-uploads | 4 | — | `--cluster storage-uploads` |
| xml-response | 7 | — | `--cluster xml-response` |
| flow-token-helper | 21 | — | `--cluster flow-token-helper` |
| organisation-bridge | 6 | — | `--cluster organisation-bridge` |
| software-catalogus-events | 23 | — | `--cluster software-catalogus-events` |
| repair-and-app-boot | 2 | — | `--cluster repair-and-app-boot` |
| frontend-vue-spa | 54 files | openconnector-frontend-vue-rewrite (in-progress) | defer — covered by in-flight change |

(Per-method observed-behavior listings are in `coverage-report.json` under `buckets.bucket_2b`.)

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken / partial
- **prometheus-metrics#REQ-PROM-007 (per-endpoint hits counter)** — current `collectEndpointMetrics()` emits the `integriq_endpoints_total` gauge but NOT the per-endpoint `integriq_endpoint_hits_total{endpoint,method}` counter required by scenarios 1 and 2. Removed-lines cache shows 4 historical references — implementation may have been started then dropped. Spec's "Not implemented" note already calls this out.

### 3b — never implemented (within the prometheus-metrics spec)
- **prometheus-metrics#REQ-PROM-007 scenario 3** — no top-100 cardinality cap on endpoint metrics.
- **Request duration histogram** (spec's Not-Implemented note) — no latency histogram; would require CallService instrumentation.
- **prometheus-metrics#REQ-PROM-010 scenario 5** — critical source reachability probe in `/api/health` is absent; only database + sources_table are checked.

### 3b — in-flight changes (whole-change scope)
All REQs in 16 in-flight change-deltas:

`openconnector-services-direct-or-usage`, `dso-omgevingsloket` (partially implemented in DSO files — Bucket annotated), `openconnector-app-manifest`, `add-pdok-adapter` (partially implemented — Bucket 2b cluster `pdok-adapter`), `openconnector-adopt-or-abstractions`, `openconnector-frontend-vue-rewrite`, `ibabs-notubiz-connector` (partial — Bucket 2b `ibabs-notubiz-adapter`), `add-openconnector-connector-categories` (4 sub-specs: document-cms / saas-productivity / endpoint-workspace / data-infra), `openconnector-register-schema-declaration`, `openconnector-comprehensive-tests`, `openconnector-register-storage`, `stuf-adapter` (partial — Bucket 2b `stuf-adapter`).

These are tracked by the opsx pipeline (`/opsx-apply` per change). Not Bucket-3b in the "remove or defer" sense — they are forward work.

## Bucket 4 — ADR conformance findings

### missing-spec-in-file-docblock (73 files)
73 of the 78 in-scope PHP files lack a file-docblock `@spec openspec/changes/...` tag. Only the 5 DSO files carry tags. List in `coverage-report.json`. Address by `/opsx-annotate` (file-header tags for Bucket 1) and per-change spec adoption (file-header tags emitted for any file touched by a new spec).

### spdx-as-line-comments-not-docblock (1 file)
- `lib/Repair/InitializeRegister.php` — `// SPDX-License-Identifier` and `// SPDX-FileCopyrightText` lines sit outside the PHPDoc block. Per memory `feedback_spdx-in-docblock.md`, SPDX tags must live INSIDE the main docblock.

### direct-sql-violates-adr-001 (1 file, 5 hits)
- `lib/Service/SettingsService.php` — 5 uses of `$this->db->prepare(...)` for admin stats + retention sweeps. ADR-001 prefers OpenRegister abstractions. Likely intentional for cross-schema retention queries that span multiple OR-managed schemas; flagging for human triage rather than auto-rewrite.

## Notes for the human reviewer

- **Branch scanned: `feat/header-view-logs-v2`** (current working branch). Specs are present on this branch; no need to re-run on `development`.
- **Spec staleness flagged**: The `prometheus-metrics/spec.md` "Not implemented" section incorrectly lists REQ-PROM-008 (jobs) and REQ-PROM-009 (mapping/rule totals) as unimplemented. They are implemented in `MetricsController::collectJobMetrics()` and `collectMappingRuleMetrics()` respectively. Update the spec when running `/opsx-annotate`.
- **Bucket 2b is large but well-clustered**: 405 methods across 22 clusters — every cluster is behaviorally named (no namespace-word warnings). About half (synchronization-engine, endpoint-runtime, rule-pipeline, http-call-engine, configuration-export-import) describe the core platform behavior of integriq and need foundational spec coverage. Recommend prioritising those before adapter-specific clusters.
- **DSO is the model**: 42 methods are already annotated to `openspec/changes/dso-omgevingsloket/tasks.md` — a clean retrofit example of how to ghost-change-annotate a slice of behavior.
- **Several Bucket 2b clusters map directly to in-flight changes** (iBabs/STuF/PDOK adapters, frontend-vue-spa). When those changes promote from `proposed`→`implemented`, the corresponding cluster transitions from Bucket 2b into the annotated set without needing a separate reverse-spec run — `/opsx-apply` will tag those methods.
- **`OrganisationBridgeService::getOrganisationService()`** has the catch-Throwable→return-null shape flagged by `hydra-gate-unsafe-auth-resolver`. Out of scope for this scan but worth a follow-up gate run.
- **No `.opsx-ignore` file** present — nothing suppressed.
- **No Python**, no ExApp wrapper — pure PHP+Vue. Frontend cluster (54 files) was bucketed once at file granularity; per-component classification deferred to the `openconnector-frontend-vue-rewrite` in-flight change.
