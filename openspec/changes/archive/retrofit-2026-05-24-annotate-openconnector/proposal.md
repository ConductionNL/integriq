# Retrofit — annotate openconnector against existing specs

Retroactive annotation of 8 methods across 2 files against 9 REQs in 1 capability (`prometheus-metrics`). No code logic changes. No spec deltas (all REQs already exist in `openspec/specs/`).

Source: `openspec/coverage-report.md` generated 2026-05-24 (Bucket 1 only).

The `MetricsController::collectEndpointMetrics()` entry was flagged `needs_review: true` in the coverage report (REQ-PROM-007 is only partially implemented — the per-endpoint `openconnector_endpoint_hits_total` counter is missing). It is intentionally **not** annotated here; it will be handled when REQ-PROM-007 ships in full, separately from this annotation pass.

See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
