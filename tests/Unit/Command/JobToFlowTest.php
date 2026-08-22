<?php

/**
 * Tests for the openconnector:job-to-flow command.
 *
 * The command's whole contract is that it WRITES NOTHING and that a refusal is
 * visible: {@see testARefusalNamesEveryUnsupportedFeatureAndFails()} asserts the
 * non-zero exit AND the reasons on stdout, because a migration tool that exits 0
 * on a job it could not express reads exactly like one that did.
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

use OCA\Integriq\Command\JobToFlow;
use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Service\JobToFlowGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Command\JobToFlow
 */
class JobToFlowTest extends TestCase {

	/**
	 * The generator double.
	 *
	 * @var JobToFlowGenerator&MockObject
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

		$this->generator = $this->createMock(JobToFlowGenerator::class);
		$this->tester = new CommandTester(new JobToFlow(generator: $this->generator));

	}//end setUp()

	/**
	 * A generated document is printed, with the DISABLED fact stated.
	 *
	 * @return void
	 */
	public function testAGeneratedDocumentIsPrintedAndSaysItIsDisabled(): void {
		$this->generator->method('generateFor')->willReturn(
			[
				'name' => 'Nightly TenderNed pull (generated from job)',
				'enabled' => false,
				'trigger' => 'schedule',
				'cron' => '0 * * * *',
			]
		);

		$status = $this->tester->execute(['job' => 'nightly-tenderned-pull']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString('DISABLED', $display);
		$this->assertStringContainsString('nothing was created', $display);
		$this->assertStringContainsString('"enabled": false', $display);
		$this->assertStringContainsString('"cron": "0 * * * *"', $display);

	}//end testAGeneratedDocumentIsPrintedAndSaysItIsDisabled()

	/**
	 * With `--json` the document is the only thing on stdout.
	 *
	 * @return void
	 */
	public function testJsonModeEmitsTheDocumentAlone(): void {
		$this->generator->method('generateFor')->willReturn(['name' => 'x', 'enabled' => false]);

		$status = $this->tester->execute(['job' => 'x', '--json' => true]);
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
			new EntityNotMigratableException(
				subject: 'job',
				message: 'cannot be migrated yet',
				reasons: [
					'interval 420 seconds: a five-field cron is absolute wall-clock time.',
					'userId "alice": a scheduled flow runs as its OWNER.',
				]
			)
		);

		$status = $this->tester->execute(['job' => 'seven-minute-job']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('cannot be migrated yet', $display);
		$this->assertStringContainsString('420 seconds', $display);
		$this->assertStringContainsString('alice', $display);

	}//end testARefusalNamesEveryUnsupportedFeatureAndFails()
}//end class
