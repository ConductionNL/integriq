---
kind: spec-only
depends_on: []
---

# Proposal: secret-hygiene (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The secret
hygiene work was implemented and archived on 2026-07-14
(`archive/2026-07-14-secret-hygiene`, 33/33 tasks checked with per-task
evidence), yet this live copy was resurrected at 0/33: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD:
`lib/Service/Security/SensitiveFieldRegistry.php`, its consumers in
`lib/Service/CallService.php` and every
`lib/Service/ConfigurationHandlers/*Handler.php`, and the full test tree
(`tests/Unit/Service/Security/SensitiveFieldRegistryTest.php` plus a
dedicated test per configuration handler).

No live `@spec` tags point into this directory.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `SensitiveFieldRegistry`, secret masking in export/import configuration handlers, call-log secret scrubbing, tests, docs, i18n (all tasks) | **Already shipped and archived**: `archive/2026-07-14-secret-hygiene` (33/33 boxes checked), code at HEAD |
| Residual work | None — the archived twin closed every box |

## Sequencing

Nothing remains to implement from this change.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable; archive it via the normal flow at the next sweep.
