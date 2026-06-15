# Contract: openconnector-services-direct-or-usage

## Consumers

This change does NOT introduce new HTTP endpoints. It preserves every
existing REST endpoint that openconnector controllers already expose, and
guarantees their wire format is unchanged before/after the rewrite. The
"contract" surface for this change is therefore:

1. **Preserved external HTTP wire format** for every existing
   openconnector REST endpoint — for downstream apps and the Vue
   frontend.
2. **Removed internal PHP API** — the 15 `<Resource>Mapper.php` classes
   and the 15 `<Resource>` entity classes are deleted. Any in-process
   consumer that imports `OCA\OpenConnector\Db\<Resource>` or
   `OCA\OpenConnector\Db\<Resource>Mapper` MUST migrate to OR's
   `ObjectService` + `ObjectEntity` before this change ships.
3. **Introduced internal PHP API** — input DTO classes under
   `OCA\OpenConnector\Db\Dto\<Resource>Dto` for write-side validation.
   These are new and consumable by controllers/services within
   openconnector only; no cross-app coupling.

### Consumer projects

- `openconnector` (internal) — ~20 services, ~18 controllers, 2 cron
  tasks consume the deleted mapper API. ALL must be rewritten in this
  change.
- `decidesk`, `pipelinq`, `procest`, `docudesk` (downstream apps) —
  consume openconnector REST endpoints (e.g. `GET /api/sources`) and OR's
  generic surface (`/api/objects/openconnector/<schema>/<uuid>`). Wire
  format is unchanged, so downstream apps are NOT affected by this
  change.
- Nextcloud Vue frontend (`src/store/*`) — consumes the same REST
  endpoints. Wire format unchanged.

## Endpoints

This change does NOT add or remove HTTP endpoints. It preserves the wire
contract on every existing endpoint. Representative endpoints are
documented below to make the preservation guarantee precise. The full
list is in `appinfo/routes.php` (unchanged).

### `GET /api/sources` (preserved)
**Auth**: Nextcloud session.

**Request:** query params `_search`, `_limit`, `_offset`, `_filters[…]`
(unchanged from chain B).

**Response (200):**
```json
{
  "results": [
    {
      "id": 1,
      "uuid": "00000000-0000-0000-0000-000000000000",
      "name": "example",
      "type": "api",
      "location": "https://example.example",
      "auth": "none",
      "created": "2026-05-20T12:00:00+00:00",
      "updated": "2026-05-20T12:00:00+00:00"
    }
  ],
  "total": 1,
  "limit": 50,
  "offset": 0
}
```

The shape comes from `\OCA\OpenRegister\Db\ObjectEntity::jsonSerialize()`
on the chain A-declared schema, which by design replicates the legacy
typed-entity shape. Field-by-field parity is asserted by Newman /
contract tests (see test-plan.md TC-CHAIN-C-WIRE-PARITY).

**Errors:**
| Code | Condition                              |
|------|----------------------------------------|
| 401  | Not authenticated                      |
| 500  | OR storage backend unavailable         |

### `GET /api/sources/{id}` (preserved)
**Auth**: Nextcloud session.

**Path param**: `{id}` accepts either integer PK or UUID (preserved from
the chain B facade behaviour).

**Response (200):**
```json
{
  "id": 1,
  "uuid": "00000000-0000-0000-0000-000000000000",
  "name": "example",
  "type": "api"
}
```

**Errors:**
| Code | Condition          |
|------|--------------------|
| 401  | Not authenticated  |
| 404  | Source not found   |

### `POST /api/sources` (preserved)
**Auth**: Nextcloud session, admin scope (existing — unchanged).

**Request:**
```json
{
  "name": "new-source",
  "type": "api",
  "location": "https://api.example",
  "auth": "apikey",
  "apikey": "YOUR_API_KEY_HERE"
}
```

**Response (200):** the created object (same shape as `GET /api/sources/{id}`).

**Errors:**
| Code | Condition                                     |
|------|-----------------------------------------------|
| 400  | DTO validation failure (missing required field, wrong type) |
| 401  | Not authenticated                             |
| 403  | Not admin                                     |
| 422  | OR schema validation failure (e.g. enum violation) |
| 500  | OR storage backend unavailable                |

Note: status code `400` is raised by the controller via the DTO
(`SourceDto::fromArray()` throws `\InvalidArgumentException` → 400);
status code `422` is raised by `ObjectService::saveObject()` when the
schema-level validation (chain A's JSON schema rules) rejects the
payload. Both are documented and preserved across chain B → chain C.

### Remaining 17+ endpoints (preserved)

All other CRUD endpoints follow the same pattern:
- `GET /api/<resource>` — list with `_search`, `_limit`, `_offset`,
  `_filters` query params.
- `GET /api/<resource>/{id}` — single fetch, id accepts integer PK or
  UUID.
- `POST /api/<resource>` — create with DTO validation.
- `PUT /api/<resource>/{id}` — update with DTO validation.
- `DELETE /api/<resource>/{id}` — delete by integer PK or UUID.
- `POST /api/<resource>/{id}/run`, `/test`, `/export`, etc. — resource-
  specific action endpoints; signatures unchanged.

The 15 resources covered: `source`, `consumer`, `endpoint`, `event`,
`event_message`, `event_subscription`, `job`, `job_log`, `mapping`,
`rule`, `synchronization`, `synchronization_contract`,
`synchronization_contract_log`, `synchronization_log`, `call_log`.

## Internal PHP API Contract (changed)

### Removed (DELETED in this change)

| Removed PHP type                                              | Replacement                                                          |
|---------------------------------------------------------------|----------------------------------------------------------------------|
| `OCA\OpenConnector\Db\Source` (and all 14 other entities)     | `\OCA\OpenRegister\Db\ObjectEntity` (read: `$obj->getObject()['key']`) |
| `OCA\OpenConnector\Db\SourceMapper` (and 14 other mappers)    | `OCA\OpenRegister\Service\ObjectService` (DI dependency)              |
| `OCA\OpenConnector\Service\Storage\ObjectMapperFacade`        | `OCA\OpenRegister\Service\ObjectService` (called directly, no facade) |

### Added (NEW in this change)

| Added PHP type                                                | Purpose                                                              |
|---------------------------------------------------------------|----------------------------------------------------------------------|
| `OCA\OpenConnector\Db\Dto\SourceDto` (and 14 others)          | Write-side input validation in controllers and a few services        |

The DTO interface (each of the 15 follows the same shape):

```php
final class SourceDto {
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $location = null,
        // … resource-specific typed properties …
    ) {}

    /** @throws \InvalidArgumentException on missing/invalid input */
    public static function fromArray(array $data): self { /* … */ }

    public function toArray(): array { /* … */ }
}
```

DTOs:
- Have NO `id`, `uuid`, `created`, `updated`, `owner` — those are
  OR-managed.
- Are NEVER returned from controllers — they exist on the write path
  only.
- Are NEVER persisted directly — controllers call `$dto->toArray()` then
  pass to `ObjectService::saveObject(...)`.
- Throw `\InvalidArgumentException` (mapped to HTTP 400 by the
  controller) on validation failure.

### Service API: `ObjectService` usage pattern

Every consumer (service, controller, cron) MUST call OR's `ObjectService`
using the canonical signatures below. These are NOT defined in this
change — they are pre-existing in openregister `>= ^v0.2.10`.

```php
// Read
$obj = $this->objectService->find('openconnector', 'source', $uuid);
$results = $this->objectService->findAll(
    'openconnector', 'source',
    filters: ['type' => 'api'],
    limit: 50, offset: 0
);

// Write
$obj = $this->objectService->saveObject('openconnector', 'source', $data, $uuid);

// Delete
$this->objectService->delete('openconnector', 'source', $uuid);
```

The exact method signatures are pinned in design.md (read at apply-time
from `openregister/lib/Service/ObjectService.php`).

## Error Codes

| Code | Meaning                | Condition                                                              |
|------|------------------------|------------------------------------------------------------------------|
| 400  | Bad request            | DTO validation failure (missing required field, type mismatch)         |
| 401  | Unauthenticated        | No Nextcloud session                                                   |
| 403  | Not authorised         | Session present but lacks scope for the resource action                |
| 404  | Not found              | `find()` raises `DoesNotExistException` (OR layer); controller maps    |
| 422  | OR schema validation   | OR schema rules reject the payload (chain A's `properties.required` etc.) |
| 500  | OR backend unavailable | `IDBConnection` failure or OR exception bubbles unhandled              |

Pre-existing error codes from chain B's facade path (`409` on duplicate,
etc.) are preserved if the underlying OR API still raises them.

## Versioning

- **External REST API version**: `v1` (path-implicit). No change to the
  versioning scheme. Wire format on every endpoint is preserved byte-for-
  byte.
- **Internal PHP API**: chain C is the cutover from the
  `ObjectMapperFacade`-shaped API (chain B) to direct `ObjectService`
  usage. There is no co-existence period inside openconnector — the
  mapper API is deleted in this change.
- **DTO classes**: each DTO is `final` (no inheritance). Adding a field
  to a DTO is a minor (backward-compatible) change as long as the field
  is nullable or has a default. Removing or renaming a DTO field is a
  breaking change and requires a follow-up.

## Breaking Change Policy

- **External REST wire format**: preserved byte-for-byte. This change is
  NOT a breaking change for downstream apps or the Vue frontend.
- **Internal PHP API**: breaking — 31 classes are deleted. The breaking
  surface is fully internal to openconnector; no cross-app PHP imports
  of those types exist (verified by `grep -rln "OCA\\OpenConnector\\Db\\<type>"
  ../*/lib/ ../*/tests/` returning empty for all 30 deleted types
  except openconnector itself).
- **Pre-flight gate**: deploying chain C to an environment where chain B
  has not run (`storage_migrated !== 'true'`) raises `\LogicException` at
  app boot. The app does NOT start until the migration is run. This is
  intentional and documented in release notes.
- **Quality gate**: a custom PHPCS sniff (or equivalent) fails the build
  if any of the 30 deleted PHP types are used in `use` statements under
  `lib/` or `tests/` post-merge. Prevents accidental reintroduction.

## SLA

- **REST endpoint latency**: p95 within 5% of the chain B baseline for
  `find(uuid)` and `findAll(filters)` paths. The chain C path removes
  one method-call hop (facade) but keeps the same OR access pattern.
- **REST endpoint availability**: unchanged. Same Nextcloud session +
  database availability dependencies as chain B.
- **DTO validation overhead**: ≤ 1ms p95 for the typed-property bind
  + `fromArray` call on a `SourceDto`-sized payload (10–20 fields).
  Negligible at request scale.
- **Pre-flight assertion overhead**: one `IAppConfig::getValue` call per
  app boot — cached for the request lifetime. < 1ms.
