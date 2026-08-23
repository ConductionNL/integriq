---
status: done
---

# object-service-shim Specification

## Purpose
Provides a MongoDB Data API CRUD shim that translates caller arrays into insertOne/findOne/find/updateOne/deleteOne/aggregate requests over a Guzzle client built from caller-supplied configuration, minting a fresh UUIDv4 as both `id` and `_id` on insert. It also resolves the OpenRegister ObjectService opportunistically — returning null when the app is absent or the container cannot bind it — and resolves mappers by register and schema, throwing on unknown object types.

@e2e exclude backend MongoDB Data API CRUD shim service (no browser UI) — all 10 scenarios below are asserted by `OCA\Integriq\Tests\Unit\Service\SourceMappingServiceShimTest` (tests/Unit/Service/SourceMappingServiceShimTest.php), which drives the real `SourceMappingService` through a Guzzle `MockHandler` injected via the caller-supplied config and asserts the outgoing Data API requests; the `openregister app is not installed` scenario is additionally covered by `SourceMappingServiceTest::testGetOpenRegistersReturnsNullWhenNotInstalled`. Both run in the `tests/Unit` PHPUnit suite on every CI leg.

## Requirements
### Requirement: MongoDB Data API CRUD wrapper (REQ-001)

`ObjectService` MUST expose `saveObject`, `findObject`, `findObjects`, `updateObject`,
and `deleteObject` methods that translate caller arrays into MongoDB Data API requests
and POST them through a Guzzle client built from the caller-supplied configuration.
Every call MUST set the `dataSource` field from `$config['mongodbCluster']`, MUST default
the `database`/`collection` to the `BASE_OBJECT` constants (`objects` / `json`), and MUST
return the decoded JSON body of the Data API response. The five operations MUST target,
respectively, the `action/insertOne`, `action/findOne`, `action/find`, `action/updateOne`,
and `action/deleteOne` endpoints relative to the Guzzle base URI supplied via `$config`.

#### Scenario: saveObject posts a document and re-fetches by inserted id

- **GIVEN** a caller supplies a non-empty `$data` array and a `$config` with a
  `mongodbCluster` key
- **WHEN** `saveObject($data, $config)` is invoked
- **THEN** the method SHALL POST `action/insertOne` with the document merged into
  `BASE_OBJECT` and `dataSource` set to `$config['mongodbCluster']`
- **AND** SHALL extract `insertedId` from the response body
- **AND** SHALL return the result of `findObject(['_id' => $insertedId], $config)`

#### Scenario: findObjects returns the raw decoded Data API body

- **GIVEN** a caller supplies a `$filters` array and a Mongo `$config`
- **WHEN** `findObjects($filters, $config)` is invoked
- **THEN** the method SHALL POST `action/find` with `filter` set to `$filters`
- **AND** SHALL return the JSON-decoded response body as an associative array

#### Scenario: deleteObject returns an empty array regardless of upstream response

- **GIVEN** a caller supplies a `$filters` array and a Mongo `$config`
- **WHEN** `deleteObject($filters, $config)` is invoked
- **THEN** the method SHALL POST `action/deleteOne` to the Data API
- **AND** SHALL return `[]` without inspecting the upstream response status or body

#### Scenario: getClient returns a Guzzle Client built from caller-supplied config

- **GIVEN** a caller supplies a `$config` array containing Guzzle options plus
  `mongodbCluster`
- **WHEN** `getClient($config)` is invoked
- **THEN** the method SHALL strip `mongodbCluster` from the local copy
- **AND** SHALL pass the **original** `$config` (including `mongodbCluster`) to the
  `\GuzzleHttp\Client` constructor

#### Notes

- `getClient` constructs the Guzzle client from `$config` directly, not from the
  `mongodbCluster`-stripped local copy — the local copy is computed and discarded. This
  is observed behaviour, not necessarily intended; flagged for follow-up rather than
  silently corrected.
- None of the CRUD methods validate their input arrays. Malformed `$config` (missing
  `mongodbCluster`, missing Guzzle `base_uri`) surfaces as a Guzzle exception thrown
  upward by the calling code.

### Requirement: UUID minting on insert (REQ-002)

When `saveObject` constructs the Mongo document, it MUST mint a fresh UUIDv4 via
`Symfony\Component\Uid\Uuid::v4()` and assign it to **both** the `id` and `_id` keys of
the document before POSTing. Callers MUST NOT supply their own `id` or `_id`; any
caller-supplied values are overwritten.

#### Scenario: caller-supplied id is overwritten by minted UUID

- **GIVEN** a caller invokes `saveObject(['id' => 'caller-supplied', 'foo' => 'bar'], $config)`
- **WHEN** the method constructs the insert payload
- **THEN** `document.id` SHALL be a freshly minted UUIDv4
- **AND** `document._id` SHALL equal `document.id` (same minted UUID)
- **AND** the caller-supplied `'caller-supplied'` value SHALL be discarded

#### Notes

- This is the only mutation `saveObject` makes to the caller's `$data` array — every
  other field is copied verbatim into `document`.

### Requirement: Pipeline-based aggregation (REQ-003)

`ObjectService::aggregateObjects` MUST accept a `$filters` array and a `$pipeline` array
and MUST POST them to the Data API `action/aggregate` endpoint together with the
caller's Mongo `dataSource`. The full decoded JSON response body MUST be returned to
the caller as an associative array. The method MUST NOT post-process the aggregation
result (no facet flattening, no shape normalisation, no result coercion).

#### Scenario: aggregateObjects forwards filters + pipeline verbatim

- **GIVEN** a caller supplies a `$filters` array, a `$pipeline` array, and a Mongo
  `$config`
- **WHEN** `aggregateObjects($filters, $pipeline, $config)` is invoked
- **THEN** the request body SHALL contain `filter`, `pipeline`, and `dataSource` set
  from the caller's inputs
- **AND** the JSON-decoded response body SHALL be returned to the caller as an
  associative array

### Requirement: Opportunistic OpenRegister service resolution (REQ-004)

`ObjectService::getOpenRegisters()` MUST return an instance of
`\OCA\OpenRegister\Service\ObjectService` if and only if (a) the `openregister` app
appears in `IAppManager::getInstalledApps()` AND (b) the PSR-11 container can resolve
the service binding. In every other situation (app not installed, container miss,
container throws) the method MUST return `null` and MUST NOT propagate the exception.

#### Scenario: openregister app is not installed

- **GIVEN** `IAppManager::getInstalledApps()` does not contain `'openregister'`
- **WHEN** `getOpenRegisters()` is invoked
- **THEN** the method SHALL return `null`
- **AND** SHALL NOT call `ContainerInterface::get`

#### Scenario: openregister is installed but the container binding is missing

- **GIVEN** the openregister app is installed
- **WHEN** the container throws `NotFoundExceptionInterface` from
  `ContainerInterface::get`
- **THEN** the method SHALL catch the exception and return `null`
- **AND** SHALL NOT rethrow

#### Notes

- The `catch (Exception $e)` swallows every `Throwable`-shaped failure, including
  container misconfiguration that an operator would want to learn about. This silent
  fall-through is observed behaviour; flagged in case the
  `openconnector-services-direct-or-usage` cutover wants to surface it as a structured
  log line before the shim is removed. Compare with the hydra-gate-unsafe-auth-resolver
  pattern for the security flavour of the same anti-pattern.

### Requirement: Mapper resolution via OpenRegister, with hard failure on unknown types (REQ-005)

`ObjectService::getMapper(?string $objectType=null, ?int $schema=null, ?int $register=null)` MUST
resolve a mapper by delegating to `getOpenRegisters()->getMapper(register, schema)`
when both `$register` and `$schema` are non-null AND `$objectType` is null. In every
other input combination (any `$objectType` value, or missing `$register`/`$schema` while
`$objectType` is null) the method MUST throw `InvalidArgumentException` with the
message `Unknown object type: <objectType>`.

#### Scenario: register + schema resolves an OR mapper

- **GIVEN** the openregister app is installed and a caller invokes
  `getMapper(null, $schemaId, $registerId)`
- **WHEN** `getMapper` runs
- **THEN** the method SHALL call `getOpenRegisters()->getMapper(register: $registerId,
  schema: $schemaId)` and SHALL return that mapper

#### Scenario: any other input combination throws

- **GIVEN** a caller invokes `getMapper('legacyType')` OR
  `getMapper(null, null, $registerId)` OR `getMapper(null, $schemaId, null)`
- **WHEN** `getMapper` runs
- **THEN** the method SHALL throw `InvalidArgumentException` with the message
  `Unknown object type: <objectType>` (where `<objectType>` is the caller's
  `$objectType` argument, possibly null)

#### Notes

- When `getOpenRegisters()` returns `null` (openregister not installed), the delegation
  call becomes `null->getMapper(...)` and produces a fatal `Error` (`Call to a member
  function getMapper() on null`). This is an observed failure mode, not a thrown
  exception; flagged so callers wiring up the cutover know to check the app-installed
  precondition before invoking `getMapper`.

