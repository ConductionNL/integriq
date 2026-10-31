<?php

/**
 * Golden characterization of LegacyToRegisterMigrator::migrateEntity().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/openconnector-storage-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * One entity migration per case, recorded against a golden file: every
 * query, every insert with its values, every log line and the result.
 * Regenerate it on the code it must describe with MIGRATOR_ENTITY_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\Migration\LegacyToRegisterMigrator
 * @uses   \OCA\Integriq\Service\Migration\EntityMigrationRun
 * @uses   \OCA\Integriq\Service\Migration\LegacyObjectRow
 */
class LegacyToRegisterMigratorEntityGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/migration/migrate-entity.json';

	/**
	 * Every database call and log line, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * A result serving the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return IResult
	 */
	private function rowsResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetchOne')->willReturn(($rows[0] ?? [false])[array_key_first($rows[0] ?? [0 => false])]);
		$result->method('fetch')->willReturn($rows[0] ?? false);
		return $result;
	}//end result()

	/**
	 * A query builder that records its query and answers from the case.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$state = (object)['call' => []];
		foreach (['select', 'from', 'insert', 'orderBy', 'setFirstResult', 'setMaxResults', 'where'] as $name) {
			$qb->method($name)->willReturnCallback(
				function (...$args) use ($qb, $state, $name) {
					$state->call[] = [$name, ...array_map(static fn ($arg) => is_object($arg) ? '<expression>' : $arg, $args)];
					return $qb;
				}
			);
		}

		$qb->method('values')->willReturnCallback(
			function (array $values) use ($qb, $state) {
				$state->call[] = ['values', $values];
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value, $type = null): string => json_encode([$value, $type]));
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($state) {
				$this->log[] = ['query', $state->call];
				$from = '';
				foreach ($state->call as $call) {
					if ($call[0] === 'from') {
						$from = (string)$call[1];
					}
				}

				$isCount = ($state->call[0][0] === 'select' && $state->call[0][1] === '<expression>');
				if ($isCount === true) {
					return $this->rowsResult([['c' => (int)($this->case['count'] ?? 0)]]);
				}

				if ($from === 'openconnector_sources') {
					return $this->rowsResult([['uuid' => 'source-uuid-42']]);
				}

				$offset = 0;
				$limit = 0;
				foreach ($state->call as $call) {
					if ($call[0] === 'setFirstResult') {
						$offset = (int)$call[1];
					}

					if ($call[0] === 'setMaxResults') {
						$limit = (int)$call[1];
					}
				}

				return $this->rowsResult(array_slice(($this->case['rows'] ?? []), $offset, $limit));
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($state) {
				$this->log[] = ['statement', $state->call];
				$values = ($state->call[count($state->call) - 1][1] ?? []);
				if (str_contains((string)($values['uuid'] ?? ''), 'explode') === true) {
					throw new RuntimeException('insert broke');
				}

				return 1;
			}
		);

		return $qb;
	}//end queryBuilder()

	/**
	 * The migrator over recording collaborators, with its startup state set.
	 *
	 * @return LegacyToRegisterMigrator
	 */
	private function migrator(): LegacyToRegisterMigrator {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('execute')->willReturnCallback(
			function (array $params) {
				$this->log[] = ['prepare.execute', $params];
				return $this->createMock(IResult::class);
			}
		);
		$statement->method('fetch')->willReturnCallback(fn () => (($this->case['tableExists'] ?? true) === true ? ['1' => 1] : false));
		$db->method('prepare')->willReturn($statement);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level) {
					$this->log[] = ['logger.' . $level, preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', '<now>', (string)$message)];
				}
			);
		}

		$migrator = new LegacyToRegisterMigrator($db, $this->createMock(IAppConfig::class), $logger, $this->createMock(ISecureRandom::class));
		$reflection = new ReflectionClass($migrator);
		$reflection->getProperty('registerPk')->setValue($migrator, 3);
		$reflection->getProperty('schemaPks')->setValue($migrator, ['source' => 10, 'synchronization' => 20]);
		return $migrator;
	}//end migrator()

	/**
	 * The cases: entity, run flags, legacy rows and database answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$rows = [
			['id' => 1, 'uuid' => 'u-1', 'name' => 'one', 'config_json' => '{"a":1}', 'list_col' => '[1,2]', 'bad_json' => '{nope', 'created' => '2024-01-01 00:00:00', 'updated' => '2024-01-02 00:00:00', 'owner' => 'x'],
			['id' => 2, 'uuid' => '', 'name' => 'no uuid'],
			['id' => 3, 'uuid' => 'u-3', 'name' => 'three', 'date_created' => '2023-05-05 05:05:05', 'date_modified' => '2023-06-06 06:06:06'],
			['id' => 4, 'uuid' => 'u-explode', 'name' => 'insert fails', 'created' => '2024-01-01 00:00:00', 'updated' => '2024-01-01 00:00:00'],
			['uuid' => 'u-5', 'name' => 'no id', 'created' => '2024-01-01 00:00:00', 'updated' => '2024-01-01 00:00:00'],
			['id' => 6, 'uuid' => 'u-6', 'created' => '2022-02-02 02:02:02', 'date_created' => '2021-01-01 01:01:01', 'updated' => '2022-03-03 03:03:03', 'date_modified' => '2021-04-04 04:04:04'],
		];
		$syncRows = [
			['id' => 7, 'uuid' => 's-1', 'source_id' => '42', 'target_id' => 'zaken/zaak', 'created' => '2024-01-01 00:00:00', 'updated' => '2024-01-01 00:00:00'],
			['id' => 8, 'uuid' => 's-2', 'source_id' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'target_id' => '???', 'created' => '2024-01-01 00:00:00', 'updated' => '2024-01-01 00:00:00'],
			['id' => 9, 'uuid' => '', 'source_id' => '43', 'target_id' => 7, 'created' => '2024-01-01 00:00:00', 'updated' => '2024-01-01 00:00:00'],
		];
		$source = ['slug' => 'source', 'legacyTable' => 'oc_openconnector_legacy_rows'];
		$sync = ['slug' => 'synchronization', 'legacyTable' => 'oc_openconnector_synchronizations', 'hasSyncBranching' => true];
		return [
			'unknown-schema' => ['entity' => ['slug' => 'mapping', 'legacyTable' => 'oc_openconnector_mappings']],
			'no-table' => ['entity' => $source, 'tableExists' => false],
			'empty-table' => ['entity' => $source, 'count' => 0],
			'dry-run' => ['entity' => $source, 'count' => 5, 'dryRun' => true],
			'rows-one-batch' => ['entity' => $source, 'count' => 5, 'rows' => $rows, 'batchSize' => 10],
			'rows-batches' => ['entity' => $source, 'count' => 5, 'rows' => $rows, 'batchSize' => 2],
			'count-above-rows' => ['entity' => $source, 'count' => 9, 'rows' => $rows, 'batchSize' => 3],
			'sync-branching' => ['entity' => $sync, 'count' => 3, 'rows' => $syncRows, 'batchSize' => 2],
			'sync-branching-flag-off' => ['entity' => ['slug' => 'synchronization', 'legacyTable' => 'oc_openconnector_synchronizations'], 'count' => 3, 'rows' => $syncRows, 'batchSize' => 5],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->case = $case;
			$this->log = [];
			$migrator = $this->migrator();
			$method = new \ReflectionMethod($migrator, 'migrateEntity');
			try {
				$outcome = ['return' => $method->invoke($migrator, $case['entity'], (bool)($case['dryRun'] ?? false), (int)($case['batchSize'] ?? 100))];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['outcome' => $outcome, 'log' => $this->log];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('MIGRATOR_ENTITY_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
