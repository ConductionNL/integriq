<?php

/**
 * ExchangeRejectionService: the correction loop on dead letters.
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

use InvalidArgumentException;
use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\Exchange\ExchangeErrorCodeCatalogue;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-002, REQ-006 and REQ-007 scenarios.
 */
class ExchangeRejectionServiceTest extends TestCase {

	/**
	 * Stored objects keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Schema per stored uuid.
	 *
	 * @var array<string, string>
	 */
	private array $schemaOf = [];

	/**
	 * The service under test.
	 *
	 * @var ExchangeRejectionService
	 */
	private ExchangeRejectionService $service;

	/**
	 * Set up an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null, ...$rest) {
				$uuid = $uuid ?? ($schema . '-' . (count($this->store) + 1));
				$this->store[$uuid] = $object;
				$this->schemaOf[$uuid] = (string)$schema;
				return $this->entity(uuid: $uuid, data: $object);
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ...$rest) {
				if (isset($this->store[$id]) === false) {
					throw new \RuntimeException('not found');
				}

				return $this->entity(uuid: (string)$id, data: $this->store[$id]);
			}
		);
		$objects->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$logger = $this->createMock(LoggerInterface::class);
		$jobs = new ExchangeJobService($objects, new ExchangeTargetCatalogue(), $logger);
		$this->service = new ExchangeRejectionService($objects, new ExchangeErrorCodeCatalogue($objects, $logger), $jobs);

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
	 * Store a ROD job and one failed rejection of it.
	 *
	 * @return string The rejection's uuid.
	 */
	private function failedRejection(): string {
		$this->store['job-1'] = [
			'name' => 'ROD groep 3',
			'ownerApp' => 'learniq',
			'exchangeTarget' => 'bron-rod',
			'exchangeDirection' => 'export',
			'exchangeScope' => ['berichtsoort' => 'inschrijving'],
		];
		$row = $this->service->record(
			'job-1',
			'bron-rod',
			['recordId' => 'lp-9', 'sourceKind' => 'learner-profile', 'errorCode' => 'BRON-102', 'offendingFields' => ['geboorteDatum', 42]],
			'learniq'
		);

		return $row->getUuid();

	}//end failedRejection()

	/**
	 * A rejection stores code, field names and owner, never data or the target's text.
	 *
	 * @return void
	 */
	public function testARejectionStoresNoPersonalData(): void {
		$id = $this->failedRejection();
		$row = $this->store[$id];

		$this->assertSame('job-1', $row['exchangeJob']);
		$this->assertSame('failed', $row['status']);
		$this->assertSame('BRON-102', $row['errorCode']);
		$this->assertSame(['geboorteDatum'], $row['offendingFields']);
		$this->assertSame('learner-profile/lp-9', $row['ownerRef']);
		$this->assertSame('learniq', $row['ownerApp']);
		$this->assertSame([], $row['payload']);
		$this->assertSame('Ontbrekende geboortedatum', $row['error'], 'The label comes from the shipped catalogue.');

	}//end testARejectionStoresNoPersonalData()

	/**
	 * Resubmit a rejection: a single-record job, and the rejection is replayed.
	 *
	 * @return void
	 */
	public function testResubmitARejection(): void {
		$id = $this->failedRejection();

		$outcome = $this->service->resubmit($id, 'coordinator');

		$this->assertSame('replayed', $this->store[$id]['status']);
		$this->assertSame('coordinator', $this->store[$id]['replayedBy']);
		$job = $this->store[$outcome['jobId']];
		$this->assertSame(['lp-9'], $job['exchangeScope']['recordIds']);
		$this->assertSame($id, $job['resubmissionOf']);
		$this->assertTrue($job['isEnabled']);

	}//end testResubmitARejection()

	/**
	 * A rejection that is not failed cannot be resubmitted; an unknown id is not found.
	 *
	 * @return void
	 */
	public function testResubmitRefusesAReplayedRejection(): void {
		$id = $this->failedRejection();
		$this->service->resubmit($id, 'coordinator');

		$this->expectException(InvalidMessageStateException::class);
		$this->service->resubmit($id, 'coordinator');

	}//end testResubmitRefusesAReplayedRejection()

	/**
	 * An unknown rejection id is refused.
	 *
	 * @return void
	 */
	public function testResubmitRefusesAnUnknownRejection(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->resubmit('nope', 'coordinator');

	}//end testResubmitRefusesAnUnknownRejection()

	/**
	 * The resubmission is rejected again: the same row is failed again.
	 *
	 * @return void
	 */
	public function testTheResubmissionIsRejectedAgain(): void {
		$id = $this->failedRejection();
		$this->service->resubmit($id, 'coordinator');
		$count = count($this->store);

		$this->service->reopen($id, ['errorCode' => 'BRON-101', 'offendingFields' => ['bsn']]);

		$this->assertSame($count, count($this->store), 'No second rejection row.');
		$this->assertSame('failed', $this->store[$id]['status']);
		$this->assertSame('BRON-101', $this->store[$id]['errorCode']);
		$this->assertSame(1, $this->store[$id]['retryCount']);
		$this->assertCount(2, $this->store[$id]['attempts']);

	}//end testTheResubmissionIsRejectedAgain()

	/**
	 * Waive without a reason is refused; with one it is discarded and stamped.
	 *
	 * @return void
	 */
	public function testWaiveNeedsAReason(): void {
		$id = $this->failedRejection();
		$entry = $this->service->find($id);
		$this->assertNotNull($entry);

		try {
			$this->service->waive($entry, 'coordinator', '  ');
			$this->fail('An empty reason must be refused.');
		} catch (InvalidArgumentException $exception) {
			$this->assertSame('failed', $this->store[$id]['status']);
		}

		$this->service->waive($entry, 'coordinator', 'The pupil left the school.');
		$this->assertSame('discarded', $this->store[$id]['status']);
		$this->assertSame('The pupil left the school.', $this->store[$id]['discardReason']);
		$this->assertSame('coordinator', $this->store[$id]['discardedBy']);

	}//end testWaiveNeedsAReason()

	/**
	 * A migrated waived rejection is discarded with its reason; statuses translate.
	 *
	 * @return void
	 */
	public function testAMigratedRejectionTranslatesItsStatus(): void {
		$job = $this->entity('job-9', ['ownerApp' => 'learniq', 'exchangeTarget' => 'bron-rod']);

		$row = $this->service->migrate($job, [
			'recordId' => 'lp-1',
			'sourceKind' => 'learner-profile',
			'errorCode' => 'BRON-201',
			'status' => 'waived',
			'waivedBy' => 'admin',
			'waiveReason' => 'Double enrolment corrected at the other school.',
		]);

		$data = $row->getObject();
		$this->assertSame('discarded', $data['status']);
		$this->assertSame('Double enrolment corrected at the other school.', $data['discardReason']);
		$this->assertSame('learniq', $data['ownerApp']);
		$this->assertSame('failed', $this->service->translateStatus('corrected'));
		$this->assertSame('replayed', $this->service->translateStatus('accepted'));
		$this->assertSame('failed', $this->service->translateStatus('something-else'));

	}//end testAMigratedRejectionTranslatesItsStatus()

	/**
	 * A rejection that is not an exchange rejection is not found.
	 *
	 * @return void
	 */
	public function testAPlainDeadLetterIsNotAnExchangeRejection(): void {
		$this->store['dl-plain'] = ['synchronization' => 'sync-1', 'status' => 'failed'];

		$this->assertNull($this->service->find('dl-plain'));

	}//end testAPlainDeadLetterIsNotAnExchangeRejection()
}//end class
