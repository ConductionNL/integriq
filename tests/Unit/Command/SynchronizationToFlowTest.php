<?php

/**
 * Tests for the integriq:synchronization-to-flow command.
 *
 * The command's whole contract is that it WRITES NOTHING and that a refusal is
 * visible: {@see testARefusalNamesEveryUnsupportedFeatureAndFails()} asserts the
 * non-zero exit AND the reasons on stdout, because a migration tool that exits 0
 * on a synchronization it could not express reads exactly like one that did.
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

use OCA\Integriq\Command\SynchronizationToFlow;
use OCA\Integriq\Exception\SynchronizationNotMigratableException;
use OCA\Integriq\Service\SynchronizationFlowGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Command\SynchronizationToFlow
 */
class SynchronizationToFlowTest extends TestCase {

	/**
	 * The generator double.
	 *
	 * @var SynchronizationFlowGenerator&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $generator;

	/**
	 * The command tester.
	 *
	 * @var CommandTester
	 */
	private CommandTester $tester;

	/**
	 * Wire the command over a generator double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->generator = $this->createMock(SynchronizationFlowGenerator::class);
		$this->tester = new CommandTester(new SynchronizationToFlow(generator: $this->generator));

	}//end setUp()

	/**
	 * A generated document is printed, with the DISABLED fact stated.
	 *
	 * @return void
	 */
	public function testAGeneratedDocumentIsPrintedAndSaysItIsDisabled(): void {
		$this->generator->method('generateFor')->willReturn(
			['name' => 'TenderNed datasets (generated from synchronization)', 'enabled' => false]
		);

		$status = $this->tester->execute(['synchronization' => 'tenderned-datasets']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString('DISABLED', $display);
		$this->assertStringContainsString('nothing was created', $display);
		$this->assertStringContainsString('"enabled": false', $display);

	}//end testAGeneratedDocumentIsPrintedAndSaysItIsDisabled()

	/**
	 * With `--json` the document is the only thing on stdout.
	 *
	 * @return void
	 */
	public function testJsonModeEmitsTheDocumentAlone(): void {
		$this->generator->method('generateFor')->willReturn(['name' => 'x', 'enabled' => false]);

		$status = $this->tester->execute(['synchronization' => 'x', '--json' => true]);
		$decoded = json_decode(trim($this->tester->getDisplay()), true);

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame(['name' => 'x', 'enabled' => false], $decoded);

	}//end testJsonModeEmitsTheDocumentAlone()

	/**
	 * A refusal exits non-zero and lists every reason.
	 *
	 * @return void
	 */
	public function testARefusalNamesEveryUnsupportedFeatureAndFails(): void {
		$this->generator->method('generateFor')->willThrowException(
			new SynchronizationNotMigratableException(
				message: 'cannot be migrated yet',
				reasons: ['syncMode "incremental": no step carries the cursor watermark.', 'actions: declared.']
			)
		);

		$status = $this->tester->execute(['synchronization' => 'incremental-sync']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('cannot be migrated yet', $display);
		$this->assertStringContainsString('incremental', $display);
		$this->assertStringContainsString('actions', $display);

	}//end testARefusalNamesEveryUnsupportedFeatureAndFails()
}//end class
