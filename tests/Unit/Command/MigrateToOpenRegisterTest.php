<?php

/**
 * Unit tests for the openconnector:migrate-storage OCC command.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openconnector.app
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Command;

use InvalidArgumentException;
use OCA\Integriq\Command\MigrateToOpenRegister;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the OCC command's flag validation, dispatch, and output rendering.
 */
class MigrateToOpenRegisterTest extends TestCase {

	/**
	 * Mocked migrator service.
	 *
	 * @var LegacyToRegisterMigrator&MockObject
	 */
	private LegacyToRegisterMigrator $migrator;

	/**
	 * The command under test, wrapped in a tester.
	 *
	 * @var CommandTester
	 */
	private CommandTester $tester;

	/**
	 * Set up the command + tester with a mocked migrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->migrator = $this->createMock(LegacyToRegisterMigrator::class);

		$command = new MigrateToOpenRegister($this->migrator);
		$application = new Application();
		$application->add($command);

		$this->tester = new CommandTester($command);

	}//end setUp()

	/**
	 * A clean full run flips the flag and exits 0.
	 *
	 * @return void
	 */
	public function testFullRunSuccess(): void {
		$this->migrator->expects($this->once())
			->method('migrateAll')
			->with(false, null, 10000)
			->willReturn(
				[
					['slug' => 'source', 'legacyCount' => 3, 'migratedCount' => 3, 'skipped' => 0, 'fkRewrites' => 0, 'elapsedMs' => 5],
					['slug' => 'call_log', 'legacyCount' => 9, 'migratedCount' => 9, 'skipped' => 0, 'fkRewrites' => 18, 'elapsedMs' => 12],
				]
			);

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('Migration complete', $this->tester->getDisplay());

	}//end testFullRunSuccess()

	/**
	 * An entity that reports skips returns FAILURE and does not claim the flag flipped.
	 *
	 * @return void
	 */
	public function testRunWithSkipsFails(): void {
		$this->migrator->method('migrateAll')->willReturn(
			[
				['slug' => 'source', 'legacyCount' => 5, 'migratedCount' => 4, 'skipped' => 1, 'fkRewrites' => 0, 'elapsedMs' => 7],
			]
		);

		$exit = $this->tester->execute([]);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('NOT flipped', $this->tester->getDisplay());

	}//end testRunWithSkipsFails()

	/**
	 * --dry-run forwards dryRun=true and reports no data was written.
	 *
	 * @return void
	 */
	public function testDryRunForwardsFlag(): void {
		$this->migrator->expects($this->once())
			->method('migrateAll')
			->with(true, null, 10000)
			->willReturn([['slug' => 'source', 'legacyCount' => 3, 'migratedCount' => 0, 'skipped' => 0, 'fkRewrites' => 0, 'elapsedMs' => 1]]);

		$exit = $this->tester->execute(['--dry-run' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('No data written', $this->tester->getDisplay());

	}//end testDryRunForwardsFlag()

	/**
	 * --entity with a valid slug forwards the slug; success message warns the flag is not flipped.
	 *
	 * @return void
	 */
	public function testSingleEntityForwardsSlug(): void {
		$this->migrator->expects($this->once())
			->method('migrateAll')
			->with(false, 'call_log', 10000)
			->willReturn([['slug' => 'call_log', 'legacyCount' => 2, 'migratedCount' => 2, 'skipped' => 0, 'fkRewrites' => 0, 'elapsedMs' => 3]]);

		$exit = $this->tester->execute(['--entity' => 'call_log']);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('Run a full migration', $this->tester->getDisplay());

	}//end testSingleEntityForwardsSlug()

	/**
	 * An unknown --entity slug fails without ever calling the migrator.
	 *
	 * @return void
	 */
	public function testUnknownEntityRejected(): void {
		$this->migrator->expects($this->never())->method('migrateAll');

		$exit = $this->tester->execute(['--entity' => 'not-a-real-slug']);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('Unknown entity', $this->tester->getDisplay());

	}//end testUnknownEntityRejected()

	/**
	 * A batch-size below the floor fails without calling the migrator.
	 *
	 * @return void
	 */
	public function testBatchSizeTooSmallRejected(): void {
		$this->migrator->expects($this->never())->method('migrateAll');

		$exit = $this->tester->execute(['--batch-size' => '50']);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('[100, 100000]', $this->tester->getDisplay());

	}//end testBatchSizeTooSmallRejected()

	/**
	 * A non-numeric batch-size fails without calling the migrator.
	 *
	 * @return void
	 */
	public function testNonNumericBatchSizeRejected(): void {
		$this->migrator->expects($this->never())->method('migrateAll');

		$exit = $this->tester->execute(['--batch-size' => 'abc']);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('must be an integer', $this->tester->getDisplay());

	}//end testNonNumericBatchSizeRejected()

	/**
	 * --verify-only calls verifyRowCounts (not migrateAll) and reports parity.
	 *
	 * @return void
	 */
	public function testVerifyOnlyReportsParity(): void {
		$this->migrator->expects($this->never())->method('migrateAll');
		$this->migrator->expects($this->once())
			->method('verifyRowCounts')
			->willReturn(
				[
					['slug' => 'source', 'legacy' => 3, 'register' => 3, 'skipped' => 0, 'equal' => true],
					['slug' => 'call_log', 'legacy' => 9, 'register' => 9, 'skipped' => 0, 'equal' => true],
				]
			);

		$exit = $this->tester->execute(['--verify-only' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('parity', $this->tester->getDisplay());

	}//end testVerifyOnlyReportsParity()

	/**
	 * --verify-only with a mismatch exits non-zero.
	 *
	 * @return void
	 */
	public function testVerifyOnlyMismatchFails(): void {
		$this->migrator->method('verifyRowCounts')->willReturn(
			[
				['slug' => 'source', 'legacy' => 5, 'register' => 4, 'skipped' => 0, 'equal' => false],
			]
		);

		$exit = $this->tester->execute(['--verify-only' => true]);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('mismatch', $this->tester->getDisplay());

	}//end testVerifyOnlyMismatchFails()

	/**
	 * An InvalidArgumentException from the migrator is surfaced as a clean failure.
	 *
	 * @return void
	 */
	public function testMigratorInvalidArgumentSurfaced(): void {
		$this->migrator->method('migrateAll')
			->willThrowException(new InvalidArgumentException('batchSize MUST be in [100, 100000], got 5'));

		// batch-size is valid at the CLI layer; the migrator rejects it internally.
		$exit = $this->tester->execute(['--batch-size' => '100']);

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('batchSize MUST be', $this->tester->getDisplay());

	}//end testMigratorInvalidArgumentSurfaced()
}//end class
