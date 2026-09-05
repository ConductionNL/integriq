<?php

/**
 * Unit tests for FlowGraphMigrationService.
 *
 * The core semantics under test: the migration is additive (steps stay) and
 * idempotent (a second run skips what the first wrote), a refused flow is
 * reported and left untouched, and the rollback refuses to strip the graph
 * off a flow whose steps are gone.
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

use OCA\Integriq\Service\FlowGraphMigrationService;
use OCA\Integriq\Service\FlowStepsToGraphTranslator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the live-object half of the steps-to-graph migration.
 */
class FlowGraphMigrationServiceTest extends TestCase {

	/**
	 * The OR persistence double.
	 *
	 * @var OrObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * The service under test.
	 *
	 * @var FlowGraphMigrationService
	 */
	private FlowGraphMigrationService $service;

	/**
	 * Build the service with a real translator and a persistence double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				if (is_array($parameters) === false || $parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		$this->orObjectService = $this->createMock(OrObjectService::class);

		$this->service = new FlowGraphMigrationService(
			translator: new FlowStepsToGraphTranslator(l10n: $l10n),
			orObjectService: $this->orObjectService,
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * A flow object with the given record.
	 *
	 * @param string $uuid The uuid.
	 * @param array $data The record.
	 *
	 * @return ObjectEntity The object.
	 */
	private function flow(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;

	}//end flow()

	/**
	 * A migratable flow gets its graph written IN PLACE with steps kept —
	 * and a flow already carrying nodes is skipped, which is what makes a
	 * second run of the same migration a no-op.
	 *
	 * @return void
	 */
	public function testMigrateWritesAdditivelyAndSkipsMigrated(): void {
		$legacy = $this->flow(
			uuid: 'f-1',
			data: [
				'name' => 'legacy',
				'steps' => [['order' => 10, 'type' => 'mapping', 'configRef' => 'map-1', 'onError' => 'stop']],
			]
		);
		$alreadyMigrated = $this->flow(
			uuid: 'f-2',
			data: ['name' => 'done', 'steps' => [], 'nodes' => [['id' => 'trigger']]]
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [$legacy, $alreadyMigrated]]);

		$written = null;
		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function ($object, $register, $schema, $uuid) use (&$written): ObjectEntity {
					$this->assertSame('integriq', $register);
					$this->assertSame('flow', $schema);
					$this->assertSame('f-1', $uuid);
					$written = $object;

					return new ObjectEntity();
				}
			);

		$report = $this->service->migrate(apply: true);

		$this->assertSame(FlowGraphMigrationService::MIGRATED, $report[0]['action']);
		$this->assertSame(FlowGraphMigrationService::SKIPPED, $report[1]['action'], 'The second run of the migration skips what the first wrote');

		$this->assertNotEmpty($written['nodes']);
		$this->assertNotEmpty($written['edges']);
		$this->assertNotEmpty($written['steps'], 'steps stays beside the graph — it is the rollback shape');

	}//end testMigrateWritesAdditivelyAndSkipsMigrated()

	/**
	 * A dry run reports without writing.
	 *
	 * @return void
	 */
	public function testDryRunWritesNothing(): void {
		$legacy = $this->flow(
			uuid: 'f-1',
			data: [
				'name' => 'legacy',
				'steps' => [['order' => 10, 'type' => 'mapping', 'configRef' => 'map-1', 'onError' => 'stop']],
			]
		);
		$this->orObjectService->method('findAll')->willReturn(['results' => [$legacy]]);
		$this->orObjectService->expects($this->never())->method('saveObject');

		$report = $this->service->migrate(apply: false);

		$this->assertSame(FlowGraphMigrationService::MIGRATED, $report[0]['action']);

	}//end testDryRunWritesNothing()

	/**
	 * A refused flow is reported with its reasons and left untouched.
	 *
	 * @return void
	 */
	public function testRefusedFlowIsReportedAndUntouched(): void {
		$duplicated = $this->flow(
			uuid: 'f-1',
			data: [
				'name' => 'dupes',
				'steps' => [
					['order' => 20, 'type' => 'mapping', 'configRef' => 'a', 'onError' => 'stop'],
					['order' => 20, 'type' => 'mapping', 'configRef' => 'b', 'onError' => 'stop'],
				],
			]
		);
		$this->orObjectService->method('findAll')->willReturn(['results' => [$duplicated]]);
		$this->orObjectService->expects($this->never())->method('saveObject');

		$report = $this->service->migrate(apply: true);

		$this->assertSame(FlowGraphMigrationService::REFUSED, $report[0]['action']);
		$this->assertNotSame([], $report[0]['reasons']);

	}//end testRefusedFlowIsReportedAndUntouched()

	/**
	 * The rollback strips nodes/edges where steps remain, and refuses a flow
	 * whose graph is its ONLY shape.
	 *
	 * @return void
	 */
	public function testRollbackStripsGraphAndRefusesSteplessFlows(): void {
		$migrated = $this->flow(
			uuid: 'f-1',
			data: [
				'name' => 'migrated',
				'steps' => [['order' => 10, 'type' => 'mapping', 'configRef' => 'map-1', 'onError' => 'stop']],
				'nodes' => [['id' => 'trigger']],
				'edges' => [['id' => 'e']],
			]
		);
		$graphOnly = $this->flow(
			uuid: 'f-2',
			data: ['name' => 'graph-only', 'nodes' => [['id' => 'trigger']], 'edges' => []]
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [$migrated, $graphOnly]]);

		$written = null;
		$this->orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				static function ($object) use (&$written): ObjectEntity {
					$written = $object;

					return new ObjectEntity();
				}
			);

		$report = $this->service->rollback(apply: true);

		$this->assertSame(FlowGraphMigrationService::ROLLED_BACK, $report[0]['action']);
		$this->assertArrayNotHasKey('nodes', $written);
		$this->assertArrayNotHasKey('edges', $written);
		$this->assertNotEmpty($written['steps']);

		$this->assertSame(FlowGraphMigrationService::REFUSED, $report[1]['action'], 'A graph-only flow must not be stripped to nothing');

	}//end testRollbackStripsGraphAndRefusesSteplessFlows()
}//end class
