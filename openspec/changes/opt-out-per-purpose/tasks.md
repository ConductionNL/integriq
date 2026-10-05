# Tasks: opt-out-per-purpose (integriq)

Approved by Ruben on 2026-10-05 (decisions 1 and 2 in the proposal). Tests fail first.

## 1. Purpose

- [ ] 1.1 `OptOutCategories::purposeOf()` and `normalisePurpose()`: `marketing`, `service` and empty, with the category names and `all` as input.
  - spec_ref: `specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011`
  - files: `lib/Outbound/Identity/OptOutCategories.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter OptOutCategories`
- [ ] 1.2 `OptOutMatcher` reads the purpose for opt-outs and consent. An empty purpose covers everything.
  - spec_ref: `#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011`
  - files: `lib/Outbound/Identity/OptOutMatcher.php`, `lib/Outbound/Identity/OptOutRegistry.php`, `tests/Unit/Outbound/Identity/OptOutRegistryTest.php`
  - acceptance: the marketing, service, everything and old-row scenarios pass. Red before.
- [ ] 1.3 The row builder normalises the purpose. The dedupe key includes it when set.
  - files: `lib/Outbound/Identity/OptOutRowBuilder.php`, `lib/Db/OptOut.php`
  - acceptance: the two-rows scenario passes. Red before.
- [ ] 1.4 A migration re-keys every row with a purpose.
  - files: `lib/Migration/Version2Date20261007100000.php`, `appinfo/info.xml`
  - acceptance: live, a row written with `purpose: marketing` before the upgrade has the new key after it.

## 2. Links

- [ ] 2.1 v3 tokens carry `p`. `materialFor()` sets it from the category. `inspect()` returns `purpose`.
  - files: `lib/Outbound/Identity/UnsubscribeTokenService.php`, its test
- [ ] 2.2 The POST writes the link's purpose for `this` and an empty purpose for `all`. The page names a marketing stop. Dutch and English strings.
  - files: `lib/Controller/SenderIdentityController.php`, `l10n/en.json`, `l10n/nl.json`
  - acceptance: the marketing-link scenario passes live.

## 3. Probe

- [ ] 3.1 `OutboundSendDecisionRequestedEvent` gains `probe` and `isProbe()`. The listener and `decideMany()` pass it on. A probe writes no log row and mints no material.
  - spec_ref: `#requirement-a-probe-answers-without-writing-req-ooa-012`
  - files: `lib/Event/OutboundSendDecisionRequestedEvent.php`, `lib/EventListener/OutboundSendDecisionRequestedListener.php`, `lib/Outbound/Identity/OptOutRegistry.php`, the listener test
  - acceptance: the three probe scenarios pass. Red before.
- [ ] 3.2 pipelinq `ConsentService::latestState()` asks as a probe (pipelinq PR, branch `fix/latest-state-probe`).

## 4. Proof

- [ ] 4.1 Live proof on a fresh instance: marketing unsubscribe, stop everything, an old row, a probe against a real send.
- [ ] 4.2 `composer check:strict`, npm lint, format and l10n on the final commit.
