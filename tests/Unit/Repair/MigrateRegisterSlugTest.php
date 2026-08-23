<?php

/**
 * Tests for the register-slug migration and its pure decisions.
 *
 * @category  Test
 * @package   OCA\Integriq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateRegisterSlug;
use OCA\Integriq\Repair\MigrateRegisterSlugDecisions;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The register-slug migration, exercised against a mocked connection.
 *
 * PHPUnit assertions take positional arguments; the named-parameter sniff does
 * not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Integriq\Repair\MigrateRegisterSlug
 * @covers \OCA\Integriq\Repair\MigrateRegisterSlugDecisions
 *
 * @spec exclude No canonical spec covers the `openconnector` -> `integriq` register-slug
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class MigrateRegisterSlugTest extends TestCase {

	/**
	 * The decisions under test.
	 *
	 * @var MigrateRegisterSlugDecisions
	 */
	private MigrateRegisterSlugDecisions $decisions;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->decisions = new MigrateRegisterSlugDecisions();

	}//end setUp()

	/**
	 * Build a connection whose SELECT returns the given register rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Register rows to return.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function dbReturning(array $rows) {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);

		return $db;
	}//end dbReturning()

	/**
	 * A register on the old slug is renamed to the new one.
	 *
	 * The assertion is on the bound PARAMETERS, not just on "an update ran":
	 * a rename that wrote the wrong pair would still satisfy the weaker check.
	 *
	 * @return void
	 */
	public function testRenamesARegisterThatIsPresent(): void {
		$db = $this->dbReturning([['slug' => 'openconnector']]);

		$written = [];
		$db->expects(self::once())
			->method('executeStatement')
			->willReturnCallback(function (string $sql, array $params) use (&$written): int {
				$written[] = $params;
				return 1;
			});

		$step = new MigrateRegisterSlug($db, $this->appConfig(), $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('1 register slug(s) renamed'));

		$step->run($output);

		self::assertSame([['integriq', 'openconnector']], $written);

	}//end testRenamesARegisterThatIsPresent()

	/**
	 * A second run finds nothing to do and writes nothing.
	 *
	 * The step is registered in BOTH the install and post-migration blocks, so
	 * running twice is the expected case, not the edge case.
	 *
	 * @return void
	 */
	public function testIsIdempotent(): void {
		$db = $this->dbReturning([['slug' => 'integriq']]);
		$db->expects(self::never())->method('executeStatement');

		$step = new MigrateRegisterSlug($db, $this->appConfig(), $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 register slug(s) renamed, 0 refused'));

		$step->run($output);

	}//end testIsIdempotent()

	/**
	 * An install that never had the register is a no-op, not a refusal.
	 *
	 * @return void
	 */
	public function testAbsentRegisterIsANoOp(): void {
		$db = $this->dbReturning([]);
		$db->expects(self::never())->method('executeStatement');

		$step = new MigrateRegisterSlug($db, $this->appConfig(), $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 register slug(s) renamed, 0 refused'));

		$step->run($output);

	}//end testAbsentRegisterIsANoOp()

	/**
	 * Old and new both present: refuse, and rename NEITHER.
	 *
	 * Two rows sharing a slug is not a merge this step may perform —
	 * OpenRegister caps a slug lookup at one row ordered by id, so the lower id
	 * would silently win every lookup and the other register's objects would
	 * become unreachable without a single error.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheTargetSlugAlreadyExists(): void {
		$db = $this->dbReturning([['slug' => 'openconnector'], ['slug' => 'integriq']]);
		$db->expects(self::never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('already exists'));

		$step = new MigrateRegisterSlug($db, $this->appConfig(), $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 register slug(s) renamed, 1 refused'));

		$step->run($output);

	}//end testRefusesWhenTheTargetSlugAlreadyExists()

	/**
	 * A failing UPDATE is logged and counted as not renamed — never thrown.
	 *
	 * This step is registered under `<install>`, where an escaping exception
	 * aborts the install and the app never enables at all.
	 *
	 * @return void
	 */
	public function testAFailingUpdateIsLoggedNotThrown(): void {
		$db = $this->dbReturning([['slug' => 'openconnector']]);
		$db->method('executeStatement')->willThrowException(new \OCP\DB\Exception('write failed'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('rename failed'));

		$step = new MigrateRegisterSlug($db, $this->appConfig(), $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 register slug(s) renamed'));

		$step->run($output);

	}//end testAFailingUpdateIsLoggedNotThrown()

	/**
	 * A failing SELECT plans no rename rather than aborting the install.
	 *
	 * @return void
	 */
	public function testAFailingReadPlansNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new \OCP\DB\Exception('read failed'));
		$db->expects(self::never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('could not read register slugs'));

		$step = new MigrateRegisterSlug($db, $this->appConfig(), $logger);
		$step->run($this->createMock(IOutput::class));

	}//end testAFailingReadPlansNothing()

	/**
	 * Two map entries targeting one name: the second is refused, not applied.
	 *
	 * The planner must see its own earlier rename, or both would look safe and
	 * the second would overwrite the first's target.
	 *
	 * @return void
	 */
	public function testAPlannedRenameIsVisibleToTheNextCollisionCheck(): void {
		$plan = $this->decisions->plan(
			map: ['a' => 'target', 'b' => 'target'],
			existing: ['a', 'b']
		);

		self::assertSame(['a' => 'target'], $plan['renames']);
		self::assertArrayHasKey('b', $plan['refused']);

	}//end testAPlannedRenameIsVisibleToTheNextCollisionCheck()

	/**
	 * The read set covers BOTH sides of the map.
	 *
	 * Reading only the old slugs would find the register to rename and stay
	 * blind to the row already holding its target name — the collision check
	 * would then pass on an install where it must refuse.
	 *
	 * @return void
	 */
	public function testSlugsToReadCoversBothSidesOfTheMap(): void {
		$slugs = $this->decisions->slugsToRead(map: MigrateRegisterSlug::SLUG_MAP);

		self::assertContains('openconnector', $slugs);
		self::assertContains('integriq', $slugs);
		self::assertSame(count($slugs), count(array_unique($slugs)));

	}//end testSlugsToReadCoversBothSidesOfTheMap()

	/**
	 * A row with a null slug yields an empty string, not a TypeError.
	 *
	 * @return void
	 */
	public function testSlugsFromToleratesMissingAndNullSlugs(): void {
		self::assertSame(
			['openconnector', '', ''],
			$this->decisions->slugsFrom(rows: [['slug' => 'openconnector'], ['slug' => null], []])
		);

	}//end testSlugsFromToleratesMissingAndNullSlugs()

	/**
	 * The placeholder list matches the bound-parameter count, including zero.
	 *
	 * @return void
	 */
	public function testPlaceholdersMatchTheParameterCount(): void {
		self::assertSame('', $this->decisions->placeholders(count: 0));
		self::assertSame('', $this->decisions->placeholders(count: -3));
		self::assertSame('?', $this->decisions->placeholders(count: 1));
		self::assertSame('?,?,?', $this->decisions->placeholders(count: 3));

	}//end testPlaceholdersMatchTheParameterCount()

	/**
	 * An app config whose stored value is $stored, recording what is written.
	 *
	 * @param string $stored Current stored value for every key.
	 * @param array<int, array<int, string>> $written Captured writes, by reference.
	 *
	 * @return IAppConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function appConfig(string $stored = '', array &$written = []) {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturn($stored);
		$cfg->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$written): bool {
				$written[] = [$key, $value];
				return true;
			}
		);

		return $cfg;
	}//end appConfig()

	/**
	 * A stored config value naming an OLD slug is re-pointed at the new one.
	 *
	 * Renaming the register ROW is not enough on its own: this app resolves its
	 * register through IAppConfig, and MigrateAppConfigKeys copies the old app
	 * id's value across VERBATIM. Left alone, every reader would go on asking
	 * for a slug nothing answers to — which OpenRegister resolves by creating an
	 * empty register. The same silent failure, one layer up.
	 *
	 * @return void
	 */
	public function testAStoredConfigValueOnTheOldSlugIsRePointed(): void {
		$written = [];
		$db = $this->dbReturning([]);

		$step = new MigrateRegisterSlug(
			$db,
			$this->appConfig('openconnector', $written),
			$this->createMock(LoggerInterface::class)
		);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('1 config value(s) re-pointed'));

		$step->run($output);

		self::assertSame([['register', 'integriq']], $written);

	}//end testAStoredConfigValueOnTheOldSlugIsRePointed()

	/**
	 * A config value that is NOT an old slug is left exactly as it is.
	 *
	 * The guard is on the VALUE, not on the key. Apps that store the numeric
	 * register id here never match, and an administrator's deliberate override
	 * is not something a repair step may overwrite.
	 *
	 * @return void
	 */
	public function testAConfigValueThatIsNotAnOldSlugIsLeftAlone(): void {
		$written = [];
		$db = $this->dbReturning([]);

		$step = new MigrateRegisterSlug(
			$db,
			$this->appConfig('17', $written),
			$this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $written);

	}//end testAConfigValueThatIsNotAnOldSlugIsLeftAlone()

	/**
	 * A config READ that throws is logged, and plans no write.
	 *
	 * IAppConfig can throw on a type conflict, and this step runs under
	 * <install> where an escaping exception aborts the install and the app never
	 * enables at all. Failing to read a value must therefore mean "leave it
	 * alone", not "abort the upgrade".
	 *
	 * @return void
	 */
	public function testAConfigReadFailureIsLoggedNotThrown(): void {
		$written = [];
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willThrowException(new \RuntimeException('type conflict'));
		$cfg->expects(self::never())->method('setValueString');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('could not read app config'));

		$step = new MigrateRegisterSlug($this->dbReturning([]), $cfg, $logger);
		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $written);

	}//end testAConfigReadFailureIsLoggedNotThrown()

	/**
	 * A config WRITE that throws is logged and counted as not re-pointed.
	 *
	 * Same reason as the read: under <install> a throw costs the whole install.
	 * The count in the summary must also stay honest — a write that failed is
	 * not a value re-pointed.
	 *
	 * @return void
	 */
	public function testAConfigWriteFailureIsLoggedNotThrown(): void {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturn('openconnector');
		$cfg->method('setValueString')->willThrowException(new \RuntimeException('write refused'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('could not re-point app config'));

		$step = new MigrateRegisterSlug($this->dbReturning([]), $cfg, $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 config value(s) re-pointed'));

		$step->run($output);

	}//end testAConfigWriteFailureIsLoggedNotThrown()

	/**
	 * The shipped map is a repair step's, and every entry actually moves.
	 *
	 * @return void
	 */
	public function testShippedMapIsWellFormed(): void {
		$map = MigrateRegisterSlug::SLUG_MAP;
		self::assertNotSame([], $map);

		foreach ($map as $old => $new) {
			self::assertNotSame($old, $new, "`$old` maps to itself");
			self::assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', (string)$old);
			self::assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', (string)$new);
		}

		self::assertTrue(
			(new ReflectionClass(MigrateRegisterSlug::class))->implementsInterface(IRepairStep::class)
		);
		self::assertNotSame('', (new MigrateRegisterSlug(
			$this->createMock(IDBConnection::class),
			$this->appConfig(),
			$this->createMock(LoggerInterface::class)
		))->getName());

	}//end testShippedMapIsWellFormed()
}//end class
