<?php

/**
 * Unit tests for the MigrateFlowStepsToGraph repair step.
 *
 * The core semantics under test: the step applies the migration, reports the
 * counts, logs refusals per flow, and never lets a failure escape into the
 * upgrade that runs it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateFlowStepsToGraph;
use OCA\Integriq\Service\FlowGraphMigrationService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Repair\MigrateFlowStepsToGraph
 */
class MigrateFlowStepsToGraphTest extends TestCase {

	/**
	 * The step names itself for occ output.
	 *
	 * @return void
	 */
	public function testGetNameIsNonEmpty(): void {
		$step = new MigrateFlowStepsToGraph(
			container: $this->createMock(ContainerInterface::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertNotSame('', $step->getName());

	}//end testGetNameIsNonEmpty()

	/**
	 * A pass with work reports migrated and refused counts, and logs each
	 * refusal with its reasons.
	 *
	 * @return void
	 */
	public function testRunAppliesAndReportsCounts(): void {
		$migration = $this->createMock(FlowGraphMigrationService::class);
		$migration->expects($this->once())->method('migrate')
			->with(true)
			->willReturn(
				[
					['id' => 'f-1', 'name' => 'one', 'action' => FlowGraphMigrationService::MIGRATED, 'reasons' => []],
					['id' => 'f-2', 'name' => 'two', 'action' => FlowGraphMigrationService::SKIPPED, 'reasons' => []],
					['id' => 'f-3', 'name' => 'three', 'action' => FlowGraphMigrationService::REFUSED, 'reasons' => ['Duplicate step order 20.']],
				]
			);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FlowGraphMigrationService::class)->willReturn($migration);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with(
				$this->stringContains('flow refused'),
				$this->callback(static fn (array $ctx): bool => $ctx['flowId'] === 'f-3')
			);

		$reported = null;
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->willReturnCallback(
				static function (string $message) use (&$reported): void {
					$reported = $message;
				}
			);

		$step = new MigrateFlowStepsToGraph(container: $container, logger: $logger);
		$step->run($output);

		$this->assertStringContainsString('1 migrated, 1 refused', (string)$reported);

	}//end testRunAppliesAndReportsCounts()

	/**
	 * A pass with nothing to do stays silent.
	 *
	 * @return void
	 */
	public function testRunWithNothingToDoStaysSilent(): void {
		$migration = $this->createMock(FlowGraphMigrationService::class);
		$migration->method('migrate')->willReturn(
			[
				['id' => 'f-1', 'name' => 'one', 'action' => FlowGraphMigrationService::SKIPPED, 'reasons' => []],
			]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($migration);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$step = new MigrateFlowStepsToGraph(
			container: $container,
			logger: $this->createMock(LoggerInterface::class)
		);
		$step->run($output);

	}//end testRunWithNothingToDoStaysSilent()

	/**
	 * A failure inside the migration is logged, never thrown — an escaping
	 * exception here would abort the upgrade.
	 *
	 * @return void
	 */
	public function testRunNeverThrows(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('container broken'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('flows stay on steps[]'), $this->anything());

		$step = new MigrateFlowStepsToGraph(container: $container, logger: $logger);
		$step->run($this->createMock(IOutput::class));

		// Reaching this line IS the assertion: nothing escaped.
		$this->assertTrue(true);

	}//end testRunNeverThrows()
}//end class
