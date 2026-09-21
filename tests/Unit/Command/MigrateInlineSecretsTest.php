<?php

/**
 * Tests for the integriq:migrate-inline-secrets command glue.
 *
 * These cover the COMMAND's responsibilities only — the dry-run/real-run dispatch,
 * the fail-closed drive, and (item 3 of ocon#151 phase C) persisting the TRUE
 * post-run Phase D gate into appconfig after a real run. The mint→verify→null
 * safety contract itself is proven at the service level in
 * {@see \OCA\Integriq\Tests\Unit\Service\Security\InlineSecretMigrationExecutorTest}.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\MigrateInlineSecrets;
use OCA\Integriq\Repair\RecordInlineSecretMigrationStatus;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Integriq\Command\MigrateInlineSecrets
 */
class MigrateInlineSecretsTest extends TestCase {

	/**
	 * @var InlineSecretMigrationPlanner&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $planner;

	/**
	 * @var InlineSecretMigrationExecutor&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $executor;

	/**
	 * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appConfig;

	/**
	 * @var CommandTester
	 */
	private CommandTester $tester;

	/**
	 * Set up the command with mocked planner, executor and appconfig.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->planner = $this->createMock(InlineSecretMigrationPlanner::class);
		$this->executor = $this->createMock(InlineSecretMigrationExecutor::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$command = new MigrateInlineSecrets($this->planner, $this->executor, $this->appConfig);
		$this->tester = new CommandTester($command);
	}//end setUp()

	/**
	 * A real run drives the executor and persists the TRUE post-run gate to
	 * appconfig — clean='1' only when the post-run re-plan is clean.
	 *
	 * @return void
	 */
	public function testRealRunWritesCleanPhaseDGate(): void {
		// migrateEverything(), matching the dry-run's planEverything(): a real run
		// that only drove `source` reported wouldMigrate for `sender_identity` and
		// migrated 0 (integriq#2104 blocker 2). The Phase D gate below is still
		// read from the SOURCE run alone.
		$this->executor->expects($this->once())
			->method('migrateEverything')
			->with(1000)
			->willReturn(
				[
					'schemas' => [
						'source' => [
							'objects' => [],
							'totalObjects' => 1,
							'migrated' => 1,
							'failed' => 0,
							'blocked' => 0,
							'skipped' => 0,
							'postRun' => ['clean' => true, 'pending' => 0, 'manual' => 0],
						],
					],
					'migrated' => 1,
					'failed' => 0,
					'blocked' => 0,
					'skipped' => 0,
				]
			);

		$written = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written) {
				$written[$key] = ['app' => $app, 'value' => $value];
				return true;
			}
		);

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertSame(
			'1',
			$written[RecordInlineSecretMigrationStatus::KEY_CLEAN]['value'],
			'A clean post-run must write the Phase D gate to 1.'
		);
		$this->assertSame(InlineSecretMigrationPlanner::APP_ID, $written[RecordInlineSecretMigrationStatus::KEY_CLEAN]['app']);
		$this->assertSame('0', $written[RecordInlineSecretMigrationStatus::KEY_PENDING]['value']);
	}//end testRealRunWritesCleanPhaseDGate()

	/**
	 * A non-`source` failure is visible in the human output, not only the exit code.
	 *
	 * The finding this covers was "a `sender_identity` failure exits non-zero
	 * next to a table showing nothing wrong" — `renderResult()` speaks the
	 * `source` vocabulary and prints per-object rows for `source` alone. The fix
	 * was `renderSchemaOutcomes()`, and it shipped without a test: both existing
	 * fixtures are single-schema `source` maps, so nothing rendered a second row
	 * (integriq#2104 review 5266971176).
	 *
	 * Asserting the schema NAME and its failure count reach the output, so a
	 * refactor that drops the call, reorders it so `$rows === []` short-circuits,
	 * or mis-keys the counters puts this red rather than staying green.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function testANonSourceFailureIsVisibleInTheTable(): void {
		$this->executor->method('migrateEverything')->willReturn(
			[
				'schemas' => [
					'source' => [
						'objects' => [],
						'totalObjects' => 0,
						'migrated' => 0,
						'failed' => 0,
						'blocked' => 0,
						'skipped' => 0,
						'postRun' => ['clean' => true, 'pending' => 0, 'manual' => 0],
					],
					'sender_identity' => [
						'objects' => [],
						'totalObjects' => 1,
						'migrated' => 0,
						'failed' => 1,
						'blocked' => 0,
						'skipped' => 0,
						'postRun' => ['clean' => false, 'pending' => 1, 'manual' => 0],
					],
				],
				'migrated' => 0,
				'failed' => 1,
				'blocked' => 0,
				'skipped' => 0,
			]
		);

		$exit = $this->tester->execute([]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::FAILURE, $exit, 'A failed field must exit non-zero.');
		$this->assertStringContainsString('Per schema', $display, 'The per-schema section must render.');
		$this->assertStringContainsString(
			'sender_identity',
			$display,
			'The failing schema must be named in the human output, not only in --json.'
		);

	}//end testANonSourceFailureIsVisibleInTheTable()

	/**
	 * A real run that leaves pending/blocked fields keeps the gate closed ('0')
	 * and exits non-zero when a field failed.
	 *
	 * @return void
	 */
	public function testRealRunKeepsGateClosedWhenNotClean(): void {
		$this->executor->method('migrateEverything')->willReturn(
			[
				'schemas' => [
					'source' => [
						'objects' => [
							['uuid' => 's1', 'name' => 'S1', 'organisation' => null, 'fields' => [
								['field' => 'apikey', 'provider' => 'generic-apikey', 'outcome' => 'blocked', 'reason' => 'no-organisation', 'credentialId' => null],
							]],
						],
						'totalObjects' => 1,
						'migrated' => 0,
						'failed' => 1,
						'blocked' => 1,
						'skipped' => 0,
						'postRun' => ['clean' => false, 'pending' => 1, 'manual' => 0],
					],
				],
				'migrated' => 0,
				'failed' => 1,
				'blocked' => 1,
				'skipped' => 0,
			]
		);

		$written = [];
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written) {
				$written[$key] = $value;
				return true;
			}
		);

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::FAILURE, $exit, 'A failed field must exit non-zero.');
		$this->assertSame('0', $written[RecordInlineSecretMigrationStatus::KEY_CLEAN], 'A not-clean post-run keeps the gate closed.');
	}//end testRealRunKeepsGateClosedWhenNotClean()

	/**
	 * The real run fails closed when the executor refuses (old/absent broker):
	 * FAILURE, an upgrade hint, and NO appconfig write.
	 *
	 * @return void
	 */
	public function testRealRunFailsClosedWhenExecutorRefuses(): void {
		$this->executor->method('migrateEverything')->willThrowException(
			new \RuntimeException('CredentialBrokerService::mint() is missing. Nothing was rewritten.')
		);

		$this->appConfig->expects($this->never())->method('setValueString');

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('Refusing to migrate', $this->tester->getDisplay());
		$this->assertStringContainsString('Nothing was rewritten', $this->tester->getDisplay());
	}//end testRealRunFailsClosedWhenExecutorRefuses()

	/**
	 * `--dry-run` drives the PLANNER only and never touches the executor or appconfig.
	 *
	 * @return void
	 */
	public function testDryRunDrivesPlannerNotExecutor(): void {
		// planEverything(), not planAll(): the dry-run gate is estate-wide, so a
		// second migratable schema holding an inline secret keeps Phase D closed.
		$this->planner->expects($this->once())->method('planEverything')->willReturn(
			[
				'schemas' => [],
				'wouldMigrate' => 0,
				'needsReview' => 0,
				'clean' => true,
			]
		);
		$this->executor->expects($this->never())->method('migrateEverything');
		$this->appConfig->expects($this->never())->method('setValueString');

		$exit = $this->tester->execute(['--dry-run' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
	}//end testDryRunDrivesPlannerNotExecutor()
}//end class
