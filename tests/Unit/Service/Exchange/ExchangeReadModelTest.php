<?php

/**
 * ExchangeReadModel: owner filtering, bounds and enrichment.
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

use OCA\Integriq\Service\Exchange\ExchangeErrorCodeCatalogue;
use OCA\Integriq\Service\Exchange\ExchangeReadModel;
use OCA\Integriq\Service\Exchange\ExchangeTargetCatalogue;
use OCA\Integriq\Service\Exchange\ExchangeTargetDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-007 and REQ-008 scenarios.
 */
class ExchangeReadModelTest extends TestCase {

	/**
	 * Rows by schema.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $rows = [];

	/**
	 * Every findAll config received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The read model under test.
	 *
	 * @var ExchangeReadModel
	 */
	private ExchangeReadModel $model;

	/**
	 * Set up an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objects = $this->createMock(ORObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest): array {
				$this->queries[] = $config;
				$filters = $config['filters'] ?? [];
				$matches = [];
				foreach (($this->rows[$filters['schema'] ?? ''] ?? []) as $row) {
					$keep = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($row->getObject()[$key] ?? null) !== $value) {
							$keep = false;
						}
					}

					if ($keep === true) {
						$matches[] = $row;
					}
				}

				return ['results' => $matches, 'total' => count($matches)];
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ...$rest): ObjectEntity {
				foreach ($this->rows['job'] ?? [] as $row) {
					if ($row->getUuid() === $id) {
						return $row;
					}
				}

				throw new \RuntimeException('not found');
			}
		);

		$dispatcher = $this->createMock(ExchangeTargetDispatcher::class);
		$dispatcher->method('handledDirections')->willReturnCallback(
			static fn (string $target): array => ($target === 'bron-rod') ? ['export'] : []
		);

		$this->model = new ExchangeReadModel(
			$objects,
			new ExchangeTargetCatalogue(),
			$dispatcher,
			new ExchangeErrorCodeCatalogue($objects, $this->createMock(LoggerInterface::class))
		);

		$this->rows['job'] = [
			$this->entity('job-1', ['ownerApp' => 'learniq', 'exchangeTarget' => 'bron-rod', 'exchangeDirection' => 'export', 'exchangeStatus' => 'partial', 'name' => 'ROD']),
			$this->entity('job-2', ['ownerApp' => 'dossiq', 'exchangeTarget' => 'bron-rod', 'exchangeStatus' => 'queued']),
		];
		$this->rows['sync_item_dead_letter'] = [
			$this->entity('rej-1', ['ownerApp' => 'learniq', 'exchangeJob' => 'job-1', 'exchangeTarget' => 'bron-rod', 'status' => 'failed', 'errorCode' => 'BRON-102']),
			$this->entity('rej-2', ['ownerApp' => 'learniq', 'exchangeJob' => 'job-1', 'exchangeTarget' => 'bron-rod', 'status' => 'discarded', 'errorCode' => 'BRON-999']),
		];
		$this->rows['job_log'] = [
			$this->entity('log-1', ['jobId' => 'job-1', 'level' => 'INFO', 'message' => 'old', 'created' => '2026-10-01T08:00:00+02:00']),
			$this->entity('log-2', ['jobId' => 'job-1', 'level' => 'WARNING', 'message' => '1 of 2', 'created' => '2026-10-01T09:00:00+02:00']),
		];

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
	 * An app lists only its own jobs, with open rejection counts, bounded.
	 *
	 * @return void
	 */
	public function testAnAppListsOnlyItsOwnJobs(): void {
		$page = $this->model->listJobs('learniq', [], 5000, -3);

		$this->assertSame(1, $page['total']);
		$this->assertSame('job-1', $page['results'][0]['id']);
		$this->assertSame('DUO ROD', $page['results'][0]['targetLabel']);
		$this->assertSame(1, $page['results'][0]['openRejections']);
		$this->assertSame(200, $this->queries[0]['limit'], 'A list read is capped at 200.');
		$this->assertSame(0, $this->queries[0]['offset']);
		$this->assertSame('learniq', $this->queries[0]['filters']['ownerApp']);

	}//end testAnAppListsOnlyItsOwnJobs()

	/**
	 * Another app's job is not returned; an own job carries rejections and its last log.
	 *
	 * @return void
	 */
	public function testAnotherAppsJobIsNotReturned(): void {
		$this->assertNull($this->model->getJob('learniq', 'job-2'));
		$this->assertNull($this->model->getJob('learniq', 'job-missing'));

		$job = $this->model->getJob('learniq', 'job-1');

		$this->assertCount(2, $job['rejections']);
		$this->assertSame(1, $job['openRejections']);
		$this->assertSame('1 of 2', $job['lastLog']['message']);

	}//end testAnotherAppsJobIsNotReturned()

	/**
	 * A catalogued code gets its label; an uncatalogued one falls back.
	 *
	 * @return void
	 */
	public function testRejectionLabels(): void {
		$rows = $this->model->listRejections('learniq')['results'];

		$this->assertSame('Ontbrekende geboortedatum', $rows[0]['errorLabel']);
		$this->assertSame('blocking', $rows[0]['severity']);
		$this->assertSame('BRON-999', $rows[1]['errorLabel']);

	}//end testRejectionLabels()

	/**
	 * The target list says which directions are handled.
	 *
	 * @return void
	 */
	public function testTargetsSayWhatIsHandled(): void {
		$targets = array_column($this->model->targets(), null, 'id');

		$this->assertTrue($targets['bron-rod']['handled']['export']);
		$this->assertFalse($targets['surfconext']['handled']['sync']);
		$this->assertCount(14, $targets);

	}//end testTargetsSayWhatIsHandled()
}//end class
