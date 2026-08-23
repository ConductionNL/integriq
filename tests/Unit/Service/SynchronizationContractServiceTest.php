<?php

/**
 * Unit tests for SynchronizationContractService — the contract-lifecycle
 * service extracted from SynchronizationService in W14 Tier 2.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the OpenRegister-backed contract lifecycle service.
 */
class SynchronizationContractServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var SynchronizationContractService
	 */
	private SynchronizationContractService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->service = new SynchronizationContractService($this->orObjectService);
	}//end setUp()

	/**
	 * findObject() proxies to the OR ObjectService find() against the
	 * `openconnector` register and `synchronization_contract` schema.
	 *
	 * @return void
	 */
	public function testFindObjectQueriesOrObjectService(): void {
		$entity = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'x'], 'c-1');

		$this->orObjectService->expects($this->once())
			->method('find')
			->with(
				$this->equalTo('c-1'),
				$this->equalTo('openconnector'),
				$this->equalTo('synchronization_contract')
			)
			->willReturn($entity);

		$result = $this->service->findObject('c-1');

		$this->assertSame($entity, $result);
	}//end testFindObjectQueriesOrObjectService()

	/**
	 * findObject() returns null when OR find returns null (no match).
	 *
	 * @return void
	 */
	public function testFindObjectReturnsNullWhenMissing(): void {
		$this->orObjectService->method('find')->willReturn(null);

		$this->assertNull($this->service->findObject('missing'));
	}//end testFindObjectReturnsNullWhenMissing()

	/**
	 * findAllObjects() prepends register+schema filters and unwraps the
	 * `results` envelope.
	 *
	 * @return void
	 */
	public function testFindAllObjectsUnwrapsResultsAndInjectsScopeFilters(): void {
		$entity = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'x'], 'c-1');

		$this->orObjectService->expects($this->once())
			->method('findAll')
			->with($this->callback(function (array $config): bool {
				$filters = ($config['filters'] ?? []);
				return ($filters['register'] ?? null) === 'openconnector'
					&& ($filters['schema'] ?? null) === 'synchronization_contract'
					&& ($filters['originId'] ?? null) === 'origin-7';
			}))
			->willReturn(['results' => [$entity], 'total' => 1]);

		$result = $this->service->findAllObjects(['originId' => 'origin-7']);

		$this->assertCount(1, $result);
		$this->assertSame($entity, $result[0]);
	}//end testFindAllObjectsUnwrapsResultsAndInjectsScopeFilters()

	/**
	 * find() returns the serialised payload for a single contract.
	 *
	 * @return void
	 */
	public function testFindReturnsJsonSerializedPayload(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['originId' => 'origin-1', 'targetId' => 'target-1'],
			'c-1'
		);

		$this->orObjectService->method('find')->willReturn($entity);

		$payload = $this->service->find('c-1');

		$this->assertIsArray($payload);
		$this->assertSame('c-1', $payload['uuid'] ?? null);
		$this->assertSame('origin-1', $payload['originId'] ?? null);
	}//end testFindReturnsJsonSerializedPayload()

	/**
	 * find() throws DoesNotExistException when no contract matches.
	 *
	 * @return void
	 */
	public function testFindThrowsWhenContractMissing(): void {
		$this->orObjectService->method('find')->willReturn(null);

		$this->expectException(DoesNotExistException::class);

		$this->service->find('missing');
	}//end testFindThrowsWhenContractMissing()

	/**
	 * findBySyncAndOrigin() filters on both synchronizationId AND originId by
	 * default and returns the first match.
	 *
	 * @return void
	 */
	public function testFindBySyncAndOriginUsesBothFiltersByDefault(): void {
		$entity = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1'], 'c-1');

		$this->orObjectService->expects($this->once())
			->method('findAll')
			->with($this->callback(function (array $config): bool {
				$filters = ($config['filters'] ?? []);
				return ($filters['synchronizationId'] ?? null) === 'sync-1'
					&& ($filters['originId'] ?? null) === 'o-1';
			}))
			->willReturn(['results' => [$entity], 'total' => 1]);

		$payload = $this->service->findBySyncAndOrigin('sync-1', 'o-1');

		$this->assertIsArray($payload);
		$this->assertSame('c-1', $payload['uuid'] ?? null);
	}//end testFindBySyncAndOriginUsesBothFiltersByDefault()

	/**
	 * findBySyncAndOrigin() filters on originId only when justByOriginId=true.
	 *
	 * @return void
	 */
	public function testFindBySyncAndOriginUsesOriginOnlyWhenRequested(): void {
		$entity = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1'], 'c-1');

		$this->orObjectService->expects($this->once())
			->method('findAll')
			->with($this->callback(function (array $config): bool {
				$filters = ($config['filters'] ?? []);
				return array_key_exists('synchronizationId', $filters) === false
					&& ($filters['originId'] ?? null) === 'o-1';
			}))
			->willReturn(['results' => [$entity], 'total' => 1]);

		$payload = $this->service->findBySyncAndOrigin('ignored', 'o-1', true);

		$this->assertIsArray($payload);
	}//end testFindBySyncAndOriginUsesOriginOnlyWhenRequested()

	/**
	 * findBySyncAndOrigin() returns null when no contract matches.
	 *
	 * @return void
	 */
	public function testFindBySyncAndOriginReturnsNullWhenEmpty(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->assertNull($this->service->findBySyncAndOrigin('sync-1', 'o-missing'));
	}//end testFindBySyncAndOriginReturnsNullWhenEmpty()

	/**
	 * findByOriginId() returns the first match payload.
	 *
	 * @return void
	 */
	public function testFindByOriginIdReturnsFirstMatch(): void {
		$entity = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-9'], 'c-9');

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$entity], 'total' => 1]);

		$payload = $this->service->findByOriginId('o-9');

		$this->assertIsArray($payload);
		$this->assertSame('c-9', $payload['uuid'] ?? null);
	}//end testFindByOriginIdReturnsFirstMatch()

	/**
	 * findByOriginId() returns null when no contract matches.
	 *
	 * @return void
	 */
	public function testFindByOriginIdReturnsNullWhenEmpty(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->assertNull($this->service->findByOriginId('o-missing'));
	}//end testFindByOriginIdReturnsNullWhenEmpty()

	/**
	 * findTargetIdByOriginId() returns the targetId field of the matched contract.
	 *
	 * @return void
	 */
	public function testFindTargetIdByOriginIdReturnsTargetId(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['originId' => 'o-1', 'targetId' => 'target-42'],
			'c-1'
		);

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$entity], 'total' => 1]);

		$this->assertSame('target-42', $this->service->findTargetIdByOriginId('o-1'));
	}//end testFindTargetIdByOriginIdReturnsTargetId()

	/**
	 * findTargetIdByOriginId() returns null when targetId is empty.
	 *
	 * @return void
	 */
	public function testFindTargetIdByOriginIdReturnsNullWhenTargetEmpty(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['originId' => 'o-1', 'targetId' => ''],
			'c-1'
		);

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$entity], 'total' => 1]);

		$this->assertNull($this->service->findTargetIdByOriginId('o-1'));
	}//end testFindTargetIdByOriginIdReturnsNullWhenTargetEmpty()

	/**
	 * findTargetIdByOriginId() returns null when no contract matches.
	 *
	 * @return void
	 */
	public function testFindTargetIdByOriginIdReturnsNullWhenNoMatch(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->assertNull($this->service->findTargetIdByOriginId('o-missing'));
	}//end testFindTargetIdByOriginIdReturnsNullWhenNoMatch()

	/**
	 * persist() drops the legacy int `id` from the payload and keys the
	 * saveObject upsert on the contract uuid.
	 *
	 * @return void
	 */
	public function testPersistDropsLegacyIdAndKeysOnUuid(): void {
		$observedPayload = null;
		$observedUuid = null;

		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedPayload, &$observedUuid) {
				$observedPayload = $object;
				$observedUuid = $uuid;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'new-uuid');
			});

		$this->service->persist(['id' => 17, 'uuid' => 'c-99', 'originId' => 'origin']);

		$this->assertIsArray($observedPayload);
		$this->assertArrayNotHasKey('id', $observedPayload);
		$this->assertSame('c-99', $observedPayload['uuid']);
		$this->assertSame('c-99', $observedUuid);
	}//end testPersistDropsLegacyIdAndKeysOnUuid()

	/**
	 * persist(ensureUuid=true) auto-fills a uuid when missing.
	 *
	 * @return void
	 */
	public function testPersistAutoFillsUuidWhenEnsured(): void {
		$observedUuid = null;

		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedUuid) {
				$observedUuid = $uuid;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'new');
			});

		$this->service->persist(['originId' => 'origin'], true);

		$this->assertIsString($observedUuid);
		$this->assertNotEmpty($observedUuid);
	}//end testPersistAutoFillsUuidWhenEnsured()

	/**
	 * persist(ensureUuid=false) keeps uuid null on the saveObject call when
	 * the contract payload has no uuid yet.
	 *
	 * @return void
	 */
	public function testPersistDoesNotAutoFillUuidByDefault(): void {
		$observedUuid = 'sentinel';

		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedUuid) {
				$observedUuid = $uuid;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'new');
			});

		$this->service->persist(['originId' => 'origin']);

		$this->assertNull($observedUuid);
	}//end testPersistDoesNotAutoFillUuidByDefault()

	/**
	 * createFromArray() auto-fills uuid + version when both are missing and
	 * always keys the saveObject upsert on the uuid.
	 *
	 * @return void
	 */
	public function testCreateFromArrayAutoFillsUuidAndVersion(): void {
		$observedPayload = null;
		$observedUuid = null;

		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedPayload, &$observedUuid) {
				$observedPayload = $object;
				$observedUuid = $uuid;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'new');
			});

		$this->service->createFromArray(['originId' => 'origin', 'id' => 99]);

		$this->assertIsArray($observedPayload);
		$this->assertArrayNotHasKey('id', $observedPayload);
		$this->assertSame('0.0.1', $observedPayload['version']);
		$this->assertIsString($observedPayload['uuid']);
		$this->assertNotEmpty($observedPayload['uuid']);
		$this->assertSame($observedPayload['uuid'], $observedUuid);
	}//end testCreateFromArrayAutoFillsUuidAndVersion()

	/**
	 * updateFromArray() throws DoesNotExistException when the contract does
	 * not exist (the underlying find() lookup fails).
	 *
	 * @return void
	 */
	public function testUpdateFromArrayThrowsWhenContractMissing(): void {
		$this->orObjectService->method('find')->willReturn(null);

		$this->expectException(DoesNotExistException::class);

		$this->service->updateFromArray('missing', ['originId' => 'o']);
	}//end testUpdateFromArrayThrowsWhenContractMissing()

	/**
	 * updateFromArray() bumps the patch component of an existing version when
	 * the caller does not supply one.
	 *
	 * @return void
	 */
	public function testUpdateFromArrayBumpsPatchVersion(): void {
		$existing = ObjectServiceMockBuilder::objectEntity(
			$this,
			['originId' => 'origin', 'version' => '1.4.7'],
			'c-1'
		);
		$this->orObjectService->method('find')->willReturn($existing);

		$observedPayload = null;
		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedPayload) {
				$observedPayload = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'c-1');
			});

		$this->service->updateFromArray('c-1', ['targetId' => 't-new']);

		$this->assertIsArray($observedPayload);
		$this->assertSame('1.4.8', $observedPayload['version']);
		$this->assertSame('t-new', $observedPayload['targetId']);
		$this->assertArrayNotHasKey('id', $observedPayload);
	}//end testUpdateFromArrayBumpsPatchVersion()

	/**
	 * updateFromArray() defaults to 0.0.1 when the existing record has no
	 * version field at all.
	 *
	 * @return void
	 */
	public function testUpdateFromArrayDefaultsToInitialVersionWhenMissing(): void {
		$existing = ObjectServiceMockBuilder::objectEntity(
			$this,
			['originId' => 'origin'],
			'c-1'
		);
		$this->orObjectService->method('find')->willReturn($existing);

		$observedPayload = null;
		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedPayload) {
				$observedPayload = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'c-1');
			});

		$this->service->updateFromArray('c-1', ['targetId' => 't-new']);

		$this->assertSame('0.0.1', $observedPayload['version']);
	}//end testUpdateFromArrayDefaultsToInitialVersionWhenMissing()

	/**
	 * updateFromArray() honours an explicit caller-supplied version.
	 *
	 * @return void
	 */
	public function testUpdateFromArrayHonoursExplicitVersion(): void {
		$existing = ObjectServiceMockBuilder::objectEntity(
			$this,
			['originId' => 'origin', 'version' => '1.0.0'],
			'c-1'
		);
		$this->orObjectService->method('find')->willReturn($existing);

		$observedPayload = null;
		$this->orObjectService->method('saveObject')
			->willReturnCallback(function (
				$object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
			) use (&$observedPayload) {
				$observedPayload = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'c-1');
			});

		$this->service->updateFromArray('c-1', ['targetId' => 't-new', 'version' => '2.0.0']);

		$this->assertSame('2.0.0', $observedPayload['version']);
	}//end testUpdateFromArrayHonoursExplicitVersion()

}//end class
