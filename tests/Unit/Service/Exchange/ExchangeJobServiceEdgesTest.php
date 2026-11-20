<?php

/**
 * ExchangeJobService paths the scenario test leaves out: a mapping the store
 * refuses, an upserted mapping, a scope kind that is not a string, a migrated
 * error message and an empty job id.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Integriq\Service\Exchange\ExchangeJobRequest;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\Integriq\Tests\Support\LegacyObjectArgs;

/**
 * Edge paths of creating jobs and storing mappings.
 */
#[CoversClass(ExchangeJobService::class)]
#[UsesClass(ExchangeJobRequest::class)]
#[UsesClass(ExchangeTargetCatalogue::class)]
#[UsesClass(ExchangeJobRequestedEvent::class)]
#[UsesClass(ExchangeMappingRequestedEvent::class)]
class ExchangeJobServiceEdgesTest extends TestCase {

	/**
	 * The OpenRegister store double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $store;

	/**
	 * Set up a store double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = $this->createMock(ORObjectService::class);

	}//end setUp()

	/**
	 * The service over the store double.
	 *
	 * @return ExchangeJobService
	 */
	private function service(): ExchangeJobService {
		return new ExchangeJobService($this->store, new ExchangeTargetCatalogue(), $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * An entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
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
	 * A mapping the store cannot save is refused as store-failed, with no mapping id.
	 *
	 * @return void
	 */
	public function testAMappingTheStoreRefusesIsStoreFailed(): void {
		$this->store->method('findAll')->willReturn([]);
		$this->store->method('saveObject')->willThrowException(new RuntimeException('register locked'));

		$event = new ExchangeMappingRequestedEvent('learniq', 'learniq-custom-rod', 'Custom', 'Mine', ['voornamen' => 'givenName']);
		$this->service()->handleMappingRequest(event: $event);

		$this->assertSame(
			['code' => 'store-failed', 'reason' => 'Integriq could not store the mapping: register locked'],
			$event->getRefusal()
		);
		$this->assertNull($event->getMappingId());

	}//end testAMappingTheStoreRefusesIsStoreFailed()

	/**
	 * A mapping whose slug exists is saved over the existing object, found in a wrapped answer.
	 *
	 * @return void
	 */
	public function testAnExistingMappingIsUpdatedInPlace(): void {
		$this->store->method('findAll')->willReturn(['results' => ['not-an-entity', $this->entity('m-7', ['slug' => 'learniq-custom-rod'])]]);
		$this->store->expects($this->once())->method('saveObject')->with(...LegacyObjectArgs::with(method: 'saveObject', expected: [
			'object' => $this->callback(static fn (array $object): bool => $object['slug'] === 'learniq-custom-rod' && $object['passThrough'] === true),
			'register' => ExchangeJobService::REGISTER,
			'schema' => ExchangeJobService::SCHEMA_MAPPING,
			'uuid' => 'm-7',
		]))->willReturn($this->entity('m-7', []));

		$event = new ExchangeMappingRequestedEvent('learniq', 'learniq-custom-rod', 'Custom', 'Mine', ['voornamen' => 'givenName'], passThrough: true);
		$this->service()->handleMappingRequest(event: $event);

		$this->assertNull($event->getRefusal());
		$this->assertSame('m-7', $event->getMappingId());

	}//end testAnExistingMappingIsUpdatedInPlace()

	/**
	 * A berichtsoort that is not a string gets the catch-all default mapping.
	 *
	 * @return void
	 */
	public function testANonStringKindGetsTheCatchAllMapping(): void {
		$saved = [];
		$this->store->method('findAll')->willReturn([]);
		$this->store->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity('job-1', $object);
			}
		);

		$event = new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'bron-rod', direction: 'export', scope: ['berichtsoort' => ['schooladvies']]);
		$this->service()->handleRequest(event: $event);

		$this->assertSame('learniq-bron-rod-export-learner', $saved[0]['exchangeMapping']);

	}//end testANonStringKindGetsTheCatchAllMapping()

	/**
	 * A migrated failed job keeps its error message; an empty one is not written.
	 *
	 * @return void
	 */
	public function testAMigratedJobKeepsItsErrorMessage(): void {
		$saved = [];
		$this->store->method('findAll')->willReturn([]);
		$this->store->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->entity('job-' . count($saved), $object);
			}
		);

		$service = $this->service();
		$service->handleRequest(
			event: new ExchangeJobRequestedEvent(
				ownerApp: 'learniq',
				target: 'bron-rod',
				direction: 'export',
				history: ['legacyId' => 'legacy-3', 'status' => 'failed', 'errorMessage' => 'BRON unreachable']
			)
		);
		$service->handleRequest(
			event: new ExchangeJobRequestedEvent(
				ownerApp: 'learniq',
				target: 'bron-rod',
				direction: 'export',
				history: ['legacyId' => 'legacy-4', 'status' => 'failed', 'errorMessage' => '']
			)
		);

		$this->assertSame('BRON unreachable', $saved[0]['exchangeError']);
		$this->assertFalse($saved[0]['isEnabled']);
		$this->assertArrayNotHasKey('exchangeError', $saved[1]);

	}//end testAMigratedJobKeepsItsErrorMessage()

	/**
	 * An empty job id finds nothing without asking the store; a store error is null too.
	 *
	 * @return void
	 */
	public function testAnEmptyOrMissingJobIsNull(): void {
		$this->store->expects($this->once())->method('find')->willThrowException(new RuntimeException('not found'));
		$service = $this->service();

		$this->assertNull($service->findJob(jobId: ''));
		$this->assertNull($service->findJob(jobId: 'job-gone'));

	}//end testAnEmptyOrMissingJobIsNull()
}//end class
