# registry-subscription-connector Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- registry-subscription-connector

## Purpose

The connector half of registry subscriptions (finding B22): turn an
OpenRegister subscription request into a live BRP or KvK subscription, and
turn a source change into a post to OpenRegister's inbound update endpoint.
Integriq holds no copy of the subscribed person or company; the record
stays where the requesting app already keeps it, in OpenRegister. Supersedes
`archive/2026-09-11-superseded-brp-kvk-store-and-subscriptions`, which gave
integriq its own store and was rejected on data-minimisation grounds.

## ADDED Requirements

### Requirement: A subscription provider per registry (REQ-RSC-001)

Integriq MUST define `SubscriptionProviderInterface` with `subscribe`,
`unsubscribe` and `pollChanges`, a `brp` binding using the seeded
`brp-haalcentraal` source's Haal Centraal volgindicaties, a `kvk` binding
using the mutatieservice and a `log` binding for development. A subscribe
failure MUST report `state = failed` with the source's error text, never a
silent `none`.

#### Scenario: The source refuses the volgindicatie
- GIVEN a BRP source without a volgindicatie contract
- WHEN `subscribe()` is called for a BSN
- THEN the result carries `state = failed` and the source's error text
- @e2e exclude {provider failure; covered by PHPUnit with a mock-mode source}

### Requirement: A subscription request is turned into a live subscription (REQ-RSC-002)

On OpenRegister's `RegistrySubscriptionRequestedEvent`, integriq MUST
resolve the registry id and identity value from the event, call the
matching provider's `subscribe()`, and report the resulting state back to
OpenRegister.

#### Scenario: A request is handled by the log provider
- GIVEN a `RegistrySubscriptionRequestedEvent` for registry `brp` and a BSN
- WHEN the listener handles it with the `log` provider bound
- THEN `subscribe()` is called with that BSN and the reported state is `active`
- @e2e exclude {event handling; covered by PHPUnit on the listener with a fake provider}

### Requirement: A polled change is posted to OpenRegister, not stored locally (REQ-RSC-003)

A scheduled job MUST call `pollChanges()` for every provider with an active
subscription and, for each returned change, POST the identity value, the
changed properties and the source's event reference to OpenRegister's
`POST /api/registry/{registry}/updates`. Integriq MUST NOT persist the
changed payload anywhere beyond what the job needs to make that one call.
An unchanged payload MUST post nothing.

#### Scenario: A person moves house
- GIVEN an active BRP subscription and `pollChanges()` returning a new address for its BSN
- WHEN the poll job runs
- THEN one POST to `/api/registry/brp/updates` carries the BSN, the new address and the event reference, and nothing is written to any integriq-owned table
- @e2e exclude {background job against a live registry endpoint; covered by PHPUnit with a log provider fixture and a faked HTTP client}

## Open question this change does not resolve

REQ-RSC-002 assumes OpenRegister's `RegistrySubscriptionRequestedEvent`
reaches integriq via the same CloudEvent fan-out integriq already consumes
for other OpenRegister-side events. `registry-subscriptions` (OpenRegister)
has no implementation yet, so this is unverified. Task 1 in `tasks.md`
blocks on it rather than guessing at a delivery mechanism that might not
exist.
