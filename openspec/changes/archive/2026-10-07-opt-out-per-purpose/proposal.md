# An opt-out stops one purpose, and a probe asks without logging

Follows `opt-out-before-send` (integriq#2533, merged as b2005341). The matching hydra delta is `opt-out-per-purpose` in ConductionNL/hydra.

## Why

**A marketing unsubscribe stops appointment reminders.** integriq stores a `purpose` on every opt-out (`lib/Outbound/Identity/OptOutRowBuilder.php:91`). Nothing reads it. `OptOutMatcher::matchingOptOut()` (`lib/Outbound/Identity/OptOutMatcher.php:72`) matches on scope only (`:100`). pipelinq writes every marketing withdrawal with `purpose: marketing` (`pipelinq/lib/Service/IntegriqMarketingConsent.php:241`). So a person who leaves a newsletter by email also stops their reminders and case updates by email.

**Asking for a state fills the 7-year log.** pipelinq's `ConsentService::latestState()` (`pipelinq/lib/Service/ConsentService.php:222`) shows a contact's consent on screen. It asks through `OutboundSendDecisionRequestedEvent`. Every ask writes a `suppressed` or `allowed-count` row (`lib/Outbound/Identity/OptOutRegistry.php:607`, `:696`). Nothing was sent, yet the log says a send was stopped. On SMS each ask also stores a short link (`lib/Outbound/Identity/UnsubscribeTokenService.php:575`).

## Ruben's decisions (2026-10-05)

1. **Opt-out per purpose.** A marketing unsubscribe stops marketing only. A service opt-out stops case updates, reminders and service messages. A "stop everything" opt-out stops every non-exempt message. Exempt categories and `reply` keep their rules.
2. **A read-only probe.** The decision event gets a probe flag. A probe gets an answer and writes nothing. Only real sends are logged.

## What changes

- **Two purposes and "everything".** `marketing` covers `marketing`. `service` covers `case-update`, `reminder` and `service`. An empty purpose covers every non-exempt category.
- **Existing rows without a purpose mean "stop everything"** within their scope. That is how they behave today, so no person who opted out starts getting mail.
- **The dedupe key includes the purpose when set.** A marketing opt-out and a "stop everything" opt-out on the same address and scope are two rows. A migration re-keys the rows that already carry a purpose.
- **Version 3 links carry the purpose** in a new `p` claim. A link in a marketing mail stops marketing. A link in a reminder stops service messages. "Stop everything that is not statutory" writes an empty purpose. A v3 link without `p` keeps stopping everything in its scope.
- **Consent follows the same rule.** A marketing consent permits marketing, not a service WhatsApp.
- **`OutboundSendDecisionRequestedEvent` gains `probe`** (last constructor argument, default false). A probe returns the same decisions, without unsubscribe material and without any log row or short link.

## Capabilities

### Modified capabilities

- `outbound-opt-out-authority`: adds REQ-OOA-011 (per purpose) and REQ-OOA-012 (probe).

## Impact

- `lib/Outbound/Identity/`: `OptOutCategories`, `OptOutMatcher`, `OptOutRegistry`, `OptOutRowBuilder`, `UnsubscribeTokenService`.
- `lib/Db/OptOut.php`: the dedupe key.
- `lib/Event/OutboundSendDecisionRequestedEvent.php` and its listener: `probe`.
- `lib/Controller/SenderIdentityController.php` and `templates/unsubscribe.php`: the page names the purpose.
- `lib/Migration/`: one migration re-keys rows with a purpose.
- pipelinq: `latestState()` asks as a probe (separate PR).

## Rollback

Revert the PR. Rows written with a purpose keep their purpose and stop only that purpose until the revert, then stop everything again. A v3 link with `p` still verifies after a revert: the extra claim is ignored.

## Related

- integriq#2533, pipelinq#2162, dossiq#3281, openregister#4367.
- hydra `openspec/changes/opt-out-before-send/design.md` sections 4 and 7.
