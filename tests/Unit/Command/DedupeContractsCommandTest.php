<?php

/**
 * Unit tests for running the contract dedupe command.
 *
 * The plan itself is pinned by DedupeContractsTest. These tests pin what the
 * command does with it: a dry run deletes nothing, an apply deletes in
 * system context and counts what OpenRegister REPORTS deleted (not the plan),
 * and a shortfall exits non-zero so the run is never mistaken for a cleanup.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\DedupeContracts;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Dry run, apply, shortfall and synchronization discovery.
 *
 * @covers \OCA\Integriq\Command\DedupeContracts
 */
class DedupeContractsCommandTest extends TestCase {

	private SynchronizationContractServiceInterface&MockObject $contracts;

	private MockObject $objects;

	protected function setUp(): void {
		parent::setUp();
		$this->contracts = $this->createMock(SynchronizationContractServiceInterface::class);
		$this->objects = ObjectServiceMockBuilder::make($this);
	}//end setUp()

	/**
	 * A dry run reports the duplicates, deletes nothing and exits 0.
	 *
	 * @return void
	 */
	public function testADryRunReportsAndDeletesNothing(): void {
		$this->givenContracts(['sync-1' => $this->duplicatedRows()]);
		$this->objects->expects($this->never())->method('deleteObjects');

		$tester = $this->runCommand(['--synchronization' => 'sync-1']);

		$this->assertSame(0, $tester->getStatusCode());
		$display = $tester->getDisplay();
		$this->assertStringContainsString('DRY RUN — nothing will be deleted across 1 synchronization(s).', $display);
		$this->assertStringContainsString('sync-1: 3 contract(s), 1 duplicate(s) to remove, 2 origin(s) kept', $display);
		$this->assertStringContainsString('Duplicates planned:   1', $display);
		$this->assertStringContainsString('Re-run with --apply to delete them.', $display);
	}//end testADryRunReportsAndDeletesNothing()

	/**
	 * An apply deletes the duplicates without RBAC or multitenancy and reports what was removed.
	 *
	 * @return void
	 */
	public function testAnApplyDeletesInSystemContextAndReportsTheResult(): void {
		$this->givenContracts(['sync-1' => $this->duplicatedRows()]);
		$this->objects->expects($this->once())
			->method('deleteObjects')
			->with(['c-old'], false, false)
			->willReturn(['deleted_uuids' => ['c-old'], 'skipped_uuids' => []]);

		$tester = $this->runCommand(['--synchronization' => 'sync-1', '--apply' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('Applying across 1 synchronization(s).', $tester->getDisplay());
		$this->assertStringContainsString('Duplicates DELETED:   1', $tester->getDisplay());
		$this->assertStringContainsString('Skipped by OpenRegister: 0', $tester->getDisplay());
	}//end testAnApplyDeletesInSystemContextAndReportsTheResult()

	/**
	 * When OpenRegister removes fewer than planned the run fails and says the duplicates remain.
	 *
	 * @return void
	 */
	public function testAShortfallFailsTheRun(): void {
		$this->givenContracts(['sync-1' => $this->duplicatedRows()]);
		$this->objects->method('deleteObjects')->willReturn(['deleted_uuids' => [], 'skipped_uuids' => ['c-old']]);

		$tester = $this->runCommand(['--synchronization' => 'sync-1', '--apply' => true]);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('Skipped by OpenRegister: 1', $tester->getDisplay());
		$this->assertStringContainsString('Planned 1 deletions but OpenRegister removed 0.', $tester->getDisplay());
	}//end testAShortfallFailsTheRun()

	/**
	 * Without a filter every synchronization is visited; one without duplicates is not reported.
	 *
	 * @return void
	 */
	public function testEverySynchronizationIsVisitedWithoutAFilter(): void {
		$this->objects->expects($this->once())
			->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'synchronization']])
			->willReturn([
				'results' => [
					['id' => 'sync-1'],
					ObjectServiceMockBuilder::objectEntity($this, [], 'sync-2'),
					['name' => 'no id'],
				],
			]);
		$this->givenContracts([
			'sync-1' => $this->duplicatedRows(),
			'sync-2' => [ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-9', 'sourceLastChecked' => '2026-01-01'], 'c-9')],
		]);

		$tester = $this->runCommand([]);

		$display = $tester->getDisplay();
		$this->assertStringContainsString('across 2 synchronization(s).', $display);
		$this->assertStringContainsString('sync-1: 3 contract(s)', $display);
		$this->assertStringNotContainsString('sync-2:', $display);
		$this->assertStringContainsString('Contracts scanned:    4', $display);
		$this->assertStringContainsString('Synchronizations hit: 1', $display);
	}//end testEverySynchronizationIsVisitedWithoutAFilter()

	/**
	 * With no synchronizations at all the command says so and exits 0.
	 *
	 * @return void
	 */
	public function testNoSynchronizationsIsSaidPlainly(): void {
		$this->objects->method('findAll')->willReturn([]);
		$this->contracts->expects($this->never())->method('findAllObjects');

		$tester = $this->runCommand([]);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('No synchronizations with contracts found.', $tester->getDisplay());
	}//end testNoSynchronizationsIsSaidPlainly()

	/**
	 * Three contracts on two origins, one of them duplicated (c-old is the older one).
	 *
	 * @return array<int,object>
	 */
	private function duplicatedRows(): array {
		return [
			ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1', 'sourceLastChecked' => '2026-01-01T00:00:00Z'], 'c-old'),
			ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-1', 'sourceLastChecked' => '2026-02-01T00:00:00Z'], 'c-new'),
			ObjectServiceMockBuilder::objectEntity($this, ['originId' => 'o-2', 'sourceLastChecked' => '2026-01-15T00:00:00Z'], 'c-other'),
		];
	}//end duplicatedRows()

	/**
	 * Answer contracts per synchronization id.
	 *
	 * @param array<string,array<int,object>> $bySynchronization The rows per synchronization.
	 *
	 * @return void
	 */
	private function givenContracts(array $bySynchronization): void {
		$this->contracts->method('findAllObjects')->willReturnCallback(
			static fn (array $filters): array => ($bySynchronization[$filters['synchronizationId']] ?? [])
		);
	}//end givenContracts()

	/**
	 * Run the command.
	 *
	 * @param array<string,mixed> $input The options.
	 *
	 * @return CommandTester
	 */
	private function runCommand(array $input): CommandTester {
		$tester = new CommandTester(new DedupeContracts($this->contracts, $this->objects));
		$tester->execute($input);

		return $tester;
	}//end runCommand()
}//end class
