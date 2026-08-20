<?php

/**
 * A CONTRACT MUST BE UPSERTED ON ITS OWN IDENTITY.
 *
 * `persist()` used to read `$object['uuid']` alone and then drop `$object['id']`,
 * on the reasoning that `id` was a legacy integer. A contract payload carries no
 * `uuid` property at all — its identity comes back from OpenRegister AS `id`, and
 * that `id` is a uuid string. So the upsert key was always null and every save
 * CREATED a new object: four distinct contracts for one
 * (synchronizationId, originId), one per run, each with an IDENTICAL originHash.
 * `synchronization_contract` reached 528,656 rows on the dev instance.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Contract upsert identity.
 */
class SynchronizationContractIdentityTest extends TestCase {

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
	 * A contract read back from OpenRegister has `id` (a uuid string) and no
	 * `uuid`. That id IS the identity and must key the upsert — this is the
	 * defect: previously the key was null and the save created a duplicate.
	 *
	 * @return void
	 */
	public function testUuidStringIdKeysTheUpsert(): void {
		$this->assertSame(
			'2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33',
			$this->service->contractIdentity(object: ['id' => '2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33'])
		);
	}//end testUuidStringIdKeysTheUpsert()

	/**
	 * An explicit `uuid` still wins over `id`.
	 *
	 * @return void
	 */
	public function testExplicitUuidWins(): void {
		$this->assertSame(
			'from-uuid',
			$this->service->contractIdentity(object: ['uuid' => 'from-uuid', 'id' => 'from-id'])
		);
	}//end testExplicitUuidWins()

	/**
	 * A legacy NUMERIC id is not an OpenRegister identifier and must not become
	 * an upsert key — that is what the original code was right to guard against.
	 *
	 * @return void
	 */
	public function testNumericIdIsRefused(): void {
		$this->assertNull($this->service->contractIdentity(object: ['id' => 4438]));
		$this->assertNull($this->service->contractIdentity(object: ['id' => '4438']));
	}//end testNumericIdIsRefused()

	/**
	 * Nothing to key on returns null, which creates — the correct behaviour for
	 * a genuinely new contract.
	 *
	 * @return void
	 */
	public function testAbsentIdentityCreates(): void {
		$this->assertNull($this->service->contractIdentity(object: []));
		$this->assertNull($this->service->contractIdentity(object: ['uuid' => '', 'id' => '']));
	}//end testAbsentIdentityCreates()

	/**
	 * The end-to-end property that actually failed in production: persisting a
	 * contract that came back from OpenRegister must pass that identity to
	 * saveObject, NOT null. A null uuid is what minted a duplicate per run.
	 *
	 * @return void
	 */
	public function testPersistUpsertsRatherThanCreating(): void {
		$saved = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1'], 'c-1');

		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->anything(),
				$this->equalTo('openconnector'),
				$this->equalTo('synchronization_contract'),
				$this->equalTo('2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33')
			)
			->willReturn($saved);

		$this->service->persist(contract: [
			'id' => '2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33',
			'synchronizationId' => 's-1',
			'originId' => 'o-1',
			'originHash' => '4da6d1cb78a5',
		]);
	}//end testPersistUpsertsRatherThanCreating()

	/**
	 * THE SECOND HALF OF THE DEFECT. `ensureUuid` is meant to mint an identity
	 * for a contract that HAS none. It used to test `empty($object['uuid'])`,
	 * which is true for EVERY contract loaded back from OpenRegister — they carry
	 * `id`, never `uuid` — so it minted a fresh uuid on top of a perfectly good
	 * identity and the save created instead of updating.
	 *
	 * Both persists at the end of synchronizeContract pass `ensureUuid: true`, so
	 * this was every update. Measured live AFTER the first half of the fix landed:
	 * a run that skipped 1903 of 2000 and updated 97 still added exactly 97
	 * contract rows.
	 *
	 * @return void
	 */
	public function testEnsureUuidDoesNotOverrideAnExistingIdentity(): void {
		$saved = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1'], 'c-1');

		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->anything(),
				$this->equalTo('openconnector'),
				$this->equalTo('synchronization_contract'),
				$this->equalTo('2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33')
			)
			->willReturn($saved);

		$this->service->persist(
			contract: [
				'id' => '2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33',
				'synchronizationId' => 's-1',
				'originId' => 'o-1',
			],
			ensureUuid: true
		);
	}//end testEnsureUuidDoesNotOverrideAnExistingIdentity()

	/**
	 * `ensureUuid` must still do its job for a contract that genuinely has no
	 * identity: mint one rather than passing null and creating an unkeyed row.
	 *
	 * @return void
	 */
	public function testEnsureUuidStillMintsWhenThereIsNoIdentity(): void {
		$saved = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-2'], 'c-2');
		$seen = null;

		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (...$args) use ($saved, &$seen): mixed {
					$seen = ($args[3] ?? null);

					return $saved;
				}
			);

		$this->service->persist(
			contract: ['synchronizationId' => 's-1', 'originId' => 'o-2'],
			ensureUuid: true
		);

		$this->assertNotNull($seen, 'ensureUuid must mint an identity when there is none');
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$seen);
	}//end testEnsureUuidStillMintsWhenThereIsNoIdentity()
}//end class
