# Retrofit — events-cloudevents

Describes observed behavior of 17 methods under `events-cloudevents` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

EventService.php (10 methods):

- `lib/Service/EventService.php::processEvent()`
- `lib/Service/EventService.php::doesEventMatchSubscription()`
- `lib/Service/EventService.php::evaluateFilters()`
- `lib/Service/EventService.php::createEventMessage()`
- `lib/Service/EventService.php::deliverMessage()`
- `lib/Service/EventService.php::processRetries()`
- `lib/Service/EventService.php::pullEvents()`
- `lib/Service/EventService.php::handleObjectCreated()`
- `lib/Service/EventService.php::handleObjectUpdated()`
- `lib/Service/EventService.php::handleObjectDeleted()`

EventsController.php (7 methods):

- `lib/Controller/EventsController.php::messages()`
- `lib/Controller/EventsController.php::subscribe()`
- `lib/Controller/EventsController.php::updateSubscription()`
- `lib/Controller/EventsController.php::unsubscribe()`
- `lib/Controller/EventsController.php::subscriptions()`
- `lib/Controller/EventsController.php::subscriptionMessages()`
- `lib/Controller/EventsController.php::pull()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (every controller endpoint is
  `@NoAdminRequired`/`@NoCSRFRequired` with no IDOR guard — any authed NC user can
  modify/delete any subscription by ID; ExpressionLanguage evaluator runs on
  subscriber-supplied input; no auth on `pull` for cross-subscription access)

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
