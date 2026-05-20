# openconnector-direct-or-usage Specification

**Status**: in-progress

## Overview

This specification covers the requirements for removing all transitional mapper
and entity classes from openconnector and rewriting every consumer (services,
controllers, cron tasks) to use OpenRegister's `ObjectService` directly.
This is the Chain C completion of the strangler-fig migration begun in Chain B
(`openconnector-register-storage`).

All requirements use RFC 2119 keywords (MUST, SHALL, SHOULD, MAY) to indicate
their normative force.

---

## ADDED Requirements

### Requirement: All 15 mapper files MUST be deleted

All fifteen `lib/Db/*Mapper.php` files MUST be deleted from the openconnector
repository. After this change ships, no file under `lib/` or `tests/` SHALL
reference any of these mapper class names in a `use` statement or a `new`
expression.

The 15 files are:
`lib/Db/CallLogMapper.php`, `lib/Db/ConsumerMapper.php`,
`lib/Db/EndpointMapper.php`, `lib/Db/EventMapper.php`,
`lib/Db/EventMessageMapper.php`, `lib/Db/EventSubscriptionMapper.php`,
`lib/Db/JobMapper.php`, `lib/Db/JobLogMapper.php`,
`lib/Db/MappingMapper.php`, `lib/Db/RuleMapper.php`,
`lib/Db/SourceMapper.php`, `lib/Db/SynchronizationMapper.php`,
`lib/Db/SynchronizationContractMapper.php`,
`lib/Db/SynchronizationContractLogMapper.php`,
`lib/Db/SynchronizationLogMapper.php`.

#### Scenario: Mapper files are absent post-merge

GIVEN the chain C merge commit has been applied
WHEN a developer runs `find lib/ -name '*Mapper.php' | grep -v 'StUFFieldMapper'`
THEN the command produces zero output (all legacy mapper files are gone)

#### Scenario: Quality gate rejects mapper re-introduction

GIVEN the quality gate (PHPCS sniff or grep-based composer script) is active
WHEN a developer accidentally adds `use OCA\OpenConnector\Db\SourceMapper;` to any file under `lib/` or `tests/`
THEN `composer check:strict` fails with a human-readable error identifying the forbidden import

---

### Requirement: All 15 entity files MUST be deleted

All fifteen `lib/Db/<Entity>.php` domain-data entity classes MUST be deleted
from the openconnector repository, per ADR-001 ("ALL domain data → OpenRegister
objects. NO custom Entity/Mapper for domain data."). After this change ships,
no file under `lib/` or `tests/` SHALL reference any of these entity class names
in a `use` statement, type hint, or `new` expression.

The 15 files are:
`lib/Db/CallLog.php`, `lib/Db/Consumer.php`, `lib/Db/Endpoint.php`,
`lib/Db/Event.php`, `lib/Db/EventMessage.php`,
`lib/Db/EventSubscription.php`, `lib/Db/Job.php`, `lib/Db/JobLog.php`,
`lib/Db/Mapping.php`, `lib/Db/Rule.php`, `lib/Db/Source.php`,
`lib/Db/Synchronization.php`, `lib/Db/SynchronizationContract.php`,
`lib/Db/SynchronizationContractLog.php`, `lib/Db/SynchronizationLog.php`.

#### Scenario: Entity files are absent post-merge

GIVEN the chain C merge commit has been applied
WHEN a developer runs `find lib/Db/ -maxdepth 1 -name '*.php' -not -path '*/Dto/*'`
THEN the command produces zero output (only the `Dto/` subdirectory remains under `lib/Db/`)

#### Scenario: No entity type hints survive in services

GIVEN the chain C rewrite is complete
WHEN `grep -rn "OCA\\\\OpenConnector\\\\Db\\\\Source\b" lib/ tests/` is run
THEN the command produces zero matches (no surviving references to deleted entity types)

---

### Requirement: The ObjectMapperFacade MUST be deleted

`lib/Service/Storage/ObjectMapperFacade.php` MUST be deleted — no surviving reference SHALL remain.
The file was introduced by chain B as a
transitional abstraction. No file under `lib/` or `tests/` SHALL
import or instantiate `OCA\OpenConnector\Service\Storage\ObjectMapperFacade`
after this change ships. The `lib/Service/Storage/` directory SHALL be removed
entirely if it contains no other files after the deletion.

#### Scenario: Facade file is absent post-merge

GIVEN the chain C merge commit has been applied
WHEN a developer runs `find lib/Service/Storage/ -name 'ObjectMapperFacade.php' 2>/dev/null`
THEN the command produces zero output

#### Scenario: No surviving facade references

GIVEN the quality gate is active
WHEN a developer introduces `use OCA\OpenConnector\Service\Storage\ObjectMapperFacade;` in any file
THEN `composer check:strict` fails with a forbidden-import error

---

### Requirement: Every service MUST be rewritten to inject ObjectService directly

Every `lib/Service/` file that previously injected a mapper MUST be rewritten to inject `\OCA\OpenRegister\Service\ObjectService` directly.
All PHP files under `lib/Service/` that previously injected one or more
`<Resource>Mapper` classes MUST be rewritten to inject
`\OCA\OpenRegister\Service\ObjectService` via constructor injection instead.
Services MUST call OR's `ObjectService` using **named-parameter** invocations (NOT positional) to avoid the context-leak footgun documented at `openregister/lib/Service/ObjectService.php:587-589`. The canonical call shapes are:
- `$objectService->find(id: $uuid, register: 'openconnector', schema: '<schema>')`
- `$objectService->findAll(config: ['filters' => ['register' => 'openconnector', 'schema' => '<schema>', ...]])`
- `$objectService->saveObject(object: $data, register: 'openconnector', schema: '<schema>', uuid: $uuid)` — `$uuid` is null for create, set for update
- `$objectService->deleteObject(uuid: $uuid)` — method is `deleteObject`, NOT `delete`; register/schema not needed since the uuid alone uniquely identifies the object
No service SHALL retain a constructor dependency on any of the 31 deleted types after this change ships.

#### Scenario: Source service reads a Source object via ObjectService

GIVEN `SourceService` has been rewritten
WHEN `SourceService::getSource($uuid)` is called
THEN the service calls `$this->objectService->find(id: $uuid, register: 'openconnector', schema: 'source')` exactly once and returns the resulting `ObjectEntity`

#### Scenario: Job service saves a new job via ObjectService

GIVEN `JobService` has been rewritten
WHEN `JobService::createJob(array $data)` is called with valid job data
THEN the service passes the data to `$this->objectService->saveObject(object: $data, register: 'openconnector', schema: 'job')` and returns the resulting `ObjectEntity`

#### Scenario: Source service deletes a Source via ObjectService

GIVEN `SourceService` has been rewritten
WHEN `SourceService::deleteSource($uuid)` is called
THEN the service calls `$this->objectService->deleteObject(uuid: $uuid)` exactly once and propagates a true return value

#### Scenario: No mapper constructor dependency survives in any service

GIVEN the chain C rewrite is complete
WHEN `grep -rn "Mapper \$" lib/Service/` is run
THEN the command produces zero matches (no mapper-typed constructor parameters remain)

---

### Requirement: Every controller MUST receive data via rewritten services, not via mappers

Every `lib/Controller/` file that previously injected a mapper MUST be rewritten to use only service-layer or `ObjectService` dependencies.
All PHP files under `lib/Controller/` that previously injected a
`<Resource>Mapper` class MUST be rewritten so that their constructors ONLY accept
service-layer or `ObjectService` dependencies — never a mapper. Controllers that
previously called `$this->sourceMapper->find($id)` MUST instead call the
corresponding service method or call `$this->objectService->find(id: $uuid, register: 'openconnector', schema: 'source')` directly.
The HTTP wire format (response JSON shape) SHALL remain byte-for-byte identical
to the chain B baseline, produced via `ObjectEntity::jsonSerialize()`.

#### Scenario: SourcesController returns the correct wire format

GIVEN the Sources controller has been rewritten
WHEN a GET request is made to `/api/sources`
THEN the response JSON contains a `results` array where each element has `id`, `uuid`, `name`, `type`, `created`, `updated` fields matching the chain A schema property names

#### Scenario: Controller rejects invalid POST payload with 400

GIVEN the Sources controller uses `SourceDto::fromArray()` for write validation
WHEN a POST request to `/api/sources` is made without a required `name` field
THEN the controller returns HTTP 400 with an error message identifying the missing field

#### Scenario: No mapper dependency in any controller constructor

GIVEN the chain C rewrite is complete
WHEN `grep -rn "Mapper \$" lib/Controller/` is run
THEN the command produces zero matches

---

### Requirement: Application.php DI bindings MUST be updated

`lib/AppInfo/Application.php` MUST remove every
`$context->registerService(<Resource>Mapper::class, …)` call for the 15 deleted
mapper types. After this change, the `Application` class SHALL NOT register any
of the 31 deleted types as services. `ObjectService` (provided by the
openregister app) SHALL be wired as a constructor-injection dependency wherever
needed, following the standard Nextcloud DI container resolution pattern (no
manual alias required if openregister already registers it).

#### Scenario: Application boots without mapper service registrations

GIVEN the chain C merge is applied and `openconnector.storage_migrated` is `'true'`
WHEN Nextcloud boots the openconnector app
THEN `Application::register()` completes without errors and no mapper alias is registered in the DI container

#### Scenario: Application fails fast when storage_migrated is not set

GIVEN a fresh environment where chain B has not been run (`storage_migrated !== 'true'`)
WHEN Nextcloud boots the openconnector app
THEN `Application::register()` throws `\LogicException` with a message containing the operator runbook command `occ openconnector:migrate-storage`

#### Scenario: Pre-flight check is bypassable in CI via env var

GIVEN the env var `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1` is set
WHEN Nextcloud boots the openconnector app in a CI/test environment
THEN `Application::register()` does NOT throw `\LogicException` even if `storage_migrated` is absent or `'false'`

---

### Requirement: composer check:strict MUST pass with all deleted files removed

`composer check:strict` MUST produce zero errors after deletion of all 31 files (PHPCS, PHPMD, Psalm, PHPStan).
Autoload
configuration in `composer.json` SHALL be updated to remove any classmap or
PSR-4 entries that reference the deleted files or the deleted `lib/Db/` top-level
classes. Any `psalm.xml` or `phpstan.neon` exclusion entries that existed solely
to suppress errors on the now-deleted types MUST be removed.

#### Scenario: Quality gates pass after file deletions

GIVEN all 31 files have been deleted and callers have been rewritten
WHEN `composer check:strict` is run in the openconnector repository root
THEN the command exits with code 0 (PHPCS, PHPMD, Psalm, PHPStan all pass)

#### Scenario: Autoload does not reference deleted classes

GIVEN the chain C merge is applied
WHEN `composer dump-autoload --dry-run` is run
THEN no class-map entry references `OCA\OpenConnector\Db\Source` or any other deleted entity/mapper type

---

### Requirement: No file under lib/ or tests/ may reference deleted types post-merge

A quality gate MUST be added to `composer check:strict` (implemented as a PHPCS
custom sniff or a `composer.json` scripts entry running a grep-based check) that
fails the build with exit code 1 if any of the 31 deleted PHP types
appears in a `use` statement or direct class reference anywhere under `lib/` or
`tests/`. The 31 types are:

- 15 entity classes under `lib/Db/`: `OCA\OpenConnector\Db\<Resource>` for each of the 15 resources (`Source`, `Endpoint`, `Consumer`, `Event`, `EventMessage`, `EventSubscription`, `Job`, `JobLog`, `Mapping`, `Rule`, `Synchronization`, `SynchronizationContract`, `SynchronizationContractLog`, `SynchronizationLog`, `CallLog`)
- 15 mapper classes under `lib/Db/`: `OCA\OpenConnector\Db\<Resource>Mapper` for each of the 15 resources
- 1 facade class under `lib/Service/Storage/`: `OCA\OpenConnector\Service\Storage\ObjectMapperFacade`

This gate SHALL be active in CI and SHALL run on every push to the chain C branch.

#### Scenario: Quality gate blocks forbidden entity import

GIVEN the quality gate is installed and running
WHEN a file under `lib/` contains `use OCA\OpenConnector\Db\Job;`
THEN the quality gate exits non-zero and reports the forbidden import with file and line number

#### Scenario: Quality gate blocks ObjectMapperFacade re-introduction

GIVEN the quality gate is installed and running
WHEN any file under `lib/` or `tests/` contains `use OCA\OpenConnector\Service\Storage\ObjectMapperFacade;`
THEN the quality gate exits non-zero with a message: "ObjectMapperFacade was deliberately deleted in chain C — services MUST inject ObjectService directly"

#### Scenario: Quality gate does not flag DTO or unrelated classes

GIVEN the quality gate is installed
WHEN a file under `lib/Db/Dto/` contains `use OCA\OpenConnector\Db\Dto\SourceDto;`
THEN the quality gate exits zero (DTO imports are permitted)

---

### Requirement: Existing unit tests MUST be rewritten to mock ObjectService

Every `tests/Unit/` file that imported a deleted type MUST be rewritten to mock `\OCA\OpenRegister\Service\ObjectService` instead.
All PHP test files under `tests/Unit/` that previously imported, instantiated,
or mocked one of the 31 deleted types MUST be rewritten to mock
`\OCA\OpenRegister\Service\ObjectService` and assert against
`\OCA\OpenRegister\Db\ObjectEntity` return values instead. The rewritten tests
SHALL maintain at least the same assertion coverage as the pre-rewrite tests.
No test file SHALL contain a `use` statement for any of the 30 deleted entity/mapper
types after this change ships.

#### Scenario: SourceService unit test passes with ObjectService mock

GIVEN the SourceService unit test has been rewritten
WHEN the test calls `SourceService::getSource($uuid)` with a mocked `ObjectService` that returns a stub `ObjectEntity`
THEN the test passes and the assertion verifies that `objectService->find` was called with `('openconnector', 'source', $uuid)`

#### Scenario: All unit tests pass after rewrite

GIVEN all unit tests under `tests/Unit/` have been rewritten
WHEN `composer phpunit` is run targeting the `tests/Unit/` directory
THEN the test suite passes with no errors and coverage is ≥ 80% line / ≥ 70% branch on rewritten services

---

### Requirement: Newman/Postman integration tests MUST still pass

The Newman integration test collection MUST continue to pass without modification — the HTTP surface SHALL be byte-identical before and after chain C.
The Newman (Postman) integration test collection that covers the openconnector
REST API MUST continue to pass without modification after this change ships.
The HTTP surface (endpoint paths, request shapes, response shapes, status codes)
SHALL be identical before and after chain C. Any test that asserts the JSON
response body shape SHALL pass because `ObjectEntity::jsonSerialize()` over the
chain A schema produces the same field names and value types as the deleted typed
entities did.

#### Scenario: Sources list endpoint returns unchanged JSON shape

GIVEN chain C is deployed
WHEN the Newman collection runs the `GET /api/sources` test case
THEN the test passes with an HTTP 200 response whose body matches the stored contract fixture (same field names, same value types)

#### Scenario: Synchronization run endpoint is still reachable

GIVEN chain C is deployed
WHEN the Newman collection runs the `POST /api/synchronizations/{id}/run` test case
THEN the test passes with the expected status code and response body (no regression in the action endpoint layer)

---

### Requirement: Synchronization.sourceId branching logic MUST survive intact

`SynchronizationService` MUST preserve the three-format `sourceId` branching logic (integer-PK, register/schema slug-pair, UUID) verbatim (per ADR-005).
The three-format branching logic for `Synchronization.sourceId` (integer-PK
format, `register/schema` slug-pair format, and UUID format) MUST be preserved
verbatim in the rewritten `SynchronizationService`. Per ADR-005 (Source /
Synchronization / SynchronizationContract triad) and chain B's REQ-004, a
`SynchronizationService` MUST contain a `resolveSyncRef` helper method (or
delegate to a dedicated `lib/Service/Helper/SyncRefResolver` service) that
implements all three format branches and preserves the skip-on-unrecognised
semantics. The helper MUST be unit-tested with at least four scenarios covering
each format variant plus the unrecognised-format fallback.

#### Scenario: Integer-PK sourceId is resolved to a UUID via ObjectService

GIVEN a `Synchronization` record whose `sourceId` is the string `"42"` (legacy integer PK format)
WHEN `SyncRefResolver::resolve("42")` is called
THEN the resolver identifies the variant as `'integer-pk'`, looks up the integer-PK→uuid mapping via the per-resource cache or a one-shot `findAll(config: ['filters' => ['register' => 'openconnector', 'schema' => 'source', 'legacyId' => 42]])` query, then calls `$objectService->find(id: $resolvedUuid, register: 'openconnector', schema: 'source')` to obtain the canonical `ObjectEntity`

#### Scenario: Register-schema slug-pair sourceId is resolved

GIVEN a `Synchronization` record whose `sourceId` is `"openconnector/source"` (slug-pair format)
WHEN `SyncRefResolver::resolve("openconnector/source")` is called
THEN the resolver identifies the variant as `'register-schema'` and returns the resolved pair without a direct ObjectService lookup

#### Scenario: UUID sourceId passes through unchanged

GIVEN a `Synchronization` record whose `sourceId` is `"00000000-0000-0000-0000-000000000000"` (UUID format)
WHEN `SyncRefResolver::resolve("00000000-0000-0000-0000-000000000000")` is called
THEN the resolver identifies the variant as `'uuid'` and returns the value unchanged

#### Scenario: Unrecognised format is skipped without error

GIVEN a `Synchronization` record whose `sourceId` is an empty string or a value matching none of the three formats
WHEN `SyncRefResolver::resolve("")` is called
THEN the resolver returns a result with variant `'unrecognised'` and `SynchronizationService` logs a warning and skips the record without throwing an exception

---

### Requirement: Source credential fields MUST be handled with explicit EncryptionService calls

Every call site reading `apikey`, `password`, `secret`, `jwt`, `jwtId`, or `username` from a Source `ObjectEntity` MUST call `EncryptionService::decrypt(...)` explicitly (per ADR-007).
The six credential-bearing fields on `Source` (`secret`, `password`,
`apikey`, `jwt`, `jwtId`, `username`) are currently stored as plaintext in the
openregister object JSON body. Because chain C removes the implicit getter/setter
encryption hooks that existed in the deleted `Source` entity class, every call
site in `lib/Service/` or `lib/Controller/` that reads one of these fields from
an `ObjectEntity` MUST explicitly call `EncryptionService::decrypt(…)` before
using the value in an outbound HTTP request or logging context. This requirement
acknowledges the current plaintext state (ADR-007) and ensures that when
`EncryptionService` is fully wired in a future change, the explicit call sites
are already in place.

#### Scenario: CallService reads apikey via explicit decrypt call

GIVEN `CallService` has been rewritten
WHEN `CallService` reads the `apikey` field from a Source `ObjectEntity`
THEN the code path calls `$this->encryptionService->decrypt($obj->getObject()['apikey'])` before passing the value to Guzzle auth configuration (not reading the raw field value directly)

#### Scenario: No raw field access for credential fields in any service

GIVEN the chain C rewrite is complete
WHEN `grep -rn "getObject()\['apikey'\]" lib/Service/` is run (without a surrounding decrypt call)
THEN every match is preceded by `$this->encryptionService->decrypt(` on the same line or the immediately preceding line

---

### Requirement: Endpoint targetType/targetId dispatch logic MUST be preserved

`EndpointService` MUST preserve the polymorphic `targetType` / `targetId` dispatch branches for all four known target types intact (per ADR-008).
The polymorphic `targetType` / `targetId` dispatch mechanism in
`EndpointService::handleEndpointRequest()` MUST be preserved intact after the
entity/mapper deletion. The four known `targetType` values (`register/schema`,
`api`, `job`, `synchronization`) and their respective `targetId` parsing rules
MUST continue to work as documented in ADR-008. The rewritten service MUST read
`targetType` and `targetId` from the `ObjectEntity::getObject()` array rather
than from typed entity getters, and the dispatch branching SHALL remain otherwise
unchanged.

#### Scenario: register/schema targetType dispatches to ObjectService CRUD

GIVEN an Endpoint record with `targetType = 'register/schema'` and `targetId = '20/111'`
WHEN a request is made to that endpoint
THEN `EndpointService` splits `targetId` on `/`, validates both parts as numeric, and dispatches to `ObjectService::getMapper(schema, register)` (unchanged from chain B)

#### Scenario: api targetType dispatches to CallService proxy

GIVEN an Endpoint record with `targetType = 'api'` and `targetId = '00000000-0000-0000-0000-000000000000'` (Source UUID)
WHEN a request is made to that endpoint
THEN `EndpointService` reads the Source object via `$objectService->find(id: $targetId, register: 'openconnector', schema: 'source')` and proxies the request through `CallService`

#### Scenario: Unknown targetType returns a meaningful error

GIVEN an Endpoint record with `targetType = 'unknown-type'`
WHEN a request is made to that endpoint
THEN `EndpointService` throws an exception with a message indicating the unrecognised target type (preserving the existing fallback guard)

---

### Requirement: Multi-platform DB compatibility MUST be preserved

All new query code introduced in this change MUST use `IQueryBuilder` / `QBMapper` exclusively (per ADR-009) — no new MySQL-specific raw SQL SHALL be added.
Because chain C removes all `lib/Db/*Mapper.php` files (which were the
primary raw-SQL location), the post-merge `lib/` tree MUST contain no new
MySQL-specific constructs. The known pre-existing MySQL-only SQL in
`SettingsService::applyRetention()` (`DATE_ADD`, `SHOW COLUMNS`, backtick
quoting) is explicitly out of scope for this change — it MUST NOT be fixed here,
but MUST be tracked as a follow-up issue (cross-reference ADR-009).

#### Scenario: No new MySQL-specific SQL is introduced

GIVEN the chain C rewrite is complete
WHEN `grep -rn "DATE_ADD\|SHOW COLUMNS\|INTERVAL.*MICROSECOND" lib/` is run
THEN every match found is a pre-existing occurrence in `SettingsService.php` (not any newly written code)

#### Scenario: New calls to ObjectService use platform-neutral interface

GIVEN the chain C rewrite is complete
WHEN `grep -rn "executeQuery\|createFunction\|backtick" lib/Service/` is run (excluding pre-existing known violations)
THEN the command produces zero NEW occurrences outside of the known pre-existing `SettingsService` violations

---

### Requirement: 15 input DTO classes MUST be introduced for write-side validation

Fifteen thin input DTO classes MUST be created under `lib/Db/Dto/`, one for each
domain resource. Each DTO MUST be a `final` class with typed read-only constructor
properties, a static `fromArray(array $data): self` factory method that throws
`\InvalidArgumentException` on missing or invalid input, and a `toArray(): array`
serialiser. DTOs MUST NOT include `id`, `uuid`, `created`, `updated`, or `owner`
fields — those are OR-managed. DTOs MUST NOT be used on read paths; only on write
paths (`POST`, `PUT`) in controllers and, where necessary, in services for input
validation before calling `ObjectService::saveObject()`.

#### Scenario: SourceDto rejects missing required field

GIVEN the `SourceDto::fromArray()` method is called
WHEN the input array omits the required `name` field
THEN `SourceDto::fromArray()` throws `\InvalidArgumentException` with a message identifying the missing field

#### Scenario: SourceDto serialises to an array matching ObjectService input

GIVEN a valid `SourceDto` has been constructed
WHEN `$dto->toArray()` is called
THEN the returned array contains exactly the user-supplied fields (no `id`, `uuid`, `created`, `updated`) and is safe to pass directly as the `object:` named argument to `$objectService->saveObject(object: $dto->toArray(), register: 'openconnector', schema: 'source')`

#### Scenario: DTO is not returned from any controller response

GIVEN the chain C rewrite is complete
WHEN any controller action that processes a POST or PUT request returns its response
THEN the response JSON is derived from `ObjectEntity::jsonSerialize()` (not from `$dto->toArray()`)
