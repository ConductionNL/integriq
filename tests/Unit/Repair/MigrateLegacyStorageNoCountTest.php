<?php

/**
 * The legacy storage cutover when a legacy table answers its count with nothing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateLegacyStorage;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A COUNT that yields no row is an unknown, not an empty table.
 */
#[CoversClass(MigrateLegacyStorage::class)]
final class MigrateLegacyStorageNoCountTest extends TestCase {

	/**
	 * A count that returns false stops at that table and leaves the flag unset.
	 *
	 * @return void
	 */
	public function testACountWithoutARowLeavesTheFlagAlone(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')
			->with('integriq', 'storage_migrated', 'false')
			->willReturn('false');
		$appConfig->expects($this->never())->method('setValueString');

		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetchOne')->willReturn(false);
		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('func')->willReturn($functions);
		$qb->method('select')->willReturnSelf();
		$qb->expects($this->once())->method('from')
			->with(MigrateLegacyStorage::LEGACY_TABLES[0])
			->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('tableExists')
			->with(MigrateLegacyStorage::LEGACY_TABLES[0])
			->willReturn(true);
		$db->expects($this->once())->method('getQueryBuilder')->willReturn($qb);

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$output->expects($this->never())->method('info');

		(new MigrateLegacyStorage($container, $appConfig, $db, $logger))->run($output);

		$this->assertCount(2, $warnings);
		$this->assertSame(
			'Integriq: legacy table `' . MigrateLegacyStorage::LEGACY_TABLES[0] . '` returned no count.',
			$warnings[0]
		);
		$this->assertStringStartsWith('Integriq: could not count every legacy table', $warnings[1]);
	}//end testACountWithoutARowLeavesTheFlagAlone()
}//end class
