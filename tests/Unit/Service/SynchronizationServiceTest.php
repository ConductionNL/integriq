<?php

/**
 * Unit tests for SynchronizationService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\Tables\TablesSyncAdapter;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the synchronization orchestration service (OR cutover — no deleted Db types).
 */
class SynchronizationServiceTest extends TestCase {

	/**
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var TablesSyncAdapter|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $tablesSyncAdapter;

	/**
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $container;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->callService = $this->createMock(CallService::class);

		$mappingService = $this->createMock(MappingService::class);
		$container = $this->createMock(ContainerInterface::class);
		$this->container = $container;
		$objectService = $this->createMock(ObjectService::class);
		$synchronizationLogService = $this->createMock(SynchronizationLogService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$approvalService = $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class);
		$this->tablesSyncAdapter = $this->createMock(TablesSyncAdapter::class);

		$this->service = new SynchronizationService(
			$this->callService,
			$mappingService,
			$container,
			$this->orObjectService,
			$objectService,
			$this->logger,
			$synchronizationLogService,
			$appConfig,
			$approvalService,
			$this->tablesSyncAdapter,
		);
	}//end setUp()

	/**
	 * Test that the constructor instantiates SynchronizationService without errors.
	 *
	 * @return void
	 */
	public function testConstructorWiresDependencies(): void {
		$this->assertInstanceOf(SynchronizationService::class, $this->service);
	}//end testConstructorWiresDependencies()

	/**
	 * Test that getSynchronization by id returns the entity from OR find.
	 *
	 * @return void
	 */
	public function testGetSynchronizationByIdReturnsFindResult(): void {
		// Arrange
		$syncEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'my-sync'],
			'sync-uuid-1'
		);

		$this->orObjectService->method('find')->willReturn($syncEntity);

		// Act
		$result = $this->service->getSynchronization('sync-uuid-1');

		// Assert
		$this->assertSame($syncEntity, $result);
	}//end testGetSynchronizationByIdReturnsFindResult()

	/**
	 * Test that getSynchronization throws DoesNotExistException when OR find returns null.
	 *
	 * @return void
	 */
	public function testGetSynchronizationThrowsWhenNotFound(): void {
		// Arrange
		$this->orObjectService->method('find')->willReturn(null);

		// Assert
		$this->expectException(DoesNotExistException::class);

		// Act
		$this->service->getSynchronization('non-existent-uuid');
	}//end testGetSynchronizationThrowsWhenNotFound()

	/**
	 * Test findAllBySourceId returns results array from OR findAll.
	 *
	 * @return void
	 */
	public function testFindAllBySourceIdReturnsSynchronizations(): void {
		// Arrange
		$syncEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'sync-a'],
			'sync-uuid-2'
		);

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$syncEntity], 'total' => 1]);

		// Act
		$result = $this->service->findAllBySourceId('openconnector', 'source');

		// Assert
		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertSame($syncEntity, $result[0]);
	}//end testFindAllBySourceIdReturnsSynchronizations()

	/**
	 * Test handleObjectEventSynchronization silently ignores invalid mutation types.
	 *
	 * @return void
	 */
	public function testHandleObjectEventIgnoresInvalidMutationType(): void {
		// Arrange — real ObjectEntity hydrated with register/schema via the
		// magic setters (the previous test used MockObject->method('getRegister')
		// which fails against the real entity now that ObjectServiceMockBuilder
		// returns a real ObjectEntity to dodge the magic-getUuid stub problem,
		// #1015).
		$objectEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[],
			'obj-uuid-1'
		);
		// Positional args only — Entity::__call's setter uses $args[0].
		$objectEntity->setRegister('openconnector');
		$objectEntity->setSchema('source');

		// OR findAll must not be called for invalid mutation type
		$this->orObjectService->expects($this->never())->method('findAll');

		// Act — 'invalid_type' is not in VALID_MUTATION_TYPES
		$this->service->handleObjectEventSynchronization($objectEntity, 'invalid_type');

		// Assert — reached here means no exception was thrown
		$this->assertTrue(true);
	}//end testHandleObjectEventIgnoresInvalidMutationType()

	/**
	 * Test sortNestedArray returns true for a non-empty array.
	 *
	 * @return void
	 */
	public function testSortNestedArrayReturnsTrueForNonEmptyArray(): void {
		// Arrange
		$array = ['b' => 2, 'a' => 1, 'c' => 3];

		// Act
		$result = $this->service->sortNestedArray($array);

		// Assert
		$this->assertTrue($result);
		// Keys should now be in alphabetical order
		$this->assertSame(['a', 'b', 'c'], array_keys($array));
	}//end testSortNestedArrayReturnsTrueForNonEmptyArray()

	/**
	 * #1007 regression test — sync log + contract log writes MUST be CREATE-only.
	 *
	 * Append-only schemas reject UPDATE (saveObject with a uuid arg). The fix
	 * refactors synchronize() and synchronizeContract() to accumulate state in
	 * memory and write each row exactly once at finalize. This test enforces
	 * that NO call to ORObjectService::saveObject for `synchronization_log` or
	 * `synchronization_contract_log` is invoked with a non-null uuid argument
	 * during a sync — preventing regression of the 405 SCHEMA_APPEND_ONLY bug.
	 *
	 * @return void
	 */
	public function testSynchronizationLogWritesAreCreateOnly(): void {
		// The OpenRegister `synchronization_log` schema is immutable / append-only:
		// any saveObject(uuid: ...) UPDATE is rejected. SynchronizationLogService
		// must therefore persist the run-log via an INSERT (no uuid argument).
		$observedUuid = 'sentinel';
		$observedBody = null;
		$defaultEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['result' => []],
			'log-uuid'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('saveObject')->willReturnCallback(
			static function (
				$object,
				$register = null,
				$schema = null,
				?string $uuid = null,
			) use (&$observedUuid, &$observedBody, $defaultEntity) {
				$observedUuid = $uuid;
				$observedBody = $object;
				return $defaultEntity;
			}
		);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$session = $this->createMock(\OCP\ISession::class);
		$logService = new SynchronizationLogService($orObjectService, $userSession, $session);

		$log = $logService->createFromArray(['synchronizationId' => 'sync-1', 'result' => []]);
		$log->setMessage('Success');
		$logService->update($log);

		// A CREATE (no uuid argument) must have been used; an `id` in the body
		// would make OpenRegister treat the write as an UPDATE, so it must be
		// stripped from the persisted payload.
		$this->assertNull(
			$observedUuid,
			'The run-log must be written with a CREATE (no uuid arg) against the append-only schema'
		);
		$this->assertIsArray($observedBody);
		$this->assertArrayNotHasKey(
			'id',
			$observedBody,
			'The persisted payload must not carry an id (would trigger an append-only UPDATE)'
		);
	}//end testSynchronizationLogWritesAreCreateOnly()

	/**
	 * Regression — the run-log must carry the FK that scopes it to its
	 * synchronization, and a null FK must be visibly absent rather than
	 * silently equivalent.
	 *
	 * `normalize()` strips every null from the payload, which is correct on its
	 * own but turned an upstream defect into silent data loss: the caller built
	 * the payload from `$synchronization['uuid']`, a key OpenRegister does not
	 * expose (it returns the identifier as `id`), so `synchronizationId` was
	 * always null, was stripped here, and every stored row became
	 * unattributable — leaving the "View logs" row action nothing to filter on.
	 *
	 * @return void
	 */
	public function testRunLogPersistsItsSynchronizationForeignKey(): void {
		$observedBody = null;
		$defaultEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['result' => []],
			'log-uuid'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('saveObject')->willReturnCallback(
			static function (
				$object,
				$register = null,
				$schema = null,
				?string $uuid = null,
			) use (&$observedBody, $defaultEntity) {
				$observedBody = $object;
				return $defaultEntity;
			}
		);

		$logService = new SynchronizationLogService(
			$orObjectService,
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\OCP\ISession::class)
		);

		$log = $logService->createFromArray(['synchronizationId' => 'sync-1', 'result' => []]);
		$logService->update($log);

		$this->assertIsArray($observedBody);
		$this->assertSame(
			'sync-1',
			($observedBody['synchronizationId'] ?? null),
			'The persisted run-log must carry synchronizationId, or the logs page cannot be scoped to its synchronization'
		);
	}//end testRunLogPersistsItsSynchronizationForeignKey()

	/**
	 * Companion to the above: a null FK is DROPPED, not persisted as null.
	 *
	 * Documents the exact mechanism that hid the defect — there is no null
	 * `synchronizationId` column to notice in the stored data, the key is
	 * simply absent. Pinning it here means a future change that reintroduces a
	 * null FK fails loudly at the boundary instead of silently producing
	 * unattributable rows.
	 *
	 * @return void
	 */
	public function testRunLogDropsANullSynchronizationForeignKey(): void {
		$observedBody = null;
		$defaultEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['result' => []],
			'log-uuid'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('saveObject')->willReturnCallback(
			static function (
				$object,
				$register = null,
				$schema = null,
				?string $uuid = null,
			) use (&$observedBody, $defaultEntity) {
				$observedBody = $object;
				return $defaultEntity;
			}
		);

		$logService = new SynchronizationLogService(
			$orObjectService,
			$this->createMock(\OCP\IUserSession::class),
			$this->createMock(\OCP\ISession::class)
		);

		$log = $logService->createFromArray(['synchronizationId' => null, 'result' => []]);
		$logService->update($log);

		$this->assertIsArray($observedBody);
		$this->assertArrayNotHasKey(
			'synchronizationId',
			$observedBody,
			'A null FK is stripped by normalize(), which is why the upstream defect produced unattributable rows silently'
		);
	}//end testRunLogDropsANullSynchronizationForeignKey()

	/**
	 * #1007 regression — the append-only run-log is written exactly once.
	 *
	 * SynchronizationLogService::update() is idempotent: the first call INSERTs
	 * the row and marks the log persisted; subsequent calls are no-ops so the
	 * append-only schema is never asked to UPDATE.
	 *
	 * @return void
	 */
	public function testSynchronizationLogIsWrittenExactlyOnce(): void {
		$defaultEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['result' => []],
			'log-uuid'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		// saveObject must be invoked exactly once across multiple update() calls.
		$orObjectService->expects($this->once())->method('saveObject')->willReturn($defaultEntity);

		$userSession = $this->createMock(\OCP\IUserSession::class);
		$session = $this->createMock(\OCP\ISession::class);
		$logService = new SynchronizationLogService($orObjectService, $userSession, $session);

		$log = $logService->createFromArray(['synchronizationId' => 'sync-1', 'result' => []]);
		$this->assertFalse($log->isPersisted(), 'createFromArray must NOT persist the row');

		$logService->update($log);
		$this->assertTrue($log->isPersisted(), 'the first update must persist the row');

		// Second/third calls must not write again (append-only).
		$logService->update($log);
		$logService->persist($log);
	}//end testSynchronizationLogIsWrittenExactlyOnce()

	/**
	 * synchronize() throws when an invalid mutationType is passed.
	 *
	 * @return void
	 */
	public function testSynchronizeThrowsOnInvalidMutationType(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/Invalid mutation type/');

		$this->service->synchronize(
			synchronization: ['uuid' => 'sync-1'],
			mutationType: 'not-a-real-type'
		);
	}//end testSynchronizeThrowsOnInvalidMutationType()

	/**
	 * handleObjectEventSynchronization() short-circuits when the
	 * ObjectEntity has no register/schema (no findAll, no synchronize).
	 *
	 * @return void
	 */
	public function testHandleObjectEventSkipsWhenObjectHasNoRegister(): void {
		$objectEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[],
			'obj-1'
		);
		// Leave register/schema unset.
		$this->orObjectService->expects($this->never())->method('findAll');

		$this->service->handleObjectEventSynchronization($objectEntity, 'create');
		$this->addToAssertionCount(1);
	}//end testHandleObjectEventSkipsWhenObjectHasNoRegister()

	/**
	 * handleObjectEventSynchronization() queries OR for direct syncs on the
	 * object's register/schema when the mutation type is valid.
	 *
	 * @return void
	 */
	public function testHandleObjectEventQueriesOrForDirectSyncs(): void {
		$objectEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[],
			'obj-1'
		);
		$objectEntity->setRegister('openconnector');
		$objectEntity->setSchema('source');

		// OR findAll is invoked at least once on the direct-sync lookup. The
		// related-trigger lookup may also hit findAll. We assert at least one
		// call instead of exact count to keep the test resilient.
		$this->orObjectService->expects($this->atLeastOnce())
			->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		$this->service->handleObjectEventSynchronization($objectEntity, 'create');
		$this->addToAssertionCount(1);
	}//end testHandleObjectEventQueriesOrForDirectSyncs()

	/**
	 * encodeArrayKeys() rewrites flat keys.
	 *
	 * @return void
	 */
	public function testEncodeArrayKeysReplacesFlatKeys(): void {
		$input = ['a.b' => 1, 'c.d' => 2];

		$result = $this->service->encodeArrayKeys($input, '.', '|');

		$this->assertSame(['a|b' => 1, 'c|d' => 2], $result);
	}//end testEncodeArrayKeysReplacesFlatKeys()

	/**
	 * encodeArrayKeys() recurses into nested arrays and rewrites their keys
	 * too.
	 *
	 * @return void
	 */
	public function testEncodeArrayKeysRecursesIntoNestedArrays(): void {
		$input = ['a.b' => ['c.d' => 1, 'e.f' => ['g.h' => 2]]];

		$result = $this->service->encodeArrayKeys($input, '.', '|');

		$this->assertSame(
			['a|b' => ['c|d' => 1, 'e|f' => ['g|h' => 2]]],
			$result
		);
	}//end testEncodeArrayKeysRecursesIntoNestedArrays()

	/**
	 * encodeArrayKeys() returns an empty array unchanged.
	 *
	 * @return void
	 */
	public function testEncodeArrayKeysReturnsEmptyArrayUnchanged(): void {
		$this->assertSame([], $this->service->encodeArrayKeys([], '.', '|'));
	}//end testEncodeArrayKeysReturnsEmptyArrayUnchanged()

	/**
	 * encodeArrayKeys() leaves an inner empty array untouched (not recursed
	 * into).
	 *
	 * @return void
	 */
	public function testEncodeArrayKeysLeavesEmptyInnerArrayUntouched(): void {
		$input = ['a.b' => []];

		$result = $this->service->encodeArrayKeys($input, '.', '|');

		$this->assertSame(['a|b' => []], $result);
	}//end testEncodeArrayKeysLeavesEmptyInnerArrayUntouched()

	/**
	 * sortNestedArray() returns false when given a non-array value.
	 *
	 * @return void
	 */
	public function testSortNestedArrayReturnsFalseForNonArray(): void {
		$value = 'not-an-array';
		$this->assertFalse($this->service->sortNestedArray($value));
	}//end testSortNestedArrayReturnsFalseForNonArray()

	/**
	 * sortNestedArray() sorts nested associative arrays too.
	 *
	 * @return void
	 */
	public function testSortNestedArraySortsNestedAssociativeArrays(): void {
		$array = ['b' => 2, 'a' => ['z' => 26, 'x' => 24], 'c' => 3];

		$this->assertTrue($this->service->sortNestedArray($array));
		$this->assertSame(['a', 'b', 'c'], array_keys($array));
		$this->assertSame(['x', 'z'], array_keys($array['a']));
	}//end testSortNestedArraySortsNestedAssociativeArrays()

	/**
	 * replaceRelatedOriginIds() leaves keys absent in the input untouched
	 * (no spurious additions).
	 *
	 * @return void
	 */
	public function testReplaceRelatedOriginIdsSkipsMissingKeys(): void {
		$object = ['kept' => 'as-is'];
		$config = ['absent-key' => 'true'];

		$result = $this->service->replaceRelatedOriginIds($object, $config);

		$this->assertSame(['kept' => 'as-is'], $result);
	}//end testReplaceRelatedOriginIdsSkipsMissingKeys()

	/**
	 * replaceRelatedOriginIds() leaves a non-UUID leaf string unchanged
	 * (replaceIdInString preserves values it can't resolve).
	 *
	 * @return void
	 */
	public function testReplaceRelatedOriginIdsLeavesNonUuidLeafUnchanged(): void {
		$object = ['relation' => 'not-a-uuid-value'];
		$config = ['relation' => 'true'];

		// findAll returns empty so the contract lookup yields null and the
		// leaf is returned unchanged.
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->replaceRelatedOriginIds($object, $config);

		$this->assertSame('not-a-uuid-value', $result['relation']);
	}//end testReplaceRelatedOriginIdsLeavesNonUuidLeafUnchanged()

	/**
	 * replaceRelatedOriginIds() recurses into a single nested associative
	 * subobject (config + object both shaped as assoc arrays).
	 *
	 * @return void
	 */
	public function testReplaceRelatedOriginIdsRecursesIntoNestedAssociativeObject(): void {
		$object = ['sub' => ['relation' => 'not-a-uuid-value']];
		$config = ['sub' => ['relation' => 'true']];

		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->replaceRelatedOriginIds($object, $config);

		$this->assertSame('not-a-uuid-value', $result['sub']['relation']);
	}//end testReplaceRelatedOriginIdsRecursesIntoNestedAssociativeObject()

	/**
	 * findAllBySourceId() composes the sourceId from register+schema.
	 *
	 * @return void
	 */
	public function testFindAllBySourceIdComposesSourceFilter(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['sourceId' => 'reg-1/schema-1'],
			'sync-1'
		);

		$this->orObjectService->expects($this->once())
			->method('findAll')
			->with(
				$this->callback(
					function (array $config): bool {
						$filters = ($config['filters'] ?? []);
						return ($filters['sourceId'] ?? null) === 'reg-1/schema-1'
						&& ($filters['register'] ?? null) === 'openconnector'
						&& ($filters['schema'] ?? null) === 'synchronization';
					}
				)
			)
			->willReturn(['results' => [$entity], 'total' => 1]);

		$result = $this->service->findAllBySourceId('reg-1', 'schema-1');

		$this->assertCount(1, $result);
	}//end testFindAllBySourceIdComposesSourceFilter()

	/**
	 * findAllBySourceId() returns an empty array when no syncs match.
	 *
	 * @return void
	 */
	public function testFindAllBySourceIdReturnsEmptyArrayWhenNoMatch(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->assertSame([], $this->service->findAllBySourceId('reg-x', 'schema-x'));
	}//end testFindAllBySourceIdReturnsEmptyArrayWhenNoMatch()

	/**
	 * getSynchronization() proxies to OR find() against the synchronization
	 * schema.
	 *
	 * @return void
	 */
	public function testGetSynchronizationDelegatesToFind(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'my-sync'],
			'sync-uuid-3'
		);
		$this->orObjectService->method('find')->willReturn($entity);

		$result = $this->service->getSynchronization('sync-uuid-3');

		$this->assertSame($entity, $result);
	}//end testGetSynchronizationDelegatesToFind()

	/**
	 * Stub `orObjectService::find()` to return a `source` entity carrying the
	 * given body, and `callService::applyConfigDot()` to pass its argument
	 * through unchanged (matching the real Adbar\Dot behaviour for configs
	 * with no dotted keys, which every fixture below uses).
	 *
	 * @param array $sourceBody The source object body (location/configuration/etc).
	 *
	 * @return void
	 */
	private function stubSource(array $sourceBody): void {
		$entity = ObjectServiceMockBuilder::objectEntity($this, $sourceBody, ($sourceBody['uuid'] ?? 'source-uuid'));
		$this->orObjectService->method('find')->willReturn($entity);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);
	}//end stubSource()

	/**
	 * Stub `callService::call()` to return one canned call-log response for
	 * every invocation (single-page fixtures).
	 *
	 * @param array $response The `response` sub-array (statusCode/body/encoding/headers).
	 *
	 * @return void
	 */
	private function stubSingleCallResponse(array $response): void {
		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['response' => $response], 'call-log-1');
		$this->callService->method('call')->willReturn($callLog);
	}//end stubSingleCallResponse()

	/**
	 * oc#97 — a `.jsonl.gz` bulk source (the OpenTender/OCP registry shape:
	 * Slovenia pub-93, Romania pub-75, Croatia pub-80) is gunzipped and each
	 * JSONL line decoded as one record. Includes a blank line to prove line
	 * skipping does not lose subsequent records. The gzip bytes are
	 * base64-encoded exactly as CallService encodes any non-UTF8 response
	 * body (real gzip binary always fails `mb_check_encoding`).
	 *
	 * @return void
	 */
	public function testGzipJsonlBulkSourceDecodesEachLineAsARecord(): void {
		$this->stubSource(
			[
				'uuid' => 'source-uuid-gz',
				'location' => 'https://data.open-contracting.org',
				'configuration' => [],
			]
		);

		$jsonl = implode(
			"\n",
			[
				json_encode(['ocid' => 'ocds-1', 'tender' => ['title' => 'Tender A']]),
				'',
				json_encode(['ocid' => 'ocds-2', 'tender' => ['title' => 'Tender B']]),
			]
		);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => base64_encode((string)gzencode($jsonl)),
				'encoding' => 'base64',
				'headers' => ['Content-Type' => ['application/gzip']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-gz',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/download?name=full.jsonl.gz',
					'resultsPosition' => '_root',
					'format' => 'jsonl',
					'usesPagination' => false,
				],
			]
		);

		$this->assertCount(2, $objects);
		$this->assertSame('ocds-1', $objects[0]['ocid']);
		$this->assertSame('Tender B', $objects[1]['tender']['title']);
	}//end testGzipJsonlBulkSourceDecodesEachLineAsARecord()

	/**
	 * oc#97 — `format: "jsonl"` also works standalone (no compression), e.g.
	 * an already-decompressed bulk export or an ETL pre-processing step.
	 *
	 * @return void
	 */
	public function testPlainJsonlSourceWithoutGzipStillParsesLines(): void {
		$this->stubSource(['uuid' => 'source-uuid-plain-jsonl', 'location' => 'https://example.test']);

		$jsonl = implode(
			"\n",
			[
				json_encode(['id' => 1]),
				json_encode(['id' => 2]),
				json_encode(['id' => 3]),
			]
		);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => $jsonl,
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['application/x-ndjson']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-plain-jsonl',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/export.jsonl',
					'resultsPosition' => '_root',
					'format' => 'jsonl',
					'usesPagination' => false,
				],
			]
		);

		$this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $objects);
	}//end testPlainJsonlSourceWithoutGzipStillParsesLines()

	/**
	 * oc#97 — gzip detection also works for an ordinary (non-JSONL) JSON
	 * body via the response `Content-Type: application/gzip` header alone
	 * (no `.gz`-suffixed endpoint, no `configuration.decompress` hint).
	 *
	 * @return void
	 */
	public function testGzipDetectionViaContentTypeHeaderDecompressesOrdinaryJson(): void {
		$this->stubSource(['uuid' => 'source-uuid-gz-ct', 'location' => 'https://example.test']);

		$json = json_encode(['items' => [['id' => 1], ['id' => 2]]]);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => base64_encode((string)gzencode((string)$json)),
				'encoding' => 'base64',
				'headers' => ['content-type' => ['application/gzip']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-gz-ct',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/export',
					'resultsPosition' => 'items',
					'usesPagination' => false,
				],
			]
		);

		$this->assertCount(2, $objects);
		$this->assertSame(1, $objects[0]['id']);
	}//end testGzipDetectionViaContentTypeHeaderDecompressesOrdinaryJson()

	/**
	 * oc#97 — `Source.configuration.decompress: "gzip"` is an explicit hint
	 * that works even when neither the endpoint nor the response
	 * Content-Type gives it away.
	 *
	 * @return void
	 */
	public function testDecompressConfigHintTriggersGzipDecompression(): void {
		$this->stubSource(
			[
				'uuid' => 'source-uuid-hint',
				'location' => 'https://example.test',
				'configuration' => ['decompress' => 'gzip'],
			]
		);

		$json = json_encode(['items' => [['id' => 7]]]);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => base64_encode((string)gzencode((string)$json)),
				'encoding' => 'base64',
				'headers' => ['Content-Type' => ['application/octet-stream']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-hint',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/export',
					'resultsPosition' => 'items',
					'usesPagination' => false,
				],
			]
		);

		$this->assertSame([['id' => 7]], $objects);
	}//end testDecompressConfigHintTriggersGzipDecompression()

	/**
	 * oc#97 — `.tar.gz` bulk archives are explicitly deferred (gzip alone
	 * cannot unpack a tar archive): the fetch short-circuits to zero objects
	 * with a logged warning, rather than the pre-existing silent-empty
	 * failure mode.
	 *
	 * @return void
	 */
	public function testTarGzEndpointShortCircuitsWithWarningAndNoObjects(): void {
		$this->stubSource(['uuid' => 'source-uuid-targz', 'location' => 'https://example.test']);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => base64_encode('not-actually-parsed'),
				'encoding' => 'base64',
				'headers' => ['Content-Type' => ['application/gzip']],
			]
		);

		$this->logger->expects($this->once())->method('warning');

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-targz',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/download?name=archive.tar.gz',
					'resultsPosition' => '_root',
					'usesPagination' => false,
				],
			]
		);

		$this->assertSame([], $objects);
	}//end testTarGzEndpointShortCircuitsWithWarningAndNoObjects()

	/**
	 * Regression — a plain JSON source with none of the new oc#97 config
	 * keys (`format`, `decompress`) and no gzip signal at all behaves exactly
	 * as before: the gzip/JSONL branches are never entered.
	 *
	 * @return void
	 */
	public function testExistingJsonSourceWithoutNewConfigKeysIsUnaffected(): void {
		$this->stubSource(['uuid' => 'source-uuid-plain-json', 'location' => 'https://example.test']);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => json_encode(['items' => [['id' => 1], ['id' => 2]]]),
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['application/json']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-plain-json',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/items',
					'resultsPosition' => 'items',
					'usesPagination' => false,
				],
			]
		);

		$this->assertSame([['id' => 1], ['id' => 2]], $objects);
	}//end testExistingJsonSourceWithoutNewConfigKeysIsUnaffected()

	/**
	 * Regression — the pre-existing XML fallback (simplexml, added
	 * 2026-06-20) is unaffected by the gzip/JSONL additions: a plain XML
	 * body with no gzip signal parses exactly as before.
	 *
	 * @return void
	 */
	public function testExistingXmlFallbackIsUnaffected(): void {
		$this->stubSource(['uuid' => 'source-uuid-xml', 'location' => 'https://example.test']);
		$xml = '<root><items><item><id>1</id><name>Alpha</name></item></items></root>';
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => $xml,
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['application/xml']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-xml',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/items.xml',
					'resultsPosition' => 'items.item',
					'usesPagination' => false,
				],
			]
		);

		$this->assertCount(1, $objects);
		// xmlToArray() represents a leaf text node as ['#text' => value] —
		// pre-existing shape, unrelated to this change, asserted here only to
		// prove the XML fallback path itself still runs unaffected.
		$this->assertSame('1', $objects[0]['id']['#text']);
		$this->assertSame('Alpha', $objects[0]['name']['#text']);
	}//end testExistingXmlFallbackIsUnaffected()

	/**
	 * oc#107 — a `Source.configuration.format: "markdown"` source (the
	 * awesome_selfhosted README shape) is parsed into one record per
	 * `- [Name](url) - description \`Tag\`` list item. Fixture includes: an
	 * item with two tags (license + language), an item with one tag, an
	 * item with zero tags, a heading line, a blank line, and a non-matching
	 * plain-text list item — all five non-record lines MUST be skipped
	 * without throwing, leaving exactly three records.
	 *
	 * @return void
	 */
	public function testMarkdownSourceParsesAwesomeListItemsIntoRecords(): void {
		$this->stubSource(
			[
				'uuid' => 'source-uuid-md',
				'location' => 'https://raw.githubusercontent.com/awesome-selfhosted/awesome-selfhosted-data/master/README.md',
				'configuration' => ['format' => 'markdown'],
			]
		);

		$markdown = implode(
			"\n",
			[
				'# Awesome-Selfhosted',
				'',
				'## Automation',
				'- [n8n](https://n8n.io/) - Workflow automation tool. `Sustainable use license` `TypeScript`',
				'- [Huginn](https://github.com/huginn/huginn) - Build agents that monitor things. `MIT`',
				'- Just a plain bullet with no link, describing something.',
				'- [Cronicle](https://github.com/jhuckaby/Cronicle) - Task scheduler with no tags at all',
			]
		);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => $markdown,
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['text/markdown']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-md',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/README.md',
					'resultsPosition' => '_root',
					'usesPagination' => false,
				],
			]
		);

		$this->assertCount(3, $objects);

		$this->assertSame('n8n', $objects[0]['name']);
		$this->assertSame('https://n8n.io/', $objects[0]['url']);
		$this->assertSame('Workflow automation tool.', $objects[0]['description']);
		$this->assertSame(['Sustainable use license', 'TypeScript'], $objects[0]['tags']);

		$this->assertSame('Huginn', $objects[1]['name']);
		$this->assertSame(['MIT'], $objects[1]['tags']);

		$this->assertSame('Cronicle', $objects[2]['name']);
		$this->assertSame('Task scheduler with no tags at all', $objects[2]['description']);
		$this->assertSame([], $objects[2]['tags']);
	}//end testMarkdownSourceParsesAwesomeListItemsIntoRecords()

	/**
	 * oc#107 follow-up — live verification against the real awesome-selfhosted
	 * README revealed the parser also matched table-of-contents / "back to
	 * top" navigation links, whose targets are in-document anchors
	 * (`#software`) rather than absolute URLs. Those bogus records fail the
	 * target schema's `url` format:uri validation and abort the whole sync.
	 * Fixture mixes one real absolute-URL entry with a TOC anchor item and a
	 * "back to top" anchor item — only the absolute-URL entry must survive.
	 *
	 * @return void
	 */
	public function testMarkdownSourceSkipsAnchorAndRelativeUrlListItems(): void {
		$this->stubSource(
			[
				'uuid' => 'source-uuid-md-anchor',
				'location' => 'https://raw.githubusercontent.com/awesome-selfhosted/awesome-selfhosted-data/master/README.md',
				'configuration' => ['format' => 'markdown'],
			]
		);

		$markdown = implode(
			"\n",
			[
				'# Awesome-Selfhosted',
				'',
				'## Table of Contents',
				'- [Software](#software)',
				'',
				'## Automation',
				'- [Cool Tool](https://example.com/cool-tool) - A cool self-hosted tool.',
				'- [↥ back to top](#awesome-selfhosted)',
			]
		);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => $markdown,
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['text/markdown']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-md-anchor',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/README.md',
					'resultsPosition' => '_root',
					'usesPagination' => false,
				],
			]
		);

		$this->assertCount(1, $objects);
		$this->assertSame('Cool Tool', $objects[0]['name']);
		$this->assertSame('https://example.com/cool-tool', $objects[0]['url']);
		$this->assertSame('A cool self-hosted tool.', $objects[0]['description']);
	}//end testMarkdownSourceSkipsAnchorAndRelativeUrlListItems()

	/**
	 * oc#107 — a `Source.configuration.format: "html"` source (openalternative/
	 * don_oss_register-shaped: a plain HTML table with no API) extracts one
	 * record per `htmlSelector`-matched row, with `htmlFields` sub-selectors
	 * pulling text content and — via the `selector@attr` syntax — the `href`
	 * attribute off the name column's anchor.
	 *
	 * @return void
	 */
	public function testHtmlSourceExtractsTableRowsViaCssSelectors(): void {
		$this->stubSource(
			[
				'uuid' => 'source-uuid-html',
				'location' => 'https://www.opensourcealternative.to/',
				'configuration' => [
					'format' => 'html',
					'htmlSelector' => 'table tbody tr',
					'htmlFields' => [
						'name' => 'td.name a',
						'url' => 'td.name a@href',
						'description' => 'td.description',
					],
				],
			]
		);

		$html = <<<HTML
<html><body>
<table>
  <tbody>
    <tr>
      <td class="name"><a href="https://example.test/tools/alpha">Alpha</a></td>
      <td class="description">An alternative to Acme</td>
    </tr>
    <tr>
      <td class="name"><a href="https://example.test/tools/beta">Beta</a></td>
      <td class="description">Another alternative</td>
    </tr>
  </tbody>
</table>
</body></html>
HTML;

		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => $html,
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['text/html']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-html',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/',
					'resultsPosition' => '_root',
					'usesPagination' => false,
				],
			]
		);

		$this->assertCount(2, $objects);
		$this->assertSame('Alpha', $objects[0]['name']);
		$this->assertSame('https://example.test/tools/alpha', $objects[0]['url']);
		$this->assertSame('An alternative to Acme', $objects[0]['description']);
		$this->assertSame('Beta', $objects[1]['name']);
		$this->assertSame('https://example.test/tools/beta', $objects[1]['url']);
	}//end testHtmlSourceExtractsTableRowsViaCssSelectors()

	/**
	 * Regression — a source with neither `Source.configuration.format:
	 * "markdown"` nor `"html"` (and no other new oc#107 config key) is
	 * unaffected: the markdown/html branches are never entered, and an
	 * ordinary JSON body still parses through the pre-existing
	 * `resultsPosition` extraction exactly as before.
	 *
	 * @return void
	 */
	public function testExistingJsonSourceWithoutMarkdownOrHtmlFormatIsUnaffected(): void {
		$this->stubSource(
			[
				'uuid' => 'source-uuid-plain-json-2',
				'location' => 'https://example.test',
				'configuration' => [],
			]
		);
		$this->stubSingleCallResponse(
			[
				'statusCode' => 200,
				'body' => json_encode(['items' => [['id' => 1], ['id' => 2]]]),
				'encoding' => 'UTF-8',
				'headers' => ['Content-Type' => ['application/json']],
			]
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-plain-json-2',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/items',
					'resultsPosition' => 'items',
					'usesPagination' => false,
				],
			]
		);

		$this->assertSame([['id' => 1], ['id' => 2]], $objects);
	}//end testExistingJsonSourceWithoutMarkdownOrHtmlFormatIsUnaffected()

	/**
	 * oc#94 — a synchronization whose `sourceConfig.paginationIn` is `"body"`
	 * threads that directive (plus the incrementing page number) into
	 * `CallService::call()`'s `config.pagination` for every page after the
	 * first, via `getNextPage()`. `CallService::normaliseRequestConfig()`
	 * itself (which performs the actual JSON-body substitution) is exercised
	 * separately in `CallServiceTest`; this proves the synchronization engine
	 * correctly derives and forwards the directive across a real multi-page
	 * loop.
	 *
	 * @return void
	 */
	public function testBodyPaginationDirectiveThreadsAcrossPages(): void {
		$this->stubSource(['uuid' => 'source-uuid-ted', 'location' => 'https://api.ted.europa.eu']);

		$captured = [];
		$this->callService->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = [], ...$rest) use (&$captured) {
				$captured[] = $config;
				$page = count($captured);

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					[
						'response' => [
							'statusCode' => 200,
							'body' => json_encode(['notices' => [['publication-number' => (string)$page]]]),
							'encoding' => 'UTF-8',
							'headers' => [],
						],
					],
					'call-log-' . $page
				);
			}
		);

		$objects = $this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-ted',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/search',
					'resultsPosition' => 'notices',
					'usesPagination' => true,
					'maxPages' => 3,
					'paginationQuery' => 'page',
					'paginationIn' => 'body',
				],
			]
		);

		$this->assertCount(3, $captured);
		$this->assertArrayNotHasKey('pagination', $captured[0], 'page 1 carries no pagination directive yet');
		$this->assertSame('body', $captured[1]['pagination']['paginationIn']);
		$this->assertSame(2, $captured[1]['pagination']['page']);
		$this->assertSame('body', $captured[2]['pagination']['paginationIn']);
		$this->assertSame(3, $captured[2]['pagination']['page']);
		$this->assertCount(3, $objects);
	}//end testBodyPaginationDirectiveThreadsAcrossPages()

	/**
	 * Regression — omitting `paginationIn` keeps threading the pre-existing
	 * default (`"query"`), byte-for-byte, for every synchronization that
	 * doesn't opt in to body-based pagination.
	 *
	 * @return void
	 */
	public function testDefaultPaginationInIsQueryWhenOmitted(): void {
		$this->stubSource(['uuid' => 'source-uuid-query-pg', 'location' => 'https://example.test']);

		$captured = [];
		$this->callService->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET', array $config = [], ...$rest) use (&$captured) {
				$captured[] = $config;
				$page = count($captured);

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					[
						'response' => [
							'statusCode' => 200,
							'body' => json_encode(['items' => [['id' => $page]]]),
							'encoding' => 'UTF-8',
							'headers' => [],
						],
					],
					'call-log-q-' . $page
				);
			}
		);

		$this->service->getAllObjectsFromApi(
			synchronization: [
				'sourceId' => 'source-uuid-query-pg',
				'sourceType' => 'api',
				'sourceConfig' => [
					'endpoint' => '/items',
					'resultsPosition' => 'items',
					'usesPagination' => true,
					'maxPages' => 2,
				],
			]
		);

		$this->assertCount(2, $captured);
		$this->assertSame('query', $captured[1]['pagination']['paginationIn']);
	}//end testDefaultPaginationInIsQueryWhenOmitted()

	/**
	 * Route `orObjectService::find()` to a synchronization payload when the
	 * schema is `synchronization` and a source payload when the schema is
	 * `source` — needed because `stubSource()` alone would make EVERY
	 * find() call resolve to the source, which breaks `updateTarget()`'s own
	 * internal `findSynchronization()` lookup.
	 *
	 * @param array $syncBody The synchronization payload to return.
	 * @param array $sourceBody The source payload to return.
	 *
	 * @return void
	 */
	private function stubSynchronizationAndSource(array $syncBody, array $sourceBody): void {
		$syncEntity = ObjectServiceMockBuilder::objectEntity($this, $syncBody, ($syncBody['uuid'] ?? 'sync-uuid'));
		$sourceEntity = ObjectServiceMockBuilder::objectEntity($this, $sourceBody, ($sourceBody['uuid'] ?? 'source-uuid'));

		$this->orObjectService->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true) use ($syncEntity, $sourceEntity) {
				if ($schema === 'synchronization') {
					return $syncEntity;
				}

				return $sourceEntity;
			}
		);

	}//end stubSynchronizationAndSource()

	/**
	 * `getAllObjectsFromSource()` dispatches `sourceType: nextcloud-table` to
	 * the Tables adapter and returns its rows unchanged (synchronization-engine
	 * REQ-014; tables-bridge REQ-002).
	 *
	 * @return void
	 */
	public function testGetAllObjectsFromSourceDispatchesNextcloudTable(): void {
		$this->stubSource(['uuid' => 'source-uuid-nt-1', 'location' => 'https://nc.example.test']);

		$rows = [['id' => '1', '7' => '10'], ['id' => '2', '7' => '20']];
		$this->tablesSyncAdapter->expects($this->once())->method('assertEnabled');
		$this->tablesSyncAdapter->expects($this->once())->method('fetchAllRows')
			->with($this->anything(), 42, null)
			->willReturn($rows);

		$result = $this->service->getAllObjectsFromSource(
			synchronization: [
				'sourceId' => 'source-uuid-nt-1',
				'sourceType' => 'nextcloud-table',
				'sourceConfig' => ['tableId' => 42],
			]
		);

		$this->assertSame($rows, $result);

	}//end testGetAllObjectsFromSourceDispatchesNextcloudTable()

	/**
	 * A `nextcloud-table` source with no `sourceConfig.tableId` fails loudly
	 * instead of silently returning an empty set.
	 *
	 * @return void
	 */
	public function testGetAllObjectsFromSourceNextcloudTableMissingTableIdThrows(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessageMatches('/tableId/');

		$this->service->getAllObjectsFromSource(
			synchronization: [
				'sourceId' => 'source-uuid-nt-2',
				'sourceType' => 'nextcloud-table',
				'sourceConfig' => [],
			]
		);

	}//end testGetAllObjectsFromSourceNextcloudTableMissingTableIdThrows()

	/**
	 * `updateTarget()` for `targetType: nextcloud-table` with no existing
	 * contract `targetId` creates a row and records the returned row id as
	 * the contract's `targetId` (tables-bridge REQ-001).
	 *
	 * @return void
	 */
	public function testUpdateTargetNextcloudTableCreatesRowAndSetsTargetId(): void {
		$this->stubSynchronizationAndSource(
			syncBody: [
				'uuid' => 'sync-uuid-nt-1',
				'targetId' => 'target-source-uuid-1',
				'targetType' => 'nextcloud-table',
				'targetConfig' => ['tableId' => 42, 'columnMapping' => [['column' => 'Amount', 'value' => 'total']]],
			],
			sourceBody: ['uuid' => 'target-source-uuid-1', 'location' => 'https://nc.example.test']
		);

		$this->tablesSyncAdapter->expects($this->once())->method('assertEnabled');
		$this->tablesSyncAdapter->expects($this->once())->method('writeRow')
			->with($this->anything(), 42, null, ['total' => 19.99], [['column' => 'Amount', 'value' => 'total']])
			->willReturn(['id' => '100']);

		$targetObject = ['total' => 19.99];
		$contract = $this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-uuid-nt-1', 'originId' => 'origin-1'],
			targetObject: $targetObject
		);

		$this->assertSame('100', $contract['targetId']);
		$this->assertNotNull($contract['targetHash']);

	}//end testUpdateTargetNextcloudTableCreatesRowAndSetsTargetId()

	/**
	 * `updateTarget()` for `targetType: nextcloud-table` with an existing
	 * `targetId` routes through an update (not a create) — the adapter
	 * receives the existing row id.
	 *
	 * @return void
	 */
	public function testUpdateTargetNextcloudTableUpdatesExistingRow(): void {
		$this->stubSynchronizationAndSource(
			syncBody: [
				'uuid' => 'sync-uuid-nt-2',
				'targetId' => 'target-source-uuid-2',
				'targetType' => 'nextcloud-table',
				'targetConfig' => ['tableId' => 42, 'columnMapping' => []],
			],
			sourceBody: ['uuid' => 'target-source-uuid-2', 'location' => 'https://nc.example.test']
		);

		$this->tablesSyncAdapter->method('assertEnabled');
		$this->tablesSyncAdapter->expects($this->once())->method('writeRow')
			->with($this->anything(), 42, '100', $this->anything(), $this->anything())
			->willReturn(['id' => '100']);

		$targetObject = ['total' => 25];
		$contract = $this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-uuid-nt-2', 'originId' => 'origin-1', 'targetId' => '100'],
			targetObject: $targetObject
		);

		$this->assertSame('100', $contract['targetId']);

	}//end testUpdateTargetNextcloudTableUpdatesExistingRow()

	/**
	 * A per-row skip signalled by the adapter (`null` return — ambiguous
	 * title or coercion failure) leaves the contract's `targetId` untouched
	 * rather than throwing (the row is retried on the next run; the overall
	 * run continues per REQ-001/REQ-003).
	 *
	 * @return void
	 */
	public function testUpdateTargetNextcloudTableAdapterSkipLeavesContractUnchanged(): void {
		$this->stubSynchronizationAndSource(
			syncBody: [
				'uuid' => 'sync-uuid-nt-3',
				'targetId' => 'target-source-uuid-3',
				'targetType' => 'nextcloud-table',
				'targetConfig' => ['tableId' => 42, 'columnMapping' => []],
			],
			sourceBody: ['uuid' => 'target-source-uuid-3', 'location' => 'https://nc.example.test']
		);

		$this->tablesSyncAdapter->method('assertEnabled');
		$this->tablesSyncAdapter->method('writeRow')->willReturn(null);

		$targetObject = ['total' => 25];
		$contract = $this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-uuid-nt-3', 'originId' => 'origin-1'],
			targetObject: $targetObject
		);

		$this->assertArrayNotHasKey('targetId', $contract);

	}//end testUpdateTargetNextcloudTableAdapterSkipLeavesContractUnchanged()

	/**
	 * `updateTarget()` for a `nextcloud-table` target with `action: delete`
	 * calls the adapter's `deleteRow()` and clears the contract's `targetId`.
	 *
	 * @return void
	 */
	public function testUpdateTargetNextcloudTableDeleteCallsAdapterDeleteRow(): void {
		$this->stubSynchronizationAndSource(
			syncBody: [
				'uuid' => 'sync-uuid-nt-4',
				'targetId' => 'target-source-uuid-4',
				'targetType' => 'nextcloud-table',
				'targetConfig' => ['tableId' => 42],
			],
			sourceBody: ['uuid' => 'target-source-uuid-4', 'location' => 'https://nc.example.test']
		);

		$this->tablesSyncAdapter->method('assertEnabled');
		$this->tablesSyncAdapter->expects($this->once())->method('deleteRow')
			->with($this->anything(), '100')
			->willReturn(true);

		$contract = $this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-uuid-nt-4', 'originId' => 'origin-1', 'targetId' => '100'],
			action: 'delete'
		);

		$this->assertNull($contract['targetId']);

	}//end testUpdateTargetNextcloudTableDeleteCallsAdapterDeleteRow()

	/**
	 * `updateTarget()` still throws `Unsupported target type` for a type that
	 * is neither a recognised legacy type nor `nextcloud-table` — regression
	 * guard for synchronization-engine REQ-014's "unrecognised type still
	 * throws" scenario.
	 *
	 * @return void
	 */
	public function testUpdateTargetUnrecognisedTypeStillThrows(): void {
		$this->stubSynchronizationAndSource(
			syncBody: ['uuid' => 'sync-uuid-nt-5', 'targetType' => 'some-future-type'],
			sourceBody: []
		);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Unsupported target type: some-future-type');

		$this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-uuid-nt-5', 'originId' => 'origin-1']
		);

	}//end testUpdateTargetUnrecognisedTypeStillThrows()

	/**
	 * `deleteInvalidObjects()` for a `nextcloud-table` target deletes rows
	 * whose contracts are absent from the fetched set, via the SAME
	 * diff-and-delete mechanism as `register/schema` (tables-bridge REQ-005 —
	 * composes with, does not duplicate, the shared deletion-safety guard).
	 *
	 * @return void
	 */
	public function testDeleteInvalidObjectsNextcloudTableDeletesMissingContracts(): void {
		$this->stubSynchronizationAndSource(
			syncBody: [
				'id' => 'sync-id-nt-6',
				'uuid' => 'sync-uuid-nt-6',
				'targetId' => 'target-source-uuid-6',
				'targetType' => 'nextcloud-table',
				'targetConfig' => ['tableId' => 42],
			],
			sourceBody: ['uuid' => 'target-source-uuid-6', 'location' => 'https://nc.example.test']
		);

		$keptContract = ObjectServiceMockBuilder::objectEntity($this, ['synchronizationId' => 'sync-id-nt-6', 'originId' => 'origin-kept', 'targetId' => '1'], 'contract-kept');
		$orphanContract = ObjectServiceMockBuilder::objectEntity($this, ['synchronizationId' => 'sync-id-nt-6', 'originId' => 'origin-orphan', 'targetId' => '2'], 'contract-orphan');

		$this->orObjectService->method('findAll')->willReturn(['results' => [$keptContract, $orphanContract]]);
		$this->orObjectService->method('saveObject')->willReturnCallback(
			fn (array $object, string $register, string $schema, ?string $uuid = null) => ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'saved-contract')
		);

		$this->tablesSyncAdapter->method('assertEnabled');
		$this->tablesSyncAdapter->expects($this->once())->method('deleteRow')
			->with($this->anything(), '2')
			->willReturn(true);

		$deletedCount = $this->service->deleteInvalidObjects(
			synchronization: ['id' => 'sync-id-nt-6', 'targetType' => 'nextcloud-table'],
			synchronizedTargetIds: ['1']
		);

		$this->assertSame(1, $deletedCount);

	}//end testDeleteInvalidObjectsNextcloudTableDeletesMissingContracts()

	/**
	 * stream-file-content #110: a raw binary download — no `contentPath`/`filenamePath`
	 * addressing a JSON envelope — selects the streaming transport. `fetchFile` opens a
	 * disk-backed `php://temp` handle and hands it to `CallService::call()` as its `sink`,
	 * so the body is never buffered into a PHP string.
	 *
	 * Driven through the `write === false` dry-run return, which yields the streamed bytes
	 * straight from the temp handle and so needs no FileService/ObjectService container.
	 * The handle is asserted as a boolean captured at call time, not kept: `fetchFile`
	 * closes it in its `finally`, after which `is_resource()` reports false.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stream-file-content/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering
	 */
	public function testFetchFileStreamsRawBinaryDownloadIntoASinkResource(): void {
		$bytes = 'binary-file-bytes-that-are-not-a-json-envelope';
		$sinkPath = null;
		$sinkWasPath = false;

		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$sinkPath, &$sinkWasPath, $bytes) {
				// The sink is handed over as a FILE PATH, never as our own handle:
				// Guzzle closes a resource-typed sink when its PSR-7 wrapper is
				// destructed, which previously handed a closed handle back to the
				// write side. Emulate Guzzle by writing to the path we are given.
				foreach ($args as $arg) {
					if (is_string($arg) === true && $arg !== '' && is_file($arg) === true) {
						$sinkWasPath = true;
						$sinkPath = $arg;
						file_put_contents($arg, $bytes);
					}
				}

				$callLog = new \OCA\OpenRegister\Db\ObjectEntity();
				$callLog->setObject(['response' => ['body' => '', 'headers' => []]]);

				return $callLog;
			}
		);

		$filename = null;
		$args = [
			['_transient' => true, 'uuid' => 'source-uuid', 'location' => ''],
			'/attachment/1',
			['write' => false, 'sourceConfiguration' => []],
			'object-1',
			[],
			&$filename,
		];

		$method = new \ReflectionMethod(SynchronizationService::class, 'fetchFile');
		$method->setAccessible(true);
		$result = $method->invokeArgs($this->service, $args);

		$this->assertTrue(
			$sinkWasPath,
			'a temp-file PATH must be handed to CallService::call() on the binary path, not a resource'
		);
		$this->assertSame(
			base64_encode($bytes),
			$result,
			'the dry-run return must be produced from the streamed temp file'
		);
		$this->assertFileDoesNotExist(
			(string)$sinkPath,
			'the temp file must be removed in the finally block'
		);

	}//end testFetchFileStreamsRawBinaryDownloadIntoASinkResource()

	/**
	 * stream-file-content #110: base64-in-JSON content addressed by `config['contentPath']`
	 * MUST stay on the existing in-memory string path — no sink is opened, because the body
	 * has to be parsed to extract `contentPath`/`filenamePath`.
	 *
	 * The mock raises a sentinel once the transport choice is observable, so the assertion
	 * is confined to branch selection and does not depend on the string path's downstream
	 * behaviour. `fetchFile`'s outer `try` has only a `finally`, so the sentinel propagates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stream-file-content/specs/synchronization-files/spec.md#requirement-base64-in-json-content-shall-continue-on-the-existing-string-path
	 */
	public function testFetchFileKeepsBase64InJsonResponsesOffTheStreamingPath(): void {
		$sinkWasResource = false;

		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$sinkWasResource) {
				foreach ($args as $arg) {
					if (is_resource($arg) === true) {
						$sinkWasResource = true;
					}
				}

				throw new \RuntimeException('sentinel: transport already selected');
			}
		);

		$filename = null;
		$args = [
			['_transient' => true, 'uuid' => 'source-uuid', 'location' => ''],
			'/attachment/1',
			[
				'write' => false,
				'contentPath' => 'content',
				'sourceConfiguration' => [],
			],
			'object-1',
			[],
			&$filename,
		];

		$method = new \ReflectionMethod(SynchronizationService::class, 'fetchFile');
		$method->setAccessible(true);

		try {
			$method->invokeArgs($this->service, $args);
			$this->fail('the sentinel exception should have propagated out of fetchFile');
		} catch (\RuntimeException $exception) {
			$this->assertStringContainsString('sentinel', $exception->getMessage());
		}

		$this->assertFalse(
			$sinkWasResource,
			'no sink may be opened when contentPath addresses a JSON envelope'
		);

	}//end testFetchFileKeepsBase64InJsonResponsesOffTheStreamingPath()

	/**
	 * Shared observation record for the concurrent-fetch tests.
	 *
	 * @var array
	 */
	private array $fetchRun = [];

	/**
	 * Wire `callService::callAsync()` up as a controllable async transport and
	 * record what the concurrent fetcher does with it.
	 *
	 * Each dispatch returns a Guzzle promise whose WAIT function performs the
	 * simulated download. That is what makes these tests deterministic without a
	 * real socket: `EachPromise`'s aggregate waits its pending promises one at a
	 * time, so a promise settles exactly when the pool gets around to it, and the
	 * recorded timeline shows the real interleaving of dispatches, settles and
	 * saves.
	 *
	 * Recorded in `$this->fetchRun`:
	 *  - `timeline`    ordered `dispatch:N` / `settle:N` / `save:<filename>` events
	 *  - `maxInFlight` high-water mark of dispatched-but-unsettled requests
	 *  - `sinks`       every sink argument handed to `callAsync()`
	 *  - `sinkPaths`   the subset of those that were existing file paths
	 *  - `saved`       filenames passed to `FileService::saveFile()`
	 *
	 * @param array $rejectIndexes Zero-based dispatch indexes whose promise must reject.
	 * @param callable|null $onSave Optional extra hook invoked when a save happens.
	 *
	 * @return void
	 */
	private function arrangeAsyncFetchTransport(array $rejectIndexes = [], ?callable $onSave = null): void {
		$this->fetchRun = [
			'timeline' => [],
			'inFlight' => 0,
			'maxInFlight' => 0,
			'sinks' => [],
			'sinkPaths' => [],
			'saved' => [],
			'dispatched' => 0,
		];

		// FileService double: records each save and reports no existing files, so
		// the post-run orphan cleanup is a no-op rather than a TypeError.
		$fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
		$fileService->method('getFiles')->willReturn([]);
		$fileService->method('saveFile')->willReturnCallback(
			function (...$args) use ($onSave) {
				$fileName = null;
				foreach ($args as $arg) {
					if (is_string($arg) === true && $arg !== '') {
						$fileName = $arg;
						break;
					}
				}

				$this->fetchRun['saved'][] = $fileName;
				$this->fetchRun['timeline'][] = 'save:' . $fileName;

				if ($onSave !== null) {
					$onSave($fileName);
				}

				return $this->createMock(\OCP\Files\File::class);
			}
		);

		$objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
		$objectEntity->setUuid('11111111-2222-3333-4444-555555555555');

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willReturn($objectEntity);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($fileService, $orObjectService) {
				if ($id === 'OCA\OpenRegister\Service\FileService') {
					return $fileService;
				}

				return $orObjectService;
			}
		);

		$this->callService->method('callAsync')->willReturnCallback(
			function (...$args) use ($rejectIndexes) {
				$index = $this->fetchRun['dispatched'];
				$this->fetchRun['dispatched']++;

				// Locate the sink path and the on_headers hook positionally-agnostically,
				// so this double does not break if the signature grows a parameter.
				$sinkPath = null;
				$onHeaders = null;
				foreach ($args as $arg) {
					if (is_string($arg) === true && $arg !== '' && is_file($arg) === true) {
						$sinkPath = $arg;
					}

					if (is_callable($arg) === true && is_string($arg) === false) {
						$onHeaders = $arg;
					}
				}

				$this->fetchRun['sinks'][] = $sinkPath;
				if ($sinkPath !== null) {
					$this->fetchRun['sinkPaths'][] = $sinkPath;
				}

				$this->fetchRun['inFlight']++;
				$this->fetchRun['maxInFlight'] = max($this->fetchRun['maxInFlight'], $this->fetchRun['inFlight']);
				$this->fetchRun['timeline'][] = 'dispatch:' . $index;

				$bytes = 'bytes-for-file-' . $index;
				$promise = null;
				$promise = new \GuzzleHttp\Promise\Promise(
					function () use (&$promise, $index, $sinkPath, $onHeaders, $bytes, $rejectIndexes) {
						$this->fetchRun['inFlight']--;
						$this->fetchRun['timeline'][] = 'settle:' . $index;

						if (in_array($index, $rejectIndexes, true) === true) {
							$promise->reject(new \RuntimeException('simulated transport failure ' . $index));

							return;
						}

						// Emulate Guzzle: headers first (so the byte budget can
						// observe Content-Length), then the body into the PATH.
						//
						// A THROW FROM on_headers REJECTS THE REQUEST — Guzzle
						// catches it and fails the promise ("An error was
						// encountered during the on_headers event"), and the body
						// is never downloaded. Calling the hook bare would let the
						// exception escape the transport instead, so a per-file
						// size refusal could not be tested at all.
						if ($onHeaders !== null) {
							try {
								$onHeaders(new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => (string)strlen($bytes)]));
							} catch (\Throwable $headersException) {
								$promise->reject($headersException);

								return;
							}
						}

						if ($sinkPath !== null) {
							file_put_contents($sinkPath, $bytes);
						}

						$callLog = new \OCA\OpenRegister\Db\ObjectEntity();
						$callLog->setObject(
							[
								'response' => [
									'body' => '',
									'headers' => ['Content-Disposition' => 'attachment; filename="doc-' . $index . '.pdf"'],
								],
							]
						);

						$promise->resolve($callLog);
					}
				);

				return $promise;
			}
		);
	}//end arrangeAsyncFetchTransport()

	/**
	 * Invoke the concurrent multi-file path for N endpoints.
	 *
	 * @param int $count How many file endpoints the object has.
	 * @param array $sourceConfiguration The per-source `configuration` block (cap / byte budget).
	 *
	 * @return void
	 */
	private function runMultiFileFetch(int $count, array $sourceConfiguration = []): void {
		$endpoints = [];
		for ($index = 0; $index < $count; $index++) {
			$endpoints[] = '/attachment/' . $index;
		}

		$source = [
			'_transient' => true,
			'uuid' => 'source-uuid',
			'location' => '',
			'configuration' => $sourceConfiguration,
		];

		$method = new \ReflectionMethod(SynchronizationService::class, 'processMultipleFilesWithCleanup');
		$method->setAccessible(true);
		$method->invoke(
			$this->service,
			$source,
			['sourceConfiguration' => []],
			$endpoints,
			'11111111-2222-3333-4444-555555555555'
		);
	}//end runMultiFileFetch()

	/**
	 * ocon#111: one object's several files are fetched CONCURRENTLY — dispatched
	 * into a shared in-flight window rather than one blocking call after another.
	 *
	 * The proof is the high-water mark: with 4 endpoints under the default cap of
	 * 5, all 4 are in flight at once. A sequential loop could never exceed 1.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 */
	public function testMultipleFilesForOneObjectAreFetchedConcurrently(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 4);

		$this->assertSame(4, $this->fetchRun['dispatched'], 'every file must be dispatched');
		$this->assertSame(
			4,
			$this->fetchRun['maxInFlight'],
			'all 4 fetches must be in flight together under the default cap of 5; a sequential loop peaks at 1'
		);
		$this->assertCount(4, $this->fetchRun['saved'], 'every fetched file must be saved');
	}//end testMultipleFilesForOneObjectAreFetchedConcurrently()

	/**
	 * ocon#111: the sink handed to the async transport is a PATH, never a stream
	 * resource — and every temp file is gone once the object finishes.
	 *
	 * Guzzle closes a resource-typed sink when its PSR-7 wrapper is destructed,
	 * which under asynchronous dispatch happens outside the caller's control.
	 * This asserts on the argument TYPE, mirroring the sequential-path assertion
	 * in testFetchFileStreamsRawBinaryDownloadIntoASinkResource().
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 */
	public function testEveryConcurrentSinkIsAPathAndEveryTempFileIsRemoved(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 3);

		$this->assertCount(3, $this->fetchRun['sinkPaths'], 'each concurrent fetch must receive its own temp-file path');

		foreach ($this->fetchRun['sinks'] as $sink) {
			$this->assertIsNotResource($sink, 'a stream resource must never be handed to the async transport');
		}

		$this->assertSame(
			3,
			count(array_unique($this->fetchRun['sinkPaths'])),
			'concurrent fetches must not share a temp file'
		);

		foreach ($this->fetchRun['sinkPaths'] as $path) {
			$this->assertFileDoesNotExist($path, 'no oc-stream-* temp file may remain for the object');
		}
	}//end testEveryConcurrentSinkIsAPathAndEveryTempFileIsRemoved()

	/**
	 * ocon#111: in-flight fetches never exceed the per-source configured cap, and
	 * the held-back files still all run — started as in-flight requests complete.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	public function testInFlightFetchesNeverExceedTheConfiguredCap(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 6, sourceConfiguration: ['maxConcurrentFetches' => 2]);

		$this->assertSame(6, $this->fetchRun['dispatched'], 'every file must eventually be dispatched');
		$this->assertSame(
			2,
			$this->fetchRun['maxInFlight'],
			'the configured cap of 2 must never be exceeded'
		);
		$this->assertCount(6, $this->fetchRun['saved'], 'throttled files must still be fetched and saved');
	}//end testInFlightFetchesNeverExceedTheConfiguredCap()

	/**
	 * ocon#111: a source asking for more concurrency than the hard maximum is
	 * clamped rather than obeyed, so a misconfiguration cannot turn one object's
	 * attachments into an unbounded burst against an upstream.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	public function testConcurrencyIsClampedToTheHardMaximum(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 25, sourceConfiguration: ['maxConcurrentFetches' => 500]);

		$this->assertSame(25, $this->fetchRun['dispatched']);
		$this->assertLessThanOrEqual(
			20,
			$this->fetchRun['maxInFlight'],
			'the hard maximum of 20 must hold regardless of what the source configures'
		);
	}//end testConcurrencyIsClampedToTheHardMaximum()

	/**
	 * A file whose declared Content-Length exceeds the per-file ceiling is
	 * refused at the header boundary, before its body is downloaded.
	 *
	 * The only other file-size control in the stack is the schema's `maxSize`,
	 * and FilePropertyHandler checks that at SAVE time — after the whole file
	 * has already been written to disk. Without this ceiling a multi-gigabyte
	 * attachment is fetched in full and only then rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	public function testAFileLargerThanTheCeilingIsRefusedBeforeDownload(): void {
		$this->arrangeAsyncFetchTransport();
		// The harness serves 'bytes-for-file-<n>' (16+ bytes); 5 is below every one.
		$this->runMultiFileFetch(count: 3, sourceConfiguration: ['maxFileSize' => 5]);

		$this->assertCount(
			0,
			$this->fetchRun['saved'],
			'a file over the per-file ceiling must not be saved'
		);
	}//end testAFileLargerThanTheCeilingIsRefusedBeforeDownload()

	/**
	 * THE CONTROL for {@see testAFileLargerThanTheCeilingIsRefusedBeforeDownload()}:
	 * the same run with a ceiling ABOVE the file size saves every file, so the
	 * test above cannot pass merely because the harness saves nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	public function testAFileWithinTheCeilingIsUnaffected(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 3, sourceConfiguration: ['maxFileSize' => 100000]);

		$this->assertCount(
			3,
			$this->fetchRun['saved'],
			'a file under the per-file ceiling must still be saved'
		);
	}//end testAFileWithinTheCeilingIsUnaffected()

	/**
	 * The ceiling is OFF by default: an unconfigured source keeps fetching files
	 * of any size, so adding this control cannot silently start dropping
	 * documents from syncs that already work.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	public function testThePerFileCeilingIsDisabledByDefault(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 3);

		$this->assertCount(
			3,
			$this->fetchRun['saved'],
			'with no maxFileSize configured every file must still be saved'
		);
	}//end testThePerFileCeilingIsDisabledByDefault()

	/**
	 * ocon#111: a resolved download is SAVED from its promise's `then()` while
	 * sibling downloads are still to come — the pipelining that turns
	 * `Σ fetch + Σ saves` into `max(fetch-window, Σ saves)`.
	 *
	 * The assertion is positional in the recorded timeline: with a cap of 2 over 4
	 * files, at least one `save:` event must appear before the LAST `dispatch:`
	 * event. A collect-then-save implementation would emit all four dispatches
	 * first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	public function testResolvedFetchIsSavedBeforeTheLastSiblingIsDispatched(): void {
		$this->arrangeAsyncFetchTransport();
		$this->runMultiFileFetch(count: 4, sourceConfiguration: ['maxConcurrentFetches' => 2]);

		$timeline = $this->fetchRun['timeline'];

		$lastDispatch = null;
		$firstSave = null;
		foreach ($timeline as $position => $event) {
			if (str_starts_with($event, 'dispatch:') === true) {
				$lastDispatch = $position;
			}

			if ($firstSave === null && str_starts_with($event, 'save:') === true) {
				$firstSave = $position;
			}
		}

		$this->assertNotNull($firstSave, 'at least one file must have been saved');
		$this->assertNotNull($lastDispatch);
		$this->assertLessThan(
			$lastDispatch,
			$firstSave,
			'a save must run while siblings are still being dispatched: ' . implode(' ', $timeline)
		);
	}//end testResolvedFetchIsSavedBeforeTheLastSiblingIsDispatched()

	/**
	 * ocon#111: saves are pipelined but NOT parallel. PHP is single-threaded and
	 * Nextcloud uses one shared database connection, so two OpenRegister writes
	 * must never overlap.
	 *
	 * Proven by re-entrancy: the save hook records whether another save was
	 * already in progress when it was entered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized
	 */
	public function testSavesAreNeverRunConcurrently(): void {
		$saveDepth = 0;
		$overlapSeen = false;

		$this->arrangeAsyncFetchTransport(
			rejectIndexes: [],
			onSave: function () use (&$saveDepth, &$overlapSeen): void {
				$saveDepth++;
				if ($saveDepth > 1) {
					$overlapSeen = true;
				}

				$saveDepth--;
			}
		);

		$this->runMultiFileFetch(count: 5);

		$this->assertFalse($overlapSeen, 'OpenRegister writes must be executed one at a time');
		$this->assertCount(5, $this->fetchRun['saved']);
	}//end testSavesAreNeverRunConcurrently()

	/**
	 * ocon#111: one file's fetch failure is isolated — logged and skipped — while
	 * the other files are still fetched and saved and the object continues.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	public function testOneFailedFetchDoesNotStopTheOthers(): void {
		$this->arrangeAsyncFetchTransport(rejectIndexes: [1]);
		$this->runMultiFileFetch(count: 4);

		$this->assertSame(4, $this->fetchRun['dispatched'], 'a failure must not abort the remaining dispatches');
		$this->assertCount(
			3,
			$this->fetchRun['saved'],
			'the three healthy files must still be saved'
		);

		foreach ($this->fetchRun['sinkPaths'] as $path) {
			$this->assertFileDoesNotExist(
				$path,
				'a failed leg must release its temp file just as a successful one does'
			);
		}
	}//end testOneFailedFetchDoesNotStopTheOthers()

	/**
	 * ocon#111: a failed SAVE is isolated the same way a failed fetch is — the
	 * remaining files are still saved and the object continues.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	public function testOneFailedSaveDoesNotStopTheOthers(): void {
		$attempts = 0;

		$this->arrangeAsyncFetchTransport(
			rejectIndexes: [],
			onSave: function () use (&$attempts): void {
				$attempts++;
				if ($attempts === 2) {
					throw new \RuntimeException('simulated save failure');
				}
			}
		);

		$this->runMultiFileFetch(count: 4);

		$this->assertSame(4, $attempts, 'every file must still reach its save attempt');

		foreach ($this->fetchRun['sinkPaths'] as $path) {
			$this->assertFileDoesNotExist($path, 'a throwing save must still release its temp file');
		}
	}//end testOneFailedSaveDoesNotStopTheOthers()

	/**
	 * ocon#111 Task 0 back-compatibility: `callSourceObject()` without any
	 * asynchronous flag still goes through the synchronous `CallService::call()`
	 * and still returns an `ObjectEntity`. The async capability is a sibling, not
	 * a widened return, precisely so this contract is untouched at ~30 call sites.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 */
	public function testCallSourceObjectStillReturnsAnObjectEntitySynchronously(): void {
		$callLog = new \OCA\OpenRegister\Db\ObjectEntity();
		$callLog->setObject(['response' => ['body' => '{}', 'headers' => []]]);

		$this->callService->expects($this->once())->method('call')->willReturn($callLog);
		$this->callService->expects($this->never())->method('callAsync');

		$method = new \ReflectionMethod(SynchronizationService::class, 'callSourceObject');
		$method->setAccessible(true);
		$result = $method->invoke(
			$this->service,
			['_transient' => true, 'uuid' => 'source-uuid', 'location' => ''],
			'/things'
		);

		$this->assertInstanceOf(\OCA\OpenRegister\Db\ObjectEntity::class, $result);
	}//end testCallSourceObjectStillReturnsAnObjectEntitySynchronously()
}//end class
