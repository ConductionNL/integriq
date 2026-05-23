# ADR-008: Endpoint (and Synchronization) resolve their target via a polymorphic `targetType` / `targetId` pair

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

`lib/Db/Endpoint.php:37-38` carries two fields:

```
protected ?string $targetType = null;
// "source (proxy endpoint) | register/schema (object endpoint)
//  | job (fire an event) | synchronization (sync endpoint)"
protected ?string $targetId = null;
```

`lib/Db/Synchronization.php:37-38` carries a parallel pair:

```
protected ?string $targetId = null;
protected ?string $targetType = null;
// "api | database | register/schema"
```

At request time `EndpointService::handleEndpointRequest()` dispatches on
`targetType` (EndpointService.php:255 and :319):

- `'register/schema'` → `handleSchemaRequest()` which calls OR's
  `ObjectService::getMapper(schema, register)`. The `targetId` in this case
  is a composite `"{registerId}/{schemaId}"` string (e.g. `"20/111"`),
  split at the `/` delimiter before dispatch (EndpointsController.php:470-471).
- `'api'` → `handleSourceRequest()`, which proxies the request through
  `CallService` to the referenced Source. Here `targetId` is the integer PK
  (or UUID) of a `Source` row.
- `'job'` and `'synchronization'` are named in the entity comment but do not
  appear as active switch branches in the current `EndpointService.php` —
  they are intended extensions, not yet implemented.

`ConfigurationService.php:275-295` and `:547-557` also branch on `targetType`
when exporting and importing Endpoint/Synchronization configurations, mapping
integer IDs to slugs for portability.

The polymorphic design deliberately avoids a foreign-key constraint: a single
`targetId` column cannot reference two different tables simultaneously in a
standard relational schema. The OR storage migration spec
(`openconnector-register-storage`) chain A REQ-005 explicitly **excludes**
`Endpoint.targetId` from typed FK relations because the value is
cross-table-polymorphic.

## Decision

The `targetType` / `targetId` pair on `Endpoint` (and `Synchronization`) is
the canonical dispatch mechanism at request-processing time. The valid
`targetType` values for `Endpoint` are:

| `targetType`        | `targetId` format       | Dispatch path                     |
|---------------------|-------------------------|-----------------------------------|
| `register/schema`   | `"{registerId}/{schemaId}"` | ObjectService CRUD              |
| `api`               | Source PK or UUID       | CallService proxy                 |
| `job`               | Job PK or UUID          | (not yet implemented)             |
| `synchronization`   | Synchronization PK/UUID | (not yet implemented)             |

New target types MUST be added to this table in this ADR before implementation.
The composite `"{registerId}/{schemaId}"` format for `register/schema` is the
canonical form; the `ConfigurationService` converts to/from slug pairs for
export portability.

`targetId` MUST NOT be declared as a typed FK column in any future OR schema
declaration for `Endpoint` — this is why the chain B register-storage spec
excludes it.

## Consequences

- Adding a new targetType (e.g. `job`) requires wiring a new dispatch branch
  in `EndpointService::handleEndpointRequest()` AND updating this ADR's table.
- The composite `registerId/schemaId` format is a serialisation convention;
  callers that parse it MUST split on the first `/` and validate that both
  parts are numeric (see EndpointsController.php:471 for the validation
  pattern).
- `ConfigurationService::exportEndpoint()` must slug-translate `targetId`
  before serialisation for all `targetType` values where the ID is
  instance-local (i.e. all except `register/schema` slugs which are already
  portable). Current code handles `api` (Source slug) and
  `register/schema` (register slug + schema slug). New target types must
  add their own slug-translation branch.
- Cross-reference: ADR-002 (MappingService / RuleService) — endpoint rules
  processed by `RuleService` are independent of the target dispatch; they run
  before and after target dispatch.
- Cross-reference: ADR-005 (Source / Synchronization / Contract triad) —
  `targetType='api'` points at a `Source` row; `targetType='synchronization'`
  would point at a `Synchronization` row.
- Cross-reference: `openspec/changes/openconnector-register-storage/` — chain
  A excludes `targetId` from typed FK declarations precisely because of this
  polymorphic pattern.

## Evidence

- `lib/Db/Endpoint.php:37-38` — entity-level comment listing all four
  `targetType` values.
- `lib/Service/EndpointService.php:255` — `if ($endpoint->getTargetType() === 'register/schema')`.
- `lib/Service/EndpointService.php:319` — `if ($endpoint->getTargetType() === 'api')`.
- `lib/Service/EndpointService.php:324` — `throw new Exception('Endpoint must specify either a schema or source connection')` — the fall-through guard confirming only two types are implemented today.
- `lib/Controller/EndpointsController.php:463-471` — `targetId` split on `/`
  for `register/schema`; validation that both parts are numeric.
- `lib/Service/ConfigurationService.php:275-295` — slug translation in export
  for `register/schema` and `api` target types.
- `lib/Db/Synchronization.php:37-38` — parallel `targetType` / `targetId`
  on `Synchronization`.
