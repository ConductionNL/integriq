<?php

/**
 * Unit tests for RegistrationStore.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registration;

use OCA\Integriq\Service\Registration\RegistrationStore;
use OCA\Integriq\Service\SystemWrite;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Targets and records are read and written in integriq's register, as the system.
 */
#[CoversClass(RegistrationStore::class)]
#[UsesClass(SystemWrite::class)]
class RegistrationStoreTest extends TestCase {

	/**
	 * An entity holding data.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An object service mock with only these methods.
	 *
	 * @param array<int,string> $methods The methods.
	 *
	 * @return OrObjectService
	 */
	private function objects(array $methods): OrObjectService {
		return $this->getMockBuilder(className: OrObjectService::class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
	}//end objects()

	/**
	 * A target is found by slug with RBAC off, and a near match is not taken.
	 *
	 * @return void
	 */
	public function testATargetIsFoundBySlug(): void {
		$objects = $this->objects(['findAll']);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'registrationTarget', 'slug' => 'zgw-milieu'], 'limit' => 1000], false, false)
			->willReturn(['results' => [$this->entity('a', ['slug' => 'zgw-milieu-2']), $this->entity('b', ['slug' => 'zgw-milieu', 'kind' => 'zgw'])]]);

		$this->assertSame(['slug' => 'zgw-milieu', 'kind' => 'zgw'], (new RegistrationStore($objects))->findTarget('zgw-milieu'));
	}//end testATargetIsFoundBySlug()

	/**
	 * A record matches on target, app and reference all three.
	 *
	 * @return void
	 */
	public function testARecordMatchesTargetAppAndReference(): void {
		$objects = $this->objects(['findAll']);
		$objects->method('findAll')->willReturn([
			$this->entity('r-1', ['target' => 't', 'app' => 'dossiq', 'submissionReference' => 'OF-1']),
			$this->entity('r-2', ['target' => 't', 'app' => 'portaliq', 'submissionReference' => 'OF-1', 'state' => 'done']),
		]);

		$store = new RegistrationStore($objects);

		$this->assertSame('r-2', $store->findRecord('t', 'portaliq', 'OF-1')['uuid']);
		$this->assertNull($store->findRecord('t', 'portaliq', 'OF-2'));
	}//end testARecordMatchesTargetAppAndReference()

	/**
	 * A record is saved in the record schema without its null values.
	 *
	 * @return void
	 */
	public function testARecordIsSavedWithoutNulls(): void {
		$objects = $this->objects(['saveObject']);
		$objects->expects($this->once())->method('saveObject')
			->with(['target' => 't', 'state' => 'started'], 'integriq', 'registrationRecord', 'r-1')
			->willReturn($this->entity('r-1', []));

		$this->assertSame('r-1', (new RegistrationStore($objects))->saveRecord(['target' => 't', 'state' => 'started', 'lastError' => null], 'r-1'));
	}//end testARecordIsSavedWithoutNulls()

	/**
	 * Every target is listed.
	 *
	 * @return void
	 */
	public function testEveryTargetIsListed(): void {
		$objects = $this->objects(['findAll']);
		$objects->method('findAll')->willReturn(['results' => [$this->entity('a', ['slug' => 'a']), 'not an entity', $this->entity('b', ['slug' => 'b'])]]);

		$this->assertSame([['slug' => 'a'], ['slug' => 'b']], (new RegistrationStore($objects))->findTargets());
	}//end testEveryTargetIsListed()
}//end class
