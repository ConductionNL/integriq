# Design — Retrofit object-service-shim

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`lib/Service/ObjectService.php` is a 322-line class with two unrelated responsibilities:

1. **MongoDB Data API client** (REQ-001, REQ-002, REQ-003) — five CRUD methods plus an
   aggregation method, all routing through Guzzle to the MongoDB Atlas Data API. Used
   when an operator configures a Mongo source via the openconnector Source UI.
2. **OpenRegister bridge** (REQ-004, REQ-005) — two methods that lazily resolve OR's
   own `ObjectService` and delegate mapper lookups to it.

Both responsibilities are observably in production today on `origin/development`; the
class is in scope for deletion by the chain-C change
`openconnector-services-direct-or-usage`, which cuts every caller over to OR's
`ObjectService` directly.

## Why a single spec rather than two

The two responsibilities share a constructor and live in one class. Splitting them into
two specs would force the reader to chase cross-references for a class that is being
deleted in a few weeks. One spec captures the shim as a whole, matching the cluster
boundary the coverage scanner identified.

## What the spec deliberately does NOT cover

- The `BASE_OBJECT` const is implicit in every REQ's `database`/`collection` defaults
  but is not surfaced as its own REQ — it is a private implementation detail of the
  Data API request shape.
- The constructor (`__construct(IAppManager, ContainerInterface)`) is wiring, not
  observable behaviour, and is not specified.
- Error logging, metrics emission, and audit-trail integration — the class does none of
  these today, and the spec does not mandate them retroactively.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Method | Issue | Severity |
|---|---|---|
| `getClient` | computed `$guzzleConf` is discarded; original `$config` is passed to Guzzle, defeating the `mongodbCluster` strip | medium |
| `getOpenRegisters` | `catch (Exception $e)` returns `null` on container failure — silent fall-through | low (cutover removes the class) |
| `getMapper` | `null->getMapper()` fatal Error path when OR is not installed | low (cutover removes the class) |
| All CRUD | no input validation; malformed `$config` surfaces as Guzzle exception only | low |

The retrofit spec captures these as observed behaviour rather than silently fixing
them via the spec text. If the cutover change wants to harden any of them before
deletion, it would do so as a separate change against this baseline.

## Annotations

Five REQs map to nine methods in one file:

- REQ-001 → `saveObject`, `findObject`, `findObjects`, `updateObject`, `deleteObject`,
  `getClient`
- REQ-002 → `saveObject`
- REQ-003 → `aggregateObjects`
- REQ-004 → `getOpenRegisters`
- REQ-005 → `getMapper`

`saveObject` carries two `@spec` references (REQ-001 + REQ-002) because it implements
both the generic CRUD contract and the UUID-minting contract.

## Validation

After archive, `openspec validate object-service-shim --strict` MUST pass and Specter
MUST register the spec as part of the retrofit cohort.
