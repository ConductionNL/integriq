---
kind: spec-only
depends_on: []
---

# Proposal: notificaties-api-subscriber (superseded — retired 2026-09-02)

This directory double-counted a change that had already shipped. The ZGW
Notificaties API subscriber/publisher was implemented and archived on
2026-07-15 (`archive/2026-07-15-notificaties-api-subscriber`, 25/33 tasks
checked with per-task evidence), yet this live copy was resurrected at
0/33: the openconnector→integriq rename applied to the prose, the evidence
notes stripped, every box reset. The machinery exists at HEAD:
`lib/Controller/NotificatiesSubscriberController.php`,
`lib/Service/NotificatiesSubscriberService.php`, the `notificaties` action
kind in `lib/Service/EventService.php`, the callback authentication in
`lib/Service/AuthorizationService.php`, the subscriber routes in
`appinfo/routes.php`, the Abonnementen UI
(`src/views/NotificatiesAbonnement/NotificatiesAbonnementenPage.vue`,
manifest page id `NotificatiesAbonnementen`), and
`tests/Integration/NotificatiesCallbackTest.php`.

No live `@spec` tags point into this directory
(`tests/Unit/Settings/RegisterDescriptorTest.php` and `appinfo/routes.php`
mention it in prose comments only, which this retirement keeps valid by
leaving the directory in place).

## Disposition of the original scope

| Original scope | Where it went |
| --- | --- |
| `abonnement` schema, subscriber service with remote abonnement lifecycle, public callback endpoint with companion-consumer auth, inbound notification → CloudEvent normalization, outbound `notificaties` event action, Abonnementen page + form | **Already shipped and archived**: `archive/2026-07-15-notificaties-api-subscriber` (25/33 boxes checked), code at HEAD |
| Residual verification: live-instance run of the Abonnementen UI, Newman for the callback + CRUD endpoints, feature docs, screenshot | Open, and honestly unticked in the archived twin (no live instance in that session). Same shape as `approvals-verification-pack`; pick up in a verification pass, not by resurrecting this change |

## Sequencing

Nothing remains to implement from this change directly. The residual
live-instance verification and docs belong to a verification-pack-style
follow-up.

## Archival

This directory is retired in place (not moved or renamed) to keep the diff
reviewable and the prose pointers in `appinfo/routes.php` valid; archive it
via the normal flow at the next sweep.
