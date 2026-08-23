<?php

/**
 * Tests for the integriq:rule-to-flow command.
 *
 * The endpoint is a REQUIRED argument, and
 * {@see testTheEndpointArgumentIsRequired()} pins that: a rule carries no
 * register, schema or method of its own, so a command that defaulted the scope
 * would emit a trigger nobody chose.
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

use OCA\Integriq\Command\RuleToFlow;
use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Service\RuleToFlowGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Command\RuleToFlow
 */
class RuleToFlowTest extends TestCase {

	/**
	 * The generator double.
	 *
	 * @var RuleToFlowGenerator&MockObject
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

		$this->generator = $this->createMock(RuleToFlowGenerator::class);
		$this->tester = new CommandTester(new RuleToFlow(generator: $this->generator));

	}//end setUp()

	/**
	 * A generated document is printed, with the DISABLED fact stated.
	 *
	 * @return void
	 */
	public function testAGeneratedDocumentIsPrintedAndSaysItIsDisabled(): void {
		$this->generator->method('generateFor')->willReturn(
			[
				'name' => 'Publish tender downstream (generated from rule)',
				'enabled' => false,
				'trigger' => 'object.created',
			]
		);

		$status = $this->tester->execute(['rule' => 'publish-tender', 'endpoint' => 'create-tender']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString('DISABLED', $display);
		$this->assertStringContainsString('nothing was created', $display);
		$this->assertStringContainsString('"trigger": "object.created"', $display);

	}//end testAGeneratedDocumentIsPrintedAndSaysItIsDisabled()

	/**
	 * With `--json` the document is the only thing on stdout.
	 *
	 * @return void
	 */
	public function testJsonModeEmitsTheDocumentAlone(): void {
		$this->generator->method('generateFor')->willReturn(['name' => 'x', 'enabled' => false]);

		$status = $this->tester->execute(['rule' => 'x', 'endpoint' => 'e', '--json' => true]);
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
				subject: 'rule',
				message: 'cannot be migrated yet',
				reasons: [
					'timing "before": an object trigger fires AFTER the change is committed.',
					'conditions read "headers.x-tenant".',
				]
			)
		);

		$status = $this->tester->execute(['rule' => 'before-rule', 'endpoint' => 'create-tender']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString('cannot be migrated yet', $display);
		$this->assertStringContainsString('before', $display);
		$this->assertStringContainsString('x-tenant', $display);

	}//end testARefusalNamesEveryUnsupportedFeatureAndFails()

	/**
	 * The endpoint argument is required, not defaulted.
	 *
	 * @return void
	 */
	public function testTheEndpointArgumentIsRequired(): void {
		$this->expectException(ConsoleRuntimeException::class);

		$this->tester->execute(['rule' => 'publish-tender']);

	}//end testTheEndpointArgumentIsRequired()
}//end class
