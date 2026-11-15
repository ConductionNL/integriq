<?php

/**
 * ExchangeJobService: the exact job record a request and a resubmission
 * store, and the refusal when the store fails.
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
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\Integriq\Service\Job\ExchangeJobAction;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden job records of ExchangeJobService.
 */
class ExchangeJobRecordGoldenTest extends TestCase {

	/**
	 * Every object handed to saveObject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Whether saveObject throws.
	 *
	 * @var boolean
	 */
	private bool $failSave = false;

	/**
	 * Build the service over a store double.
	 *
	 * @return ExchangeJobService The service.
	 */
	private function service(): ExchangeJobService {
		$store = $this->createMock(ORObjectService::class);
		$store->method('saveObject')->willReturnCallback(
			function ($object = [], ...$rest) {
				if ($this->failSave === true) {
					throw new RuntimeException('disk full');
				}

				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('new-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
		$store->method('findAll')->willReturn([]);

		return new ExchangeJobService($store, new ExchangeTargetCatalogue(), $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * Drop the clock-dependent field after checking it is set.
	 *
	 * @param array<string, mixed> $job The stored job.
	 *
	 * @return array<string, mixed> The job without requestedAt.
	 */
	private function withoutClock(array $job): array {
		$this->assertIsString($job['requestedAt']);
		$this->assertNotFalse(strtotime($job['requestedAt']));
		unset($job['requestedAt']);
		return $job;

	}//end withoutClock()

	/**
	 * A request without a name or mapping stores the full default record.
	 *
	 * @return void
	 */
	public function testARequestStoresTheFullJobRecord(): void {
		$event = new ExchangeJobRequestedEvent(
			ownerApp: 'learniq',
			target: 'bron-rod',
			direction: 'export',
			ownerRef: 'school-advies/7',
			scope: ['berichtsoort' => 'schooladvies'],
			requestedBy: 'admin'
		);

		$created = $this->service()->handleRequest(event: $event);

		$this->assertSame('new-1', $created->getUuid());
		$this->assertSame('new-1', $event->getJobId());
		$this->assertNull($event->getRefusal());
		$this->assertSame(
			[
				'name' => (new ExchangeTargetCatalogue())->label(target: 'bron-rod') . ' export',
				'description' => 'Data exchange job of learniq for target bron-rod.',
				'jobClass' => ExchangeJobAction::class,
				'arguments' => [],
				'interval' => 0,
				'isEnabled' => true,
				'singleRun' => true,
				'exchangeTarget' => 'bron-rod',
				'exchangeDirection' => 'export',
				'ownerApp' => 'learniq',
				'ownerRef' => 'school-advies/7',
				'exchangeScope' => ['berichtsoort' => 'schooladvies'],
				'exchangeStatus' => 'queued',
				'requestedBy' => 'admin',
				'exchangeMapping' => 'learniq-bron-rod-export-schooladvies',
			],
			$this->withoutClock(job: $this->saved[0])
		);
		$this->assertSame('requestedAt', array_keys($this->saved[0])[14]);

	}//end testARequestStoresTheFullJobRecord()

	/**
	 * A named request for an owner without a default mapping stores no mapping.
	 *
	 * @return void
	 */
	public function testANamedRequestWithoutADefaultStoresNoMapping(): void {
		$event = new ExchangeJobRequestedEvent(ownerApp: 'dossiq', target: 'oso', direction: 'export', name: 'Mine');

		$this->service()->handleRequest(event: $event);

		$this->assertSame('Mine', $this->saved[0]['name']);
		$this->assertArrayNotHasKey('exchangeMapping', $this->saved[0]);

	}//end testANamedRequestWithoutADefaultStoresNoMapping()

	/**
	 * A failing store refuses the request and returns null.
	 *
	 * @return void
	 */
	public function testAFailingStoreRefusesTheRequest(): void {
		$this->failSave = true;
		$event = new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'oso', direction: 'export');

		$this->assertNull($this->service()->handleRequest(event: $event));
		$this->assertSame(
			['code' => 'store-failed', 'reason' => 'Integriq could not store the job: disk full'],
			$event->getRefusal()
		);
		$this->assertNull($event->getJobId());

	}//end testAFailingStoreRefusesTheRequest()

	/**
	 * A resubmission stores the full record, scoped to the one record.
	 *
	 * @return void
	 */
	public function testAResubmissionStoresTheFullJobRecord(): void {
		$this->service()->createResubmission(
			original: [
				'ownerApp' => 'learniq',
				'exchangeTarget' => 'bron-rod',
				'exchangeDirection' => 'export',
				'exchangeScope' => ['berichtsoort' => 'inschrijving', 'recordIds' => ['a', 'b']],
				'exchangeMapping' => 'learniq-bron-rod-export-learner',
				'name' => 'ROD groep 3',
				'ownerRef' => 'ignored',
				'requestedBy' => 'ignored',
			],
			recordId: 'b',
			ownerRef: 'learner-profile/b',
			rejectionId: 'rej-1',
			actor: 'coordinator'
		);

		$this->assertSame(
			[
				'name' => 'Resubmission: ROD groep 3',
				'description' => 'Data exchange job of learniq for target bron-rod.',
				'jobClass' => ExchangeJobAction::class,
				'arguments' => [],
				'interval' => 0,
				'isEnabled' => true,
				'singleRun' => true,
				'exchangeTarget' => 'bron-rod',
				'exchangeDirection' => 'export',
				'ownerApp' => 'learniq',
				'ownerRef' => 'learner-profile/b',
				'exchangeScope' => ['berichtsoort' => 'inschrijving', 'recordIds' => ['b']],
				'exchangeStatus' => 'queued',
				'requestedBy' => 'coordinator',
				'exchangeMapping' => 'learniq-bron-rod-export-learner',
				'resubmissionOf' => 'rej-1',
			],
			$this->withoutClock(job: $this->saved[0])
		);

	}//end testAResubmissionStoresTheFullJobRecord()

	/**
	 * A resubmission of a bare record falls back to an empty scope, an empty
	 * name and the default mapping when the stored mapping is not a string.
	 *
	 * @return void
	 */
	public function testABareResubmissionFallsBackToDefaults(): void {
		$this->service()->createResubmission(
			original: [
				'ownerApp' => 'learniq',
				'exchangeTarget' => 'bron-rod',
				'exchangeDirection' => 'export',
				'exchangeScope' => 'not-a-scope',
				'exchangeMapping' => 42,
			],
			recordId: 'r-9',
			ownerRef: 'ref',
			rejectionId: 'rej-2',
			actor: 'actor'
		);

		$job = $this->saved[0];
		$this->assertSame('Resubmission: ', $job['name']);
		$this->assertSame(['recordIds' => ['r-9']], $job['exchangeScope']);
		$this->assertSame('learniq-bron-rod-export-learner', $job['exchangeMapping']);

	}//end testABareResubmissionFallsBackToDefaults()
}//end class
