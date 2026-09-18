# Tasks: registry-subscription-connector

## Implementation tasks

### Task 1: `SubscriptionProviderInterface` and the `log` binding
- **spec_ref**: `openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001`
- **files**: `lib/Service/Registry/SubscriptionProviderInterface.php`, `lib/Service/Registry/SubscriptionResult.php`, `lib/Service/Registry/SubscriptionChange.php`, `lib/Service/Registry/LogSubscriptionProvider.php`, `lib/Service/Registry/SubscriptionRegistry.php`
- [x] Implement
- [x] Test
- [BLOCKED] Confirm with the OpenRegister team whether `RegistrySubscriptionRequestedEvent`
  (design.md D2) is fanned out as a CloudEvent or is in-process only inside
  OpenRegister. `registry-subscriptions` has no implementation yet to check
  against. Do not build the listener in Task 3 against a guessed wire shape.

### Task 2: `BrpVolgindicatieProvider` and `KvkMutatieProvider`
- **spec_ref**: `openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001`
- **files**: `lib/Service/Registry/BrpVolgindicatieProvider.php`, `lib/Service/Registry/KvkMutatieProvider.php`, `lib/Settings/register.d/kvk-mutatieservice-source.json`
- [x] Implement (structural: calls the seeded source through `CallService`, not exercised against a live BRP/KvK subscription contract in this change)
- [x] Test (against a faked `CallService`, not a real source)

### Task 3: The `RegistrySubscriptionRequestedEvent` listener
- **spec_ref**: `openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002`
- **files**: `lib/EventListener/RegistrySubscriptionRequestedListener.php`, `lib/Service/Registry/SubscriptionRequestHandler.php`
- **Depends on**: Task 1's blocked item.
- [x] Implement the half that does not depend on the wire shape: `SubscriptionRequestHandler` takes the request as a payload, subscribes through the matching binding, records the identity on the roster and reports the state back.
- [x] Test
- [x] Bind it to `RegistrySubscriptionRequestedEvent`.
  - 🔴 IT WAS NOT BLOCKED, AND I MEASURED THE WRONG REPO TO CONCLUDE THAT IT
    WAS. On 2026-09-18 I reported it still blocked because the event name
    appears once in THIS repo, in a docblock. That is the wrong place to look
    for a cross-app dependency: OpenRegister owns and emits it, and it has
    shipped. `lib/Event/RegistrySubscriptionRequestedEvent.php` exists there and
    `RegistrySubscriptionNotifier` dispatches it.
  - The wire shape is therefore READ, not guessed: five fields (`objectUuid`,
    `register`, `schema`, `registry`, `identityValue`) and a `getPayload()`
    returning exactly those keys. Two of them, `registry` and `identityValue`,
    are spellings `SubscriptionRequestHandler` already accepted, so the binding
    really was one line, as this file predicted.
  - THE LISTENER READS `getPayload()` RATHER THAN ASSEMBLING ONE FROM THE
    GETTERS. Assembling it here would be a second definition of the wire shape,
    and the two would drift the first time OpenRegister added a field.
  - 🔑 IT SWALLOWS ITS OWN FAILURES. The event is dispatched inside
    OpenRegister's own work, so a registry integriq cannot reach must fail as a
    subscription that did not happen, not as a save that failed for a reason the
    person saving cannot act on. Mutation-checked by narrowing the catch and
    watching the exception escape.
  - The stub under `tests/stubs/` was DIFFED against the real class rather than
    written from the docblock: same five constructor arguments in the same
    order, same five getters, same five payload keys. A stub that drifts from
    the real class can only pass, which is the same defect as a double that adds
    a method the real class lacks.

### Task 4: The poll job and the outbound update
- **spec_ref**: `openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md#requirement-a-polled-change-is-posted-to-openregister-not-stored-locally-req-rsc-003`
- **files**: `lib/BackgroundJob/RegistrySubscriptionPollJob.php`, `lib/Service/Registry/RegistryUpdateClient.php`, `lib/Service/Registry/SubscriptionRoster.php`
- [x] Implement
- [x] Test

## What is built, and what is still blocked

Tasks 1, 2 and 4 are built, with unit tests. Task 3 is built up to the wire:
the handler that turns a request into a live subscription exists and is
tested, and the listener that binds it to OpenRegister's event does not,
because `registry-subscriptions` still has no implementation to bind
against. The roster holds identity values and subscription references only;
the person and the company stay in OpenRegister, which was the whole reason
the store-and-copy design was superseded.

## Why nothing was checked before

This change was written 2026-09-11 to retire the rejected
`brp-kvk-store-and-subscriptions` design and replace it with a spec that
matches the chosen `registry-subscriptions` (OpenRegister) shape. It is not
implemented in the same session: OpenRegister's own capability has no
inbound endpoint to POST to yet, and Task 1's open question has to be
answered before Task 3 can be built against a real event shape rather than
a guess. Implement once `registry-subscriptions` ships its endpoint and its
event delivery mechanism is confirmed.
