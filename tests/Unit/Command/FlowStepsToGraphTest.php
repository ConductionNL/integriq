<?php

/**
 * Unit tests for the integriq:flow:steps-to-graph occ command.
 *
 * The core semantics under test: dry run by default, `--apply` writes,
 * `--rollback` drives the other direction, refusals are printed with their
 * reasons and fail the command so an operator cannot read a partial
 * migration as a complete one.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\FlowStepsToGraph;
use OCA\Integriq\Service\FlowGraphMigrationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Command\FlowStepsToGraph
 */
class FlowStepsToGraphTest extends TestCase {

	/**
	 * The migration double.
	 *
	 * @var FlowGraphMigrationService&MockObject
	 */
	private $migration;

	/**
	 * The command tester.
	 *
	 * @var CommandTester
	 */
	private CommandTester $tester;

	/**
	 * Wire the command over a migration double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->migration = $this->createMock(FlowGraphMigrationService::class);
		$this->tester = new CommandTester(new FlowStepsToGraph(migration: $this->migration));

	}//end setUp()

	/**
	 * The default invocation is a dry run of the forward migration.
	 *
	 * @return void
	 */
	public function testDefaultIsForwardDryRun(): void {
		$this->migration->expects($this->once())->method('migrate')
			->with(false)
			->willReturn(
				[
					['id' => 'f-1', 'name' => 'one', 'action' => FlowGraphMigrationService::MIGRATED, 'reasons' => []],
				]
			);
		$this->migration->expects($this->never())->method('rollback');

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('Dry run', $this->tester->getDisplay());
		$this->assertStringContainsString('[migrated] one (f-1)', $this->tester->getDisplay());

	}//end testDefaultIsForwardDryRun()

	/**
	 * `--apply` writes, and a clean report succeeds.
	 *
	 * @return void
	 */
	public function testApplyWritesForward(): void {
		$this->migration->expects($this->once())->method('migrate')
			->with(true)
			->willReturn(
				[
					['id' => 'f-1', 'name' => 'one', 'action' => FlowGraphMigrationService::MIGRATED, 'reasons' => []],
					['id' => 'f-2', 'name' => 'two', 'action' => FlowGraphMigrationService::SKIPPED, 'reasons' => []],
				]
			);

		$exit = $this->tester->execute(['--apply' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringNotContainsString('Dry run', $this->tester->getDisplay());
		$this->assertStringContainsString('2 flow(s) inspected, 0 refused.', $this->tester->getDisplay());

	}//end testApplyWritesForward()

	/**
	 * `--rollback --apply` drives the other direction.
	 *
	 * @return void
	 */
	public function testRollbackDrivesTheOtherDirection(): void {
		$this->migration->expects($this->once())->method('rollback')
			->with(true)
			->willReturn(
				[
					['id' => 'f-1', 'name' => 'one', 'action' => FlowGraphMigrationService::ROLLED_BACK, 'reasons' => []],
				]
			);
		$this->migration->expects($this->never())->method('migrate');

		$exit = $this->tester->execute(['--rollback' => true, '--apply' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('[rolled_back] one (f-1)', $this->tester->getDisplay());

	}//end testRollbackDrivesTheOtherDirection()

	/**
	 * A refused flow prints its reasons and fails the command.
	 *
	 * @return void
	 */
	public function testRefusalPrintsReasonsAndFails(): void {
		$this->migration->method('migrate')->willReturn(
			[
				[
					'id' => 'f-1',
					'name' => 'dupes',
					'action' => FlowGraphMigrationService::REFUSED,
					'reasons' => ['Duplicate step order 20.'],
				],
			]
		);

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('Duplicate step order 20.', $this->tester->getDisplay());
		$this->assertStringContainsString('1 flow(s) inspected, 1 refused.', $this->tester->getDisplay());

	}//end testRefusalPrintsReasonsAndFails()
}//end class
