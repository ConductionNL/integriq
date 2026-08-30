# synchronization-engine — Delta: nextcloud-form source type

## Purpose

Extends the synchronization engine's source-fetch switch (base spec
REQ-002, and the dispatch pattern already established by REQ-014's
`nextcloud-table` branch) with a `nextcloud-form` branch, so
`sourceType: nextcloud-form` is a recognised, implemented discriminator
alongside `register/schema`, `api`, `database`, `file`, and
`nextcloud-table`. Full behavior (question/answer resolution, coercion,
feature detection) is specced in the new `nextcloud-forms-connector`
capability spec; this delta only extends the base engine's source-fetch
dispatch point. Unlike `nextcloud-table`, there is no accompanying
target-write or deletion-guard branch — `nextcloud-form` is source-only
(`nextcloud-forms-connector` REQ-002).

## ADDED Requirements

### Requirement: `nextcloud-form` source dispatch (REQ-016)

`SynchronizationService::getAllObjectsFromSource()` MUST dispatch
`sourceType: nextcloud-form` to the Forms source adapter (see
`nextcloud-forms-connector` REQ-002) instead of falling through with no
matching `case`. `SynchronizationService::updateTarget()` MUST NOT gain a
`nextcloud-form` branch — a synchronization configured with
`targetType: nextcloud-form` MUST continue to throw `Unsupported target
type: nextcloud-form`, unchanged from the base spec's existing `default`
branch behaviour (`nextcloud-forms-connector` REQ-002 explicitly excludes a
target/write direction).

#### Scenario: source fetch dispatches to the Forms adapter

- **GIVEN** a synchronization with `sourceType: nextcloud-form`
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** the Forms source adapter is invoked and its returned submissions
  are used as the fetched objects, exactly as the `api` branch returns
  `getAllObjectsFromApi()`'s result

#### Scenario: nextcloud-form is not a recognised target type

- **GIVEN** a synchronization with `targetType: nextcloud-form`
- **WHEN** `updateTarget()` runs
- **THEN** it throws `Unsupported target type: nextcloud-form`, identical
  in shape to any other unrecognised target type (unlike
  `nextcloud-table`, which REQ-014 explicitly carves out as a recognised
  target)

#### Scenario: an unrecognised source type remains a silent no-op, unchanged

- **GIVEN** a synchronization with `sourceType: some-future-type` that is
  neither `register/schema`, `api`, `database`, `nextcloud-table`, nor
  `nextcloud-form`
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** the `switch` matches no `case` and an empty array is returned,
  identical to the base spec REQ-002's documented no-op behaviour for
  `register/schema`/`database` — this requirement does not change that
  fallthrough behaviour for any type outside `nextcloud-form`
