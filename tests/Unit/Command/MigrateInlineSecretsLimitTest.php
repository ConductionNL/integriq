<?php

/**
 * The inline-secret migration reads --limit before it plans or migrates anything.
 *
 * Pins that an invalid --limit is refused with its error and nothing is
 * planned or migrated, and that a valid one reaches the planner and the
 * executor, so the shared start of a credential-custody run can move without
 * changing either.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use OCA\Integriq\Command\MigrateInlineSecrets;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\Integriq\Service\Security\InlineSecretMigrationPlanner;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the --limit of the inline-secret migration.
 */
final class MigrateInlineSecretsLimitTest extends TestCase {

	/**
	 * Invalid limits and the error each gives.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalidLimits(): array {
		return [
			'not a number' => ['abc', 'limit must be an integer, got "abc"'],
			'zero' => ['0', 'limit must be in [1, 100000], got 0'],
			'too large' => ['100001', 'limit must be in [1, 100000], got 100001'],
		];

	}//end invalidLimits()

	/**
	 * An invalid limit is refused before anything is planned or migrated.
	 *
	 * @param string $limit The --limit value.
	 * @param string $error The error it gives.
	 *
	 * @return void
	 */
	#[DataProvider('invalidLimits')]
	public function testAnInvalidLimitIsRefused(string $limit, string $error): void {
		$planner = $this->createMock(InlineSecretMigrationPlanner::class);
		$planner->expects($this->never())->method($this->anything());
		$executor = $this->createMock(InlineSecretMigrationExecutor::class);
		$executor->expects($this->never())->method($this->anything());
		$tester = new CommandTester(new MigrateInlineSecrets(planner: $planner, executor: $executor, appConfig: $this->createMock(IAppConfig::class)));

		foreach ([['--dry-run' => true], []] as $mode) {
			$this->assertSame(Command::FAILURE, $tester->execute($mode + ['--limit' => $limit]));
			$this->assertStringContainsString($error, preg_replace('/\s+/', ' ', $tester->getDisplay()));
		}

	}//end testAnInvalidLimitIsRefused()

	/**
	 * A valid limit reaches the dry-run planner and the real-run executor.
	 *
	 * @return void
	 */
	public function testAValidLimitReachesThePlannerAndTheExecutor(): void {
		$planner = $this->createMock(InlineSecretMigrationPlanner::class);
		$planner->expects($this->once())->method('planEverything')->with(42)->willReturn(['schemas' => [], 'clean' => true]);
		$executor = $this->createMock(InlineSecretMigrationExecutor::class);
		$executor->expects($this->once())->method('migrateEverything')->with(7)->willReturn(['schemas' => [], 'migrated' => 0, 'failed' => 0, 'blocked' => 0, 'skipped' => 0]);
		$tester = new CommandTester(new MigrateInlineSecrets(planner: $planner, executor: $executor, appConfig: $this->createMock(IAppConfig::class)));

		$tester->execute(['--dry-run' => true, '--limit' => '42', '--json' => true]);
		$tester->execute(['--limit' => '7', '--json' => true]);

	}//end testAValidLimitReachesThePlannerAndTheExecutor()
}//end class
