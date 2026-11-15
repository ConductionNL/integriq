<?php

/**
 * Unit tests for reopening a rejection, resubmitting one whose job is gone, and
 * translating a legacy status.
 *
 * A rejection the target rejects again must come back failed with a fresh
 * attempt, even when the stored attempt history is corrupt. A resubmission
 * needs its original job. A legacy status reads through the stored mapping
 * when there is one and never lands outside the three statuses a rejection
 * can have.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Exchange;

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
 * Reopen, resubmit without a job, and the status mapping.
 *
 * @covers \OCA\Integriq\Service\Exchange\ExchangeRejectionService
 */
class ExchangeRejectionReopenAndStatusTest extends TestCase {

	/**
	 * The stored objects by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $store = [];

	/**
	 * What `findAll` answers (a status mapping row list), or a failure to raise.
	 *
	 * @var array<int,mixed>|\Throwable
	 */
	private array|\Throwable $mappingRows = [];

	private ExchangeRejectionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->mappingRows = [];

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null, ...$rest) {
				$uuid = ($uuid ?? ($schema . '-' . (count($this->store) + 1)));
				$this->store[$uuid] = $object;
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
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest): array {
				if (($config['filters']['slug'] ?? '') !== ExchangeRejectionService::STATUS_MAPPING_SLUG) {
					return ['results' => []];
				}

				if ($this->mappingRows instanceof \Throwable) {
					throw $this->mappingRows;
				}

				return ['results' => $this->mappingRows];
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new ExchangeRejectionService(
			$objects,
			new ExchangeErrorCodeCatalogue($objects, $logger),
			new ExchangeJobService($objects, new ExchangeTargetCatalogue(), $logger)
		);
	}//end setUp()

	/**
	 * A rejected-again rejection is failed again with the new code, label and one more attempt.
	 *
	 * @return void
	 */
	public function testAReopenedRejectionIsFailedAgainWithAFreshAttempt(): void {
		$id = $this->failedRejection();
		$this->store[$id]['status'] = 'replayed';
		$this->store[$id]['attempts'] = 'corrupt';
		$retries = (int)($this->store[$id]['retryCount'] ?? 0);

		$row = $this->service->reopen($id, ['errorCode' => 'BRON-102', 'offendingFields' => ['geboorteDatum', ['nested']]]);

		$data = $row->getObject();
		$this->assertSame('failed', $data['status']);
		$this->assertSame('BRON-102', $data['errorCode']);
		$this->assertSame('Ontbrekende geboortedatum', $data['error']);
		$this->assertSame(['geboorteDatum'], $data['offendingFields']);
		$this->assertSame($retries + 1, $data['retryCount']);
		$this->assertCount(1, $data['attempts']);
		$this->assertSame('BRON-102', $data['attempts'][0]['error']);
	}//end testAReopenedRejectionIsFailedAgainWithAFreshAttempt()

	/**
	 * Reopening a rejection that does not exist answers null.
	 *
	 * @return void
	 */
	public function testReopeningAnUnknownRejectionIsNull(): void {
		$this->assertNull($this->service->reopen('nope', ['errorCode' => 'X']));
	}//end testReopeningAnUnknownRejectionIsNull()

	/**
	 * A rejection whose job no longer exists cannot be resubmitted, and stays failed.
	 *
	 * @return void
	 */
	public function testARejectionWhoseJobIsGoneCannotBeResubmitted(): void {
		$id = $this->failedRejection();
		unset($this->store['job-1']);

		try {
			$this->service->resubmit($id, 'coordinator');
			$this->fail('Expected the resubmission to be refused.');
		} catch (InvalidMessageStateException $e) {
			$this->assertSame('The exchange job of this rejection no longer exists, so it cannot be resubmitted.', $e->getMessage());
		}

		$this->assertSame('failed', $this->store[$id]['status']);
	}//end testARejectionWhoseJobIsGoneCannotBeResubmitted()

	/**
	 * A stored mapping overrides the shipped one, but never yields a status a rejection cannot have.
	 *
	 * @return void
	 */
	public function testAStoredMappingOverridesButCannotInventAStatus(): void {
		$this->mappingRows = [
			$this->entity('map-1', ['mapping' => ['corrected' => 'replayed', 'parked' => 'archived']]),
			$this->entity('map-2', ['mapping' => 'not a map']),
		];

		$this->assertSame('replayed', $this->service->translateStatus('corrected'));
		$this->assertSame('failed', $this->service->translateStatus('parked'));
		$this->assertSame('discarded', $this->service->translateStatus('waived'));
	}//end testAStoredMappingOverridesButCannotInventAStatus()

	/**
	 * An unreadable mapping falls back to the shipped table.
	 *
	 * @return void
	 */
	public function testAnUnreadableMappingFallsBackToTheShippedTable(): void {
		$this->mappingRows = new \RuntimeException('register offline');

		$this->assertSame('replayed', $this->service->translateStatus('resubmitted'));
		$this->assertSame('failed', $this->service->translateStatus('open'));
	}//end testAnUnreadableMappingFallsBackToTheShippedTable()

	/**
	 * Record a failed rejection on job-1.
	 *
	 * @return string The rejection id.
	 */
	private function failedRejection(): string {
		$this->store['job-1'] = [
			'name' => 'ROD groep 3',
			'ownerApp' => 'learniq',
			'exchangeTarget' => 'bron-rod',
			'exchangeDirection' => 'export',
			'exchangeScope' => ['berichtsoort' => 'inschrijving'],
		];

		return (string)$this->service->record(
			'job-1',
			'bron-rod',
			['recordId' => 'lp-9', 'sourceKind' => 'learner-profile', 'errorCode' => 'BRON-201'],
			'learniq'
		)->getUuid();
	}//end failedRejection()

	/**
	 * An OpenRegister object.
	 *
	 * @param string              $uuid The uuid.
	 * @param array<string,mixed> $data The body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()
}//end class
