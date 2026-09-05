---
kind: spec-only
depends_on: []
---

# Proposal: nextcloud-forms-connector (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The
Nextcloud Forms connector was implemented and archived on 2026-07-15
(`archive/2026-07-15-nextcloud-forms-connector`, 24/31 tasks checked with
per-task evidence), yet this live copy was resurrected at 0/31: the
openconnector→integriq rename applied to the prose, the evidence notes
stripped, every box reset. The machinery exists at HEAD:
`lib/Service/Forms/FormsSyncAdapter.php`, `FormsOcsClient.php`,
`FormsClientInterface.php`, `FormsAnswerResolver.php`,
`lib/Controller/FormsBridgeController.php` with its routes, the five
`lib/Exception/Forms*Exception.php` classes, the submission-event wiring in
`lib/Service/EventService.php`, and the test tree
(`tests/Unit/Service/Forms/`, `tests/Integration/Forms/`,
`tests/vitest/formsBridge.spec.js`).

12 live `@spec` tags in `lib/Service/Forms/`, `src/` and `tests/` point at
this directory's `specs/nextcloud-forms-connector/spec.md`,
`specs/sync-editor-ui/spec.md` and two task headings, so those anchor files
and headings stay exactly where they are. The other artifacts (design,
discovery, test plan, the events-cloudevents and synchronization-engine
spec deltas) are removed; they survive verbatim in the archived twin and in
git history.

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| Forms OCS client + adapter, answer-by-question resolution and type coercion, form-as-source synchronization, outbound submission dispatch, form picker + field-mapping helper in the sync editor, unit/integration/vitest coverage | **Already shipped and archived**: `archive/2026-07-15-nextcloud-forms-connector` (24/31 boxes checked), code at HEAD |
| Residual verification: Newman for the three `FormsBridgeController` routes, Playwright for the form picker + mapping helper, a screenshot in `docs/images/` | Open, and honestly unticked in the archived twin (no live instance with the `forms` app in that build). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification belongs to a verification-pack-style follow-up.

## Archival

This directory is retired in place (not moved or renamed): its two spec
files and `tasks.md` are live `@spec` anchor targets, and a rename would
both break those tags and detonate every diff-scoped gate. Archive it via
the normal flow only after those tags are repointed at the main specs.
