---
kind: spec-only
depends_on: []
---

# Proposal: environments-and-promotion (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The
environments and promotion feature was implemented and archived on
2026-07-15 (`archive/2026-07-15-environments-and-promotion`, 25/37 tasks
checked with per-task evidence), yet this live copy was resurrected at
0/35: the openconnector→integriq rename applied to the prose, the evidence
notes stripped, every box reset. The machinery exists at HEAD:
`lib/Controller/EnvironmentController.php`,
`lib/Controller/PromotionController.php`,
`lib/Service/EnvironmentService.php`, `lib/Service/PromotionService.php`,
the `lib/Settings/register.d/environments-and-promotion.json` fragment, the
manifest-declared Environments page
(`src/manifest.d/environments-and-promotion.json`, page id
`src-environments`), `src/modals/PromotePreviewModal.vue`, and the
environment/promotion routes in `appinfo/routes.php`.

No live `@spec` tags point into this directory.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `environment` schema, promotion service with preview/confirm, environment + promotion controllers and routes, Environments page (manifest fragment), promote-preview modal | **Already shipped and archived**: `archive/2026-07-15-environments-and-promotion` (25/37 boxes checked), code at HEAD |
| Residual verification: live `occ` install/fragment-merge run, Newman for `/api/environments*` and `/api/promotions*`, browser tests, feature docs, screenshot | Open, and honestly unticked in the archived twin (no live instance in that session; each open box carries its reason). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification and docs belong to a verification-pack-style
follow-up.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable; archive it via the normal flow at the next sweep.
