---
kind: code
---

# Proposal: registry-subscription-connector

## Summary
Integriq is the connector half of registry subscriptions (finding B22):
when an app requests a subscription on one of its own BRP or KvK objects,
integriq registers that subscription at the source (Haal Centraal BRP
volgindicaties, KvK mutatieservice), and when the source reports a change,
integriq posts it to OpenRegister's inbound update endpoint. Integriq holds
no copy of the person or company; the object stays where the requesting app
already keeps it.

## Motivation
Supersedes `archive/2026-09-11-superseded-brp-kvk-store-and-subscriptions`,
rejected 2026-09-11 because it gave integriq a second copy of every
subscribed person and company (a `registryStore` register) where
OpenRegister's `registry-subscriptions`
(`openregister/openspec/changes/registry-subscriptions`, chosen the same
day) updates the app's own record in place. That spec names the split
plainly (design.md D-5): "Consuming apps add the annotation to their schema
JSON, which is config; integriq ships the connector." This change is that
connector, and only that.

dossiq's `HaalCentraalBrpAdapter` and `KvkApiAdapter` look a person or
company up and store the answer once; nothing refreshes it (M1 5.11).
dossiq's `contacts-domain` task 4.3 is blocked on this connector existing:
"Refresh `brpPerson` and `kvkCompany` rows from the source; the index reads
whatever the register set holds until then."

Integriq already seeds a BRP Haal Centraal source
(`archive/2026-07-15-seed-brp-haalcentraal-source`, register
`openconnector`/schema `source`, resolved through `SourceMapper` and
`CallService`) that OpenRegister's own `BrpPersoonProvider` lookup leaf
already calls through for one-shot reads. This change reuses that same
source/CallService path for the subscribe and poll calls, and adds an
equivalent seeded source for the KvK mutatieservice.

## Scope
- A `SubscriptionProviderInterface` (`subscribe`, `unsubscribe`,
  `pollChanges`) with a `brp` binding (Haal Centraal volgindicaties, via the
  existing `brp-haalcentraal` source + `CallService`), a `kvk` binding
  (mutatieservice, via a new seeded `kvk-mutatieservice` source) and a `log`
  binding for local development, mirroring the interface shape the
  superseded design already specified in its REQ-RSUB-002.
- A listener on OpenRegister's `RegistrySubscriptionRequestedEvent`
  (dispatched by the app that owns the object, per
  `registry-subscriptions` REQ 2) that resolves the registry id from the
  event, calls the matching provider's `subscribe()`, and reports the
  result back to OpenRegister (`active`, or `failed` with the source's
  error — never silently `none`).
- A scheduled poll (or push intake, where the source offers one) that calls
  `pollChanges()` per active subscription and, on a change, posts the
  changed owned properties, the identity value and the source's event
  reference to OpenRegister's inbound endpoint,
  `POST /api/registry/{registry}/updates`
  (`registry-subscriptions` REQ 3), authenticated as a connector — an app
  password or the credential-broker grant, per that spec's D-3. An
  unchanged payload posts nothing.
- Unsubscribing when OpenRegister reports the last holder ended its
  subscription (state `ended`).

## Out of scope (dropped from the superseded design)
- **No `registryStore` register, no `holders[]`.** The object OpenRegister
  already holds — in whichever app's register declared the schema — is the
  only copy. Integriq subscribes and forwards; it does not store.
- **No `integriq-registry-subject` data-provider leaf.** OpenRegister's own
  `@self.registry` state and `_registry[state]` / `_registry[updatedBefore]`
  query lenses on the app's own object make a separate leaf redundant.
- **No integriq-owned CloudEvent fan-out for the change itself.**
  OpenRegister dispatches its own event when the inbound update lands; a
  case app subscribes to that, not to an integriq event. Integriq only
  emits its existing operational CloudEvents (subscribe/poll failures) for
  its own job-monitoring surface, unchanged from how every other
  synchronization job in this app already reports.
- Real BRP volgindicatie and KvK mutatieservice traffic. Both need a live
  subscription contract with their operator (RvIG for BRP; see the
  superseded design's Risk, which still applies) that this repo cannot
  arrange. This change ships the connector wired to the `log` provider,
  covered by tests; the `brp` and `kvk` provider bodies are structural
  (they call the existing seeded source through `CallService`) but are not
  exercised against a live source in this change. Task 2 records this
  explicitly rather than checking a box no live system backs.
