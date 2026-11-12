<?php

/**
 * Golden master of the three to-flow console commands.
 *
 * Records, per command, the definition (name, description, arguments,
 * options) and the exit code and output of four runs: a generated flow as
 * text and as JSON, the command's own refusal, and the other command's
 * refusal type (which must not be caught). Set RECORD_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\JobToFlow;
use OCA\Integriq\Command\RuleToFlow;
use OCA\Integriq\Command\SynchronizationToFlow;
use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Exception\SynchronizationNotMigratableException;
use OCA\Integriq\Service\JobToFlowGeneratorInterface;
use OCA\Integriq\Service\RuleToFlowGeneratorInterface;
use OCA\Integriq\Service\SynchronizationFlowGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * Recorded definitions and runs of the to-flow commands.
 *
 * @covers \OCA\Integriq\Command\JobToFlow
 * @covers \OCA\Integriq\Command\RuleToFlow
 * @covers \OCA\Integriq\Command\SynchronizationToFlow
 * @uses   \OCA\Integriq\Exception\EntityNotMigratableException
 * @uses   \OCA\Integriq\Exception\SynchronizationNotMigratableException
 */
class ToFlowCommandsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/to-flow-commands.json';

	/**
	 * The commands with their generator interface and arguments.
	 *
	 * @return array<string, array{0: class-string, 1: class-string, 2: array<string, string>}>
	 */
	private function commands(): array {
		return [
			'job' => [JobToFlow::class, JobToFlowGeneratorInterface::class, ['job' => 'job-1']],
			'rule' => [RuleToFlow::class, RuleToFlowGeneratorInterface::class, ['rule' => 'rule-1', 'endpoint' => 'ep-1']],
			'synchronization' => [SynchronizationToFlow::class, SynchronizationFlowGeneratorInterface::class, ['synchronization' => 'sync-1']],
		];
	}//end commands()

	/**
	 * Run one command with a generator that returns a flow or throws.
	 *
	 * @param array{0: class-string, 1: class-string, 2: array<string, string>} $command The command row.
	 * @param array<string, mixed>                                              $run     What the generator does, and the input.
	 *
	 * @return array<string, mixed>
	 */
	private function runOne(array $command, array $run): array {
		$calls     = [];
		$generator = $this->createMock($command[1]);
		$generator->method('generateFor')->willReturnCallback(
			function (...$args) use (&$calls, $run) {
				$calls[] = $args;
				if (isset($run['throw']) === true) {
					throw $run['throw'];
				}

				return ['name' => 'Generated flow', 'enabled' => false, 'nodes' => [['type' => 'x', 'url' => 'a/b']]];
			}
		);

		$subject = new $command[0]($generator);
		$tester  = new CommandTester($subject);
		try {
			$code = $tester->execute(array_merge($command[2], $run['input'] ?? []));
		} catch (Throwable $e) {
			return ['thrown' => get_class($e), 'calls' => $calls];
		}

		return ['code' => $code, 'display' => $tester->getDisplay(true), 'calls' => $calls];
	}//end runOne()

	/**
	 * Every command against the recorded fixture, run by run.
	 *
	 * @return void
	 */
	public function testCommandsMatchTheRecording(): void {
		$found = [];
		foreach ($this->commands() as $key => $command) {
			$subject  = new $command[0]($this->createMock($command[1]));
			$definition = $subject->getDefinition();
			$found[$key . ':definition'] = [
				'name' => $subject->getName(),
				'description' => $subject->getDescription(),
				'arguments' => array_map(static fn ($a) => [$a->getName(), $a->isRequired(), $a->getDescription()], array_values($definition->getArguments())),
				'options' => array_map(static fn ($o) => [$o->getName(), $o->getShortcut(), $o->acceptValue(), $o->getDescription()], array_values($definition->getOptions())),
			];
			$own   = ($key === 'synchronization') ? new SynchronizationNotMigratableException('cannot migrate', ['a reason', 'another']) : new EntityNotMigratableException('job', 'cannot migrate', ['a reason', 'another']);
			$other = ($key === 'synchronization') ? new EntityNotMigratableException('job', 'other refusal', ['r']) : new SynchronizationNotMigratableException('other refusal', ['r']);
			$found[$key . ':text']    = $this->runOne(command: $command, run: []);
			$found[$key . ':json']    = $this->runOne(command: $command, run: ['input' => ['--json' => true]]);
			$found[$key . ':refusal'] = $this->runOne(command: $command, run: ['throw' => $own]);
			$found[$key . ':other']   = $this->runOne(command: $command, run: ['throw' => $other]);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, $found[$name], $name);
		}
	}//end testCommandsMatchTheRecording()
}//end class
