---
kind: spec-only
depends_on: []
---

# Proposal: flow-workflowengine-integration (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The
Nextcloud WorkflowEngine ("Flow") integration was implemented and archived
on 2026-07-15 (`archive/2026-07-15-flow-workflowengine-integration`, 17/23
tasks checked with per-task evidence), yet this live copy was resurrected
at 0/23: the openconnector→integriq rename applied to the prose, the
evidence notes stripped, every box reset. The machinery exists at HEAD:
`lib/WorkflowEngine/CallEndpointOperation.php`,
`FireCloudEventOperation.php`, `RunSynchronizationOperation.php` and
`RegisterOperationsListener.php`, registered from
`lib/AppInfo/Application.php`.

This change registers Integriq operations inside Nextcloud core's own
WorkflowEngine and is unrelated to `integriq-flow-nodes` /
`retire-integriq-flow-schema` (the OpenRegister flow-engine track), which
continue on their own path — do not confuse the two when picking up
residuals.

No live `@spec` tags point into this directory.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| Three WorkflowEngine operations (call endpoint, fire CloudEvent, run synchronization), operation registration listener, check/scope wiring | **Already shipped and archived**: `archive/2026-07-15-flow-workflowengine-integration` (17/23 boxes checked), code at HEAD |
| Residual verification: manual live-instance run through NC's Flow admin UI, screenshot, operation-string l10n | Open, and honestly unticked in the archived twin (that pass was scoped to local checks). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification and l10n belong to a verification-pack-style
follow-up.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable; archive it via the normal flow at the next sweep.
