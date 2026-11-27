# registry-subscription-connector Specification

## Purpose
The connector half of registry subscriptions (finding B22): turn an
OpenRegister subscription request into a live BRP or KvK subscription, and
turn a source change into a post to OpenRegister's inbound update endpoint.
Integriq holds no copy of the subscribed person or company; the record
stays where the requesting app already keeps it, in OpenRegister. Supersedes
`archive/2026-09-11-superseded-brp-kvk-store-and-subscriptions`, which gave
integriq its own store and was rejected on data-minimisation grounds.

## Requirements

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

### Requirement: A change is posted in each target schema's own property names (REQ-RSC-004)

A provider whose source names fields differently from a requesting schema MUST declare, per target schema slug, which source field becomes which schema property. When a subscription goes active, integriq MUST record the requesting object's schema slug for that identity, and the poll job MUST post one update per recorded target schema, carrying only the mapped fields under their schema names. The scenarios below fix what is posted without a map, without a target, and when nothing is left.

#### Scenario: A dossiq person moves house
- GIVEN an active BRP subscription requested by a dossiq `brpPerson` object
- AND `pollChanges()` returning `verblijfplaats` and `naam` for its BSN
- WHEN the poll job runs
- THEN one POST to `/api/registry/brp/updates` carries `residence` and `name`, and no source field name
- @e2e exclude {background job against a live registry endpoint; covered by PHPUnit with a faked update client}

#### Scenario: A target schema without a map keeps the source names
- GIVEN an active KvK subscription requested by a schema the provider has no map for
- WHEN the poll job posts a change for it
- THEN the POST carries the source's own field names
- @e2e exclude {background job; covered by PHPUnit}

#### Scenario: A field the target schema does not take is left out
- GIVEN a BRP change carrying `naam` and a field the `brpPerson` map does not list
- WHEN the change is mapped to `brpPerson`
- THEN only `name` is posted
- @e2e exclude {pure mapping; covered by PHPUnit}

#### Scenario: An identity with no recorded target is posted once, unchanged
- GIVEN an active subscription whose identity has no recorded target schema
- WHEN the poll job posts a change for it
- THEN one POST carries the source's own field names
- @e2e exclude {background job; covered by PHPUnit}

#### Scenario: A mapped change that keeps no field posts nothing
- GIVEN a change whose fields the target schema's map does not list
- WHEN the change is mapped to that schema
- THEN nothing is posted for that schema
- @e2e exclude {pure mapping; covered by PHPUnit}
