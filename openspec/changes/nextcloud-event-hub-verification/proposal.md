---
kind: code
depends_on: []
---

# Proposal: nextcloud-event-hub-verification

## Summary

Close the verification debt the archived `nextcloud-event-hub` change left
open with honest unticked boxes. The most urgent item is not a test but a
fact-check: the Tables and Forms listeners shipped against event class names
the discovery doc itself called "plausible/unverified". An `IEventListener`
registered for a class that does not exist is a silent no-op — no error, no
delivery, nothing logged — so two of the five listener families may not work
at all and nothing would tell us. The rest is the standard pack: Playwright
for the self-service grant flow, Newman for the new subscription fields and
their 403 paths, and feature docs.

## Motivation

The archived twin (`archive/2026-07-15-nextcloud-event-hub`) checked 30/43
boxes; the open ones name exactly this work. It has sat invisible inside a
superseded 0/43 umbrella since 2026-07-15. The class-name spike is the
one item that can invalidate shipped behavior, which is why it is task 1 and
everything Tables/Forms-shaped is sequenced behind it.

## Affected Projects

- [x] Project: `integriq` — a live-instance spike (with a listener fix if the
  spike finds wrong class names), tests, docs. No intentional behavior
  changes.

## Scope

### In Scope

1. Spike: on a live instance with Tables and Forms enabled, trigger a row
   create/update/delete and a form submission and confirm the exact event
   classes dispatched against what `NextcloudTablesEventListener` /
   `NextcloudFormsEventListener` register for. If they differ, fix the
   listener registrations (that fix is in scope: it is the difference between
   the shipped feature existing and not existing).
2. Playwright: `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts` —
   admin grants an event family via the existing ActionAuthMatrix editor, a
   non-admin then self-service-subscribes to that family and cannot subscribe
   to an ungranted one; the subscription modal's action-type picker
   (synchronization | job | webhook) persists its choice.
3. Newman: subscribe with `action` and `retryPolicy`, per-family 403 for
   ungranted non-admins, and the delivery-status read path.
4. Feature docs: a "Nextcloud event triggers" page (file/calendar/Tables/
   Forms families, filtering, actions, retry policy, self-service gating)
   plus a screenshot of the matrix editor showing the event families.

### Out of Scope

- New event families, new action kinds, or filter dialect changes.
- Starting OR flows from events: `nc-events-start-or-flows`.
- Outbound delivery scope: the ADR-041 seam (#1810) and
  `absorb-dossiq-deliveries`.

## Approach

Spike first, against the dev environment with Tables/Forms enabled; record
the observed class names in this change's tasks as evidence. Then tests and
docs in any order.

## Impact

- `lib/EventListener/NextcloudTablesEventListener.php`,
  `lib/EventListener/NextcloudFormsEventListener.php`,
  `lib/AppInfo/Application.php` — only if the spike finds wrong class names.
- `tests/e2e/spec-coverage/nextcloud-event-triggers.spec.ts` — new.
- `tests/postman/` — scenarios added.
- `docs/` + `docs/images/` — new page + screenshot.

## Rollback Strategy

Tests and docs revert cleanly. A listener class-name fix, if any, is a
two-line registration change; reverting it restores the prior (broken)
registration, so it would only be reverted together with evidence the spike
was wrong.
