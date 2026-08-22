<?php

/**
 * THE FALLBACK persistContract() MUST KEY ON IDENTITY TOO.
 *
 * SynchronizationService::persistContract() delegates to
 * SynchronizationContractService when one is wired, and carries its own copy of
 * the same logic for when one is not. Both had the `ensureUuid` defect: it
 * tested `empty($object['uuid'])`, true for every contract loaded back from
 * OpenRegister (they carry `id`, never `uuid`), so it minted a fresh uuid over a
 * perfectly good identity and the save created instead of updating.
 *
 * The delegate is covered by SynchronizationContractIdentityTest. This covers the
 * copy — the paths must not drift.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The non-delegating persistContract() path.
 */
class SynchronizationServicePersistContractFallbackTest extends TestCase {

	/**
	 * Build a SynchronizationService with only the two properties this path
	 * touches, and no contract-service delegate so the local copy runs.
	 *
	 * @param object $orObjectService The mocked OR object service.
	 *
	 * @return SynchronizationService The instance under test.
	 */
	private function serviceWithoutDelegate(object $orObjectService): SynchronizationService {
		$class = new \ReflectionClass(SynchronizationService::class);
		$instance = $class->newInstanceWithoutConstructor();

		$or = $class->getProperty('orObjectService');
		$or->setAccessible(true);
		$or->setValue($instance, $orObjectService);

		$delegate = $class->getProperty('synchronizationContractService');
		$delegate->setAccessible(true);
		$delegate->setValue($instance, null);

		return $instance;
	}//end serviceWithoutDelegate()

	/**
	 * Invoke the private persistContract().
	 *
	 * @param SynchronizationService $service    The instance.
	 * @param array                  $contract   The contract payload.
	 * @param bool                   $ensureUuid Whether to mint a missing identity.
	 *
	 * @return array The persisted payload.
	 */
	private function persistContract(SynchronizationService $service, array $contract, bool $ensureUuid): array {
		$method = new \ReflectionMethod(SynchronizationService::class, 'persistContract');
		$method->setAccessible(true);

		return $method->invoke($service, $contract, $ensureUuid);
	}//end persistContract()

	/**
	 * `ensureUuid: true` must not mint over the `id` the contract already has —
	 * the exact call shape both persists at the end of synchronizeContract use.
	 *
	 * @return void
	 */
	public function testEnsureUuidDoesNotOverrideAnExistingIdentity(): void {
		$or = ObjectServiceMockBuilder::make($this);
		$saved = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1'], 'c-1');
		$seen = 'unset';

		$or->method('saveObject')->willReturnCallback(
			function (...$args) use ($saved, &$seen): mixed {
				$seen = ($args[3] ?? null);

				return $saved;
			}
		);

		$this->persistContract(
			service: $this->serviceWithoutDelegate(orObjectService: $or),
			contract: ['id' => '2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33', 'originId' => 'o-1'],
			ensureUuid: true
		);

		$this->assertSame('2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33', $seen);
	}//end testEnsureUuidDoesNotOverrideAnExistingIdentity()

	/**
	 * ...and must still mint when there is genuinely no identity, rather than
	 * passing null and creating an unkeyed row.
	 *
	 * @return void
	 */
	public function testEnsureUuidStillMintsWhenThereIsNoIdentity(): void {
		$or = ObjectServiceMockBuilder::make($this);
		$saved = ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-2'], 'c-2');
		$seen = null;

		$or->method('saveObject')->willReturnCallback(
			function (...$args) use ($saved, &$seen): mixed {
				$seen = ($args[3] ?? null);

				return $saved;
			}
		);

		$this->persistContract(
			service: $this->serviceWithoutDelegate(orObjectService: $or),
			contract: ['originId' => 'o-2'],
			ensureUuid: true
		);

		$this->assertNotNull($seen);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$seen);
	}//end testEnsureUuidStillMintsWhenThereIsNoIdentity()

	/**
	 * Call the private assignContractIdentity().
	 *
	 * @param array $contract The contract payload (by reference).
	 *
	 * @return array The mutated payload.
	 */
	private function assignContractIdentity(array $contract): array {
		$method = new \ReflectionMethod(SynchronizationService::class, 'assignContractIdentity');
		$method->setAccessible(true);

		$instance = (new \ReflectionClass(SynchronizationService::class))->newInstanceWithoutConstructor();
		$method->invokeArgs($instance, [&$contract]);

		return $contract;
	}//end assignContractIdentity()

	/**
	 * THE ROOT OF THE DUPLICATE-PER-RERUN DEFECT. Both sites in
	 * synchronizeContract() read `if (($contract['uuid'] ?? null) === null)` and
	 * minted. A contract loaded back from OpenRegister carries `id`, never
	 * `uuid`, so that was true on EVERY rerun: a fresh identity each time, and
	 * every downstream writer then faithfully created a row under it.
	 *
	 * Fixing persist(), persistContract(), persistBulk() and updateFromArray()
	 * changed nothing measurable, because the identity was already gone before
	 * any of them ran — three live runs still added one contract per updated
	 * object (8237 -> 8334 -> 8431 -> 8528).
	 *
	 * @return void
	 */
	public function testExistingIdBecomesTheWriteIdentity(): void {
		$out = $this->assignContractIdentity(
			contract: ['id' => '2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33', 'originId' => 'o-1']
		);

		$this->assertSame('2ad6c9a4-45ba-44b4-b2e7-d01b6b236a33', $out['uuid']);
	}//end testExistingIdBecomesTheWriteIdentity()

	/**
	 * An explicit uuid is left alone.
	 *
	 * @return void
	 */
	public function testAnExplicitUuidSurvives(): void {
		$out = $this->assignContractIdentity(contract: ['uuid' => 'keep-me', 'id' => 'other']);

		$this->assertSame('keep-me', $out['uuid']);
	}//end testAnExplicitUuidSurvives()

	/**
	 * A contract with genuinely no identity still gets one — that is what these
	 * sites exist for, and a first run must still be able to create.
	 *
	 * @return void
	 */
	public function testAContractWithNoIdentityStillGetsOne(): void {
		$out = $this->assignContractIdentity(contract: ['originId' => 'o-2']);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$out['uuid']);
	}//end testAContractWithNoIdentityStillGetsOne()

	/**
	 * A legacy numeric id is not an OpenRegister identifier, so it must be
	 * replaced by a minted uuid rather than written under.
	 *
	 * @return void
	 */
	public function testANumericIdIsReplacedNotAdopted(): void {
		$out = $this->assignContractIdentity(contract: ['id' => 4438, 'originId' => 'o-3']);

		$this->assertNotSame('4438', (string)$out['uuid']);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$out['uuid']);
	}//end testANumericIdIsReplacedNotAdopted()
}//end class
