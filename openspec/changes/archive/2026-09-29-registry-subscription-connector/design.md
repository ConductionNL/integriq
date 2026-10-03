# Design: registry-subscription-connector

Kind: code. One interface, three providers, one listener, one poll job.

## D1. `SubscriptionProviderInterface`

```php
interface SubscriptionProviderInterface {
    public function subscribe(string $identity): SubscriptionResult;
    public function unsubscribe(string $identity): void;
    public function pollChanges(): iterable; // SubscriptionChange[]
}
```

`SubscriptionResult` carries `state` (`active`|`failed`) and, on `failed`,
the source's error text — never a silent `none`. `SubscriptionChange`
carries `identity`, the changed properties (a subset of what the source
returns) and an `eventReference` string.

- `LogSubscriptionProvider`: the development binding. `subscribe()` always
  returns `active`; `pollChanges()` yields nothing unless a fixture queues a
  change (used by tests).
- `BrpVolgindicatieProvider`: resolves the seeded `brp-haalcentraal` source
  through `SourceMapper` and calls it via `CallService`, the same path
  `BrpPersoonProvider` already uses for one-shot reads. `subscribe()` calls
  `PUT /ingeschrevenpersonen/{bsn}/volgindicaties`; `pollChanges()` calls
  `GET /ingeschrevenpersonen?volgindicatie=...&sinds=` for the BSNs with an
  active subscription.
- `KvkMutatieProvider`: same shape against a new seeded `kvk-mutatieservice`
  source (its own config-kind change, not part of this one — see Task 1).

A registry id (`brp`, `kvk`) maps to a provider through DI tagging, the same
pattern `SourceFetcherRegistry`-style registries in this fleet use elsewhere.

## D2. Handling `RegistrySubscriptionRequestedEvent`

OpenRegister dispatches `RegistrySubscriptionRequestedEvent` in-process when
a user requests a subscription (`registry-subscriptions` REQ 2). Cross-app,
that reaches integriq the way every other OR-side event integriq already
consumes does: OR's own CloudEvent fan-out publishes it, integriq's existing
`EventService` subscription intake picks it up. **This change assumes that
fan-out exists on the OpenRegister side**; if `registry-subscriptions`
ships the event as in-process-only with no CloudEvent companion, task 1
blocks on that gap rather than growing a bespoke integriq-side poll of
OpenRegister for pending requests.

On receipt: resolve the registry id and identity value from the event
payload, call that provider's `subscribe()`, then call OpenRegister back
(mechanism: whatever `registry-subscriptions` exposes for the connector to
report state — the spec does not yet name this endpoint explicitly beyond
"the connector confirms with `active` through the inbound endpoint, or
reports a refusal" in its design D-2; this change treats a
zero-property inbound update carrying only the state as that
confirmation until OpenRegister's spec is more specific).

## D3. Poll job and inbound update

`RegistrySubscriptionPollJob` (a scheduled background job, the same
`BackgroundJob` shape as `RegistryChangesJob` in the superseded design)
iterates active subscriptions, calls `pollChanges()` per provider, and for
each `SubscriptionChange` issues
`POST /api/registry/{registry}/updates` on OpenRegister with the identity
value, the changed owned properties and the event reference, authenticated
as a connector (app password or credential-broker grant, per
`registry-subscriptions` D-3). A 422 (a property outside `owned`) is logged
and does not retry; any other failure is retried on the next scheduled run.

## Risks

- Same RvIG volgindicatie contract risk the superseded design carried: a
  BRP source without a subscription contract makes `subscribe()` return
  `failed`, and this change requires that failure to be reported, never
  swallowed.
- D2's cross-app event delivery is an assumption on OpenRegister's fan-out,
  not yet verified against shipped code (`registry-subscriptions` has no
  implementation yet, see its own repo). Task 1 names this as a blocking
  open question rather than guessing at a wire shape.
