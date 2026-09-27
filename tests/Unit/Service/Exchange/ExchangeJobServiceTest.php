<?php

/**
 * ExchangeJobService: creating, refusing and migrating exchange jobs, and
 * storing an app's mapping.
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

use OCA\Integriq\Action\ExchangeJobAction;
use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-001 and REQ-002 scenarios.
 */
class ExchangeJobServiceTest extends TestCase {

	/**
	 * Every saveObject call: object, schema, uuid.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Rows findAll returns, keyed by schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * The service under test.
	 *
	 * @var ExchangeJobService
	 */
	private ExchangeJobService $service;

	/**
	 * Set up a store double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$store = $this->createMock(ORObjectService::class);
		$store->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null, ...$rest) {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return $this->entity(uuid: $uuid ?? ('new-' . count($this->saved)), data: $object);
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest) {
				$filters = $config['filters'] ?? [];
				$matches = [];
				foreach (($this->rows[$filters['schema'] ?? ''] ?? []) as $row) {
					$data = $row->getObject();
					$keep = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($data[$key] ?? null) !== $value) {
							$keep = false;
						}
					}

					if ($keep === true) {
						$matches[] = $row;
					}
				}

				// OpenRegister's findAll answers with a flat list.
				return $matches;
			}
		);

		$this->service = new ExchangeJobService($store, new ExchangeTargetCatalogue(), $this->createMock(LoggerInterface::class));

	}//end setUp()

	/**
	 * Build an entity.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;

	}//end entity()

	/**
	 * An app asks integriq to carry a ROD export.
	 *
	 * @return void
	 */
	public function testAnAppAsksIntegriqToCarryARodExport(): void {
		$this->rows['mapping'] = [$this->entity('m-1', ['slug' => 'learniq-bron-rod-export-learner'])];
		$event = new ExchangeJobRequestedEvent(
			ownerApp: 'learniq',
			target: 'bron-rod',
			direction: 'export',
			ownerRef: 'school-advies/7',
			scope: ['berichtsoort' => 'schooladvies'],
			mappingSlug: 'learniq-bron-rod-export-learner',
			requestedBy: 'admin'
		);

		$created = $this->service->handleRequest(event: $event);

		$this->assertNotNull($created);
		$this->assertSame($created->getUuid(), $event->getJobId());
		$job = $this->saved[0]['object'];
		$this->assertSame('job', $this->saved[0]['schema']);
		$this->assertSame(ExchangeJobAction::class, $job['jobClass']);
		$this->assertSame('bron-rod', $job['exchangeTarget']);
		$this->assertSame('learniq', $job['ownerApp']);
		$this->assertTrue($job['singleRun']);
		$this->assertTrue($job['isEnabled']);
		$this->assertSame('queued', $job['exchangeStatus']);
		$this->assertSame('learniq-bron-rod-export-learner', $job['exchangeMapping']);

	}//end testAnAppAsksIntegriqToCarryARodExport()

	/**
	 * An unknown target is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownTargetIsRefused(): void {
		$event = new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'fax', direction: 'export');

		$this->assertNull($this->service->handleRequest(event: $event));
		$this->assertSame('target-unknown', $event->getRefusal()['code']);
		$this->assertSame([], $this->saved);

	}//end testAnUnknownTargetIsRefused()

	/**
	 * A direction the target does not support is refused.
	 *
	 * @return void
	 */
	public function testADirectionTheTargetDoesNotSupportIsRefused(): void {
		$event = new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'leerplicht', direction: 'import');

		$this->service->handleRequest(event: $event);

		$this->assertSame('direction-unsupported', $event->getRefusal()['code']);

	}//end testADirectionTheTargetDoesNotSupportIsRefused()

	/**
	 * A missing owner and a missing mapping are refused.
	 *
	 * @return void
	 */
	public function testMissingOwnerAndMissingMappingAreRefused(): void {
		$noOwner = new ExchangeJobRequestedEvent(ownerApp: '', target: 'oso', direction: 'export');
		$this->service->handleRequest(event: $noOwner);
		$this->assertSame('owner-missing', $noOwner->getRefusal()['code']);

		$noMapping = new ExchangeJobRequestedEvent(ownerApp: 'learniq', target: 'oso', direction: 'export', mappingSlug: 'learniq-nonexistent');
		$this->service->handleRequest(event: $noMapping);
		$this->assertSame('mapping-missing', $noMapping->getRefusal()['code']);

	}//end testMissingOwnerAndMissingMappingAreRefused()

	/**
	 * A finished learniq job is migrated disabled, with its history.
	 *
	 * @return void
	 */
	public function testAFinishedLearniqJobIsMigrated(): void {
		$event = new ExchangeJobRequestedEvent(
			ownerApp: 'learniq',
			target: 'bron-rod',
			direction: 'export',
			history: [
				'legacyId' => 'legacy-1',
				'status' => 'succeeded',
				'requestedAt' => '2026-09-01T08:00:00+02:00',
				'finishedAt' => '2026-09-01T08:05:00+02:00',
				'result' => ['recordsProcessed' => 3, 'recordsAccepted' => 3, 'recordsRejected' => 0],
			]
		);

		$created = $this->service->handleRequest(event: $event);

		$this->assertNotNull($created);
		$job = $this->saved[0]['object'];
		$this->assertFalse($job['isEnabled'], 'A finished migrated job never runs.');
		$this->assertSame('succeeded', $job['exchangeStatus']);
		$this->assertSame('legacy-1', $job['migratedFrom']);
		$this->assertSame('2026-09-01T08:05:00+02:00', $job['finishedAt']);
		$this->assertSame(3, $job['exchangeResult']['recordsAccepted']);

	}//end testAFinishedLearniqJobIsMigrated()

	/**
	 * A job that was waiting for parent review stays queued and runnable.
	 *
	 * @return void
	 */
	public function testAJobThatWasWaitingForParentReviewIsMigratedQueued(): void {
		$event = new ExchangeJobRequestedEvent(
			ownerApp: 'learniq',
			target: 'oso',
			direction: 'export',
			history: ['legacyId' => 'legacy-2', 'status' => 'pending-parent-review']
		);

		$this->service->handleRequest(event: $event);

		$job = $this->saved[0]['object'];
		$this->assertTrue($job['isEnabled']);
		$this->assertSame('queued', $job['exchangeStatus']);

	}//end testAJobThatWasWaitingForParentReviewIsMigratedQueued()

	/**
	 * The migration runs twice: the first job is returned, nothing is written.
	 *
	 * @return void
	 */
	public function testTheMigrationRunsTwice(): void {
		$this->rows['job'] = [$this->entity('job-first', ['ownerApp' => 'learniq', 'migratedFrom' => 'legacy-1'])];
		$event = new ExchangeJobRequestedEvent(
			ownerApp: 'learniq',
			target: 'bron-rod',
			direction: 'export',
			history: ['legacyId' => 'legacy-1', 'status' => 'failed']
		);

		$this->assertNull($this->service->handleRequest(event: $event));
		$this->assertSame('job-first', $event->getJobId());
		$this->assertSame([], $this->saved);

	}//end testTheMigrationRunsTwice()

	/**
	 * An app stores its own mapping; a foreign slug is refused.
	 *
	 * @return void
	 */
	public function testAnAppStoresOnlyItsOwnMappings(): void {
		$own = new ExchangeMappingRequestedEvent('learniq', 'learniq-custom-rod', 'Custom', 'Mine', ['voornamen' => 'givenName']);
		$this->service->handleMappingRequest(event: $own);
		$this->assertNotNull($own->getMappingId());
		$this->assertSame('mapping', $this->saved[0]['schema']);
		$this->assertSame('learniq-custom-rod', $this->saved[0]['object']['slug']);

		$foreign = new ExchangeMappingRequestedEvent('learniq', 'tenderned-to-tender', 'x', 'x', ['a' => 'b']);
		$this->service->handleMappingRequest(event: $foreign);
		$this->assertSame('slug-foreign', $foreign->getRefusal()['code']);

		$empty = new ExchangeMappingRequestedEvent('learniq', 'learniq-empty', 'x', 'x', []);
		$this->service->handleMappingRequest(event: $empty);
		$this->assertSame('mapping-empty', $empty->getRefusal()['code']);

	}//end testAnAppStoresOnlyItsOwnMappings()

	/**
	 * A resubmission job copies the original and scopes to one record.
	 *
	 * @return void
	 */
	public function testAResubmissionIsScopedToOneRecord(): void {
		$job = $this->service->createResubmission(
			original: [
				'ownerApp' => 'learniq',
				'exchangeTarget' => 'bron-rod',
				'exchangeDirection' => 'export',
				'exchangeScope' => ['berichtsoort' => 'inschrijving', 'recordIds' => ['a', 'b']],
				'exchangeMapping' => 'learniq-bron-rod-export-learner',
				'name' => 'ROD groep 3',
			],
			recordId: 'b',
			ownerRef: 'learner-profile/b',
			rejectionId: 'rej-1',
			actor: 'coordinator'
		);

		$data = $job->getObject();
		$this->assertSame(['b'], $data['exchangeScope']['recordIds']);
		$this->assertSame('inschrijving', $data['exchangeScope']['berichtsoort']);
		$this->assertSame('rej-1', $data['resubmissionOf']);
		$this->assertSame('queued', $data['exchangeStatus']);
		$this->assertSame('coordinator', $data['requestedBy']);

	}//end testAResubmissionIsScopedToOneRecord()
}//end class
