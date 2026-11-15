<?php

/**
 * Golden characterization of LegacyToRegisterMigrator::migrateAll().
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
use RuntimeException;

/**
 * Every run shape of migrateAll() over a scripted legacy database, recorded
 * against a golden file: the per-entity results, the rows written, the log
 * lines and the flag it sets.
 *
 * Regenerate the golden file on the code it must describe with
 * LEGACY_MIGRATOR_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\Migration\LegacyToRegisterMigrator
 */
class LegacyToRegisterMigratorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/migration/legacy-to-register-migrate-all.json';

	/**
	 * What the current case recorded.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * The scripted database of the current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $world = [];

	/**
	 * Legacy rows with stable timestamps.
	 *
	 * @param string $prefix The uuid prefix.
	 * @param int $count How many rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $prefix, int $count): array {
		$rows = [];
		for ($i = 1; $i <= $count; $i++) {
			$rows[] = ['id' => $i, 'uuid' => $prefix . '-' . $i, 'name' => 'n' . $i, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-02 00:00:00'];
		}

		return $rows;
	}//end rows()

	/**
	 * The cases: the scripted database and the migrateAll() arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$sources = $this->rows('src', 2);
		$mixed = [
			['id' => 1, 'uuid' => 'm-1', 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
			['id' => 2, 'uuid' => '', 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
			['id' => 3, 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
			['id' => 4, 'uuid' => 'fail-4', 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
			['id' => 5, 'uuid' => 'm-5', 'date_created' => '2025-05-05 05:05:05', 'date_modified' => '2025-06-06 06:06:06'],
		];
		$syncRows = [
			['id' => 1, 'uuid' => 's-1', 'source_id' => '1', 'target_id' => 'reg/schema', 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
			['id' => 2, 'uuid' => 's-2', 'source_id' => 'abc', 'target_id' => '00000000-0000-4000-8000-000000000001', 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00'],
		];
		return [
			'batch-too-small' => ['batchSize' => 99],
			'batch-too-large' => ['batchSize' => 100001],
			'unknown-slug' => ['entitySlug' => 'nope'],
			'no-register' => ['noRegister' => true],
			'fresh-install' => [],
			'single-entity-dry-run' => ['tables' => ['openconnector_sources' => $sources], 'entitySlug' => 'source', 'dryRun' => true],
			'single-entity' => ['tables' => ['openconnector_sources' => $sources], 'entitySlug' => 'source'],
			'single-entity-empty-table' => ['tables' => ['openconnector_sources' => []], 'entitySlug' => 'source'],
			'full-dry-run' => ['tables' => ['openconnector_sources' => $sources, 'openconnector_jobs' => $this->rows('job', 1)], 'dryRun' => true],
			'full-clean' => ['tables' => ['openconnector_sources' => $sources, 'openconnector_jobs' => $this->rows('job', 1)]],
			'full-with-skips' => ['tables' => ['openconnector_mappings' => $mixed]],
			'full-fk-failure' => ['tables' => ['openconnector_sources' => $sources], 'fkThrows' => true],
			'many-batches' => ['tables' => ['openconnector_rules' => $this->rows('r', 205)], 'entitySlug' => 'rule', 'batchSize' => 100],
			'count-above-rows' => ['tables' => ['openconnector_rules' => $this->rows('r', 3)], 'counts' => ['openconnector_rules' => 250], 'entitySlug' => 'rule', 'batchSize' => 100],
			'sync-branching' => ['tables' => ['openconnector_synchronizations' => $syncRows, 'openconnector_sources' => $sources], 'entitySlug' => 'synchronization'],
			'missing-schema' => ['tables' => ['openconnector_sources' => $sources], 'schemas' => ['consumer'], 'entitySlug' => 'source'],
			'missing-schema-full' => ['tables' => ['openconnector_sources' => $sources], 'schemas' => ['consumer', 'endpoint']],
			'table-probe-throws' => ['tables' => ['openconnector_sources' => $sources], 'probeThrows' => true, 'entitySlug' => 'source'],
		];
	}//end cases()

	/**
	 * A result that serves the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param mixed $one What fetchOne() answers.
	 *
	 * @return IResult
	 */
	private function served(array $rows, $one = false): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetchOne')->willReturn($one);
		$result->method('closeCursor')->willReturn(true);
		return $result;
	}//end result()

	/**
	 * What a SELECT on the scripted database answers.
	 *
	 * @param \stdClass $state The query builder state.
	 *
	 * @return IResult
	 */
	private function select(\stdClass $state): IResult {
		$tables = ($this->world['tables'] ?? []);
		if ($state->from === 'openregister_registers') {
			return $this->served(($this->world['noRegister'] ?? false) === true ? [] : [['id' => 5]]);
		}

		if ($state->from === 'openregister_schemas') {
			$slugs = ($this->world['schemas'] ?? array_column(LegacyToRegisterMigrator::ENTITY_ORDER, 'slug'));
			return $this->served(array_map(static fn (string $slug, int $i) => ['id' => (100 + $i), 'slug' => $slug], $slugs, array_keys($slugs)));
		}

		$rows = ($tables[$state->from] ?? []);
		if ($state->count === true) {
			return $this->served([], (string)($this->world['counts'][$state->from] ?? count($rows)));
		}

		if ($state->from === 'openconnector_sources' && $state->select === ['uuid']) {
			return $this->served([['uuid' => 'src-uuid']]);
		}

		$this->seen['batches'][] = [$state->from, $state->first, $state->max];
		return $this->served(array_slice($rows, (int)$state->first, (int)$state->max));
	}//end select()

	/**
	 * A fluent query builder over the scripted database.
	 *
	 * @return IQueryBuilder
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$state = (object)['from' => '', 'select' => [], 'count' => false, 'insert' => '', 'values' => [], 'first' => null, 'max' => null];
		$count = $this->createMock(IQueryFunction::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($count);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('1=1');

		$qb->method('select')->willReturnCallback(
			function (...$fields) use ($qb, $state, $count) {
				$state->select = $fields;
				$state->count = ($fields === [$count]);
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function ($table) use ($qb, $state) {
				$state->from = (string)$table;
				return $qb;
			}
		);
		$qb->method('insert')->willReturnCallback(
			function ($table) use ($qb, $state) {
				$state->insert = (string)$table;
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function ($values) use ($qb, $state) {
				$state->values = $values;
				return $qb;
			}
		);
		$qb->method('setFirstResult')->willReturnCallback(
			function ($first) use ($qb, $state) {
				$state->first = $first;
				return $qb;
			}
		);
		$qb->method('setMaxResults')->willReturnCallback(
			function ($max) use ($qb, $state) {
				$state->max = $max;
				return $qb;
			}
		);
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$qb->method('executeQuery')->willReturnCallback(fn () => $this->select(state: $state));
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($state) {
				if ($state->insert === 'openregister_objects') {
					if (str_starts_with((string)$state->values['uuid'], 'fail-') === true) {
						throw new RuntimeException('insert refused');
					}

					$this->seen['inserts'][] = $state->values;
				}

				if ($state->insert === 'openregister_audit_trail') {
					$this->seen['audit'][] = json_decode((string)$state->values['changed'], true);
				}

				return 1;
			}
		);
		return $qb;
	}//end queryBuilder()

	/**
	 * A prepared statement: the table probe, or an FK rewrite that fails.
	 *
	 * @param string $sql The statement text.
	 *
	 * @return IPreparedStatement
	 */
	private function prepared(string $sql): IPreparedStatement {
		if (($this->world['probeThrows'] ?? false) === true) {
			throw new RuntimeException('no information_schema');
		}

		$stmt = $this->createMock(IPreparedStatement::class);
		if (str_starts_with($sql, 'SELECT 1 FROM information_schema') === true) {
			$table = '';
			$stmt->method('execute')->willReturnCallback(
				function ($params) use (&$table) {
					$table = $params[0];
					return $this->createMock(IResult::class);
				}
			);
			$stmt->method('fetch')->willReturnCallback(
				function () use (&$table) {
					$short = str_replace('oc_', '', $table);
					return array_key_exists($short, ($this->world['tables'] ?? [])) === true ? ['1' => 1] : false;
				}
			);
			return $stmt;
		}

		$stmt->method('bindValue')->willReturnCallback(
			function ($param, $value) {
				$this->seen['fkBinds'][] = [$param, $value];
				return true;
			}
		);
		$stmt->method('execute')->willThrowException(new RuntimeException('fk rewrite refused'));
		return $stmt;
	}//end prepared()

	/**
	 * The migrator over the scripted database of the current case.
	 *
	 * @return LegacyToRegisterMigrator
	 */
	private function migrator(): LegacyToRegisterMigrator {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn('mysql');
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		$db->method('prepare')->willReturnCallback(fn (string $sql) => $this->prepared(sql: $sql));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setAppValueString')->willReturnCallback(
			function (string $key, string $value) {
				$this->seen['appConfig'][] = [$key, $value];
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level) {
					$this->seen['log'][] = [$level, (string)$message];
				}
			);
		}

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('audit-uuid');

		return new LegacyToRegisterMigrator($db, $appConfig, $logger, $random);
	}//end migrator()

	/**
	 * Every case's outcome as JSON text.
	 *
	 * @return array<string, string>
	 */
	private function record(): array {
		$out = [];
		foreach ($this->cases() as $name => $case) {
			$this->world = $case;
			$this->seen = [];
			try {
				$results = $this->migrator()->migrateAll(
					dryRun: ($case['dryRun'] ?? false),
					entitySlug: ($case['entitySlug'] ?? null),
					batchSize: ($case['batchSize'] ?? 10000)
				);
				foreach ($results as $index => $perEntity) {
					unset($results[$index]['elapsedMs']);
				}
			} catch (\Throwable $e) {
				$results = get_class($e) . ': ' . $e->getMessage();
			}

			foreach (($this->seen['audit'] ?? []) as $index => $audit) {
				foreach (($audit['perEntity'] ?? []) as $entityIndex => $perEntity) {
					unset($this->seen['audit'][$index]['perEntity'][$entityIndex]['elapsedMs']);
				}
			}

			$inserts = ($this->seen['inserts'] ?? []);
			$this->seen['inserts'] = ['count' => count($inserts), 'first' => ($inserts[0] ?? null), 'last' => (end($inserts) ?: null), 'md5' => md5((string)json_encode($inserts))];
			$out[$name] = json_encode(['results' => $results, 'seen' => $this->seen], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}//end foreach

		return $out;
	}//end record()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$record = $this->record();
		if (getenv('LEGACY_MIGRATOR_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($record, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($record));
		foreach ($record as $name => $value) {
			$this->assertSame($golden[$name], $value, $name);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
