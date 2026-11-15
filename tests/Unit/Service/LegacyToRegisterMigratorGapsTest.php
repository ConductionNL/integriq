<?php

/**
 * Unit tests for the legacy-to-register migrator's verification and fallbacks.
 *
 * The post-migration row-count parity report, JSON column decoding in the
 * object body, an integer source reference that resolves to nothing, and a
 * summary audit-trail write that fails.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for the migrator paths the other migrator tests leave out.
 */
#[CoversClass(LegacyToRegisterMigrator::class)]
class LegacyToRegisterMigratorGapsTest extends TestCase {

	/**
	 * @var IDBConnection|MockObject
	 */
	private IDBConnection|MockObject $db;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface|MockObject $logger;

	/**
	 * Answers per table for SELECT queries: a list of rows, or a scalar for fetchOne().
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = [];

	/**
	 * The legacy tables that still exist.
	 *
	 * @var array<int, string>
	 */
	private array $existingTables = [];

	/**
	 * The named parameters each query was given, per table.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $parameters = [];

	/**
	 * Set up the database and logger doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->answers = [];
		$this->existingTables = [];
		$this->parameters = [];

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		$this->db->method('prepare')->willReturnCallback(fn (string $sql) => $this->tableExistsStatement());

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The migrator over the doubles.
	 *
	 * @param ISecureRandom|null $secureRandom The random source, a plain double when null.
	 *
	 * @return LegacyToRegisterMigrator The migrator.
	 */
	private function migrator(?ISecureRandom $secureRandom = null): LegacyToRegisterMigrator {
		return new LegacyToRegisterMigrator(
			db: $this->db,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger,
			secureRandom: ($secureRandom ?? $this->createMock(ISecureRandom::class))
		);

	}//end migrator()

	/**
	 * A prepared statement answering the information_schema existence probe.
	 *
	 * @return IPreparedStatement The statement.
	 */
	private function tableExistsStatement(): IPreparedStatement {
		$table = '';
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturnCallback(
			function (?array $params = null) use (&$table) {
				$table = (string)($params[0] ?? '');
				return $this->createMock(IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnCallback(
			function () use (&$table) {
				return in_array($table, $this->existingTables, true) === true ? ['1' => 1] : false;
			}
		);

		return $stmt;

	}//end tableExistsStatement()

	/**
	 * A query builder that answers SELECTs from $this->answers by table.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function queryBuilder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$state = (object)['from' => ''];

		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			function ($table) use ($qb, $state) {
				$state->from = (string)$table;
				return $qb;
			}
		);
		$qb->method('insert')->willReturnSelf();
		$qb->method('values')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use ($state) {
				$this->parameters[$state->from][] = $value;
				return $value;
			}
		);
		$qb->method('func')->willReturnCallback(
			function () {
				$func = $this->createMock(IFunctionBuilder::class);
				$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
				return $func;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($state) {
				$answer = $this->answers[$state->from] ?? [];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $this->fakeResult(answer: $answer);
			}
		);

		return $qb;

	}//end queryBuilder()

	/**
	 * A result over rows, or over one scalar for fetchOne().
	 *
	 * @param mixed $answer The rows or the scalar.
	 *
	 * @return IResult The result.
	 */
	private function fakeResult(mixed $answer): IResult {
		$rows = is_array($answer) === true ? $answer : [];
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnCallback(
			function () use (&$rows) {
				return array_shift($rows) ?? false;
			}
		);
		$result->method('fetchOne')->willReturn(is_array($answer) === true ? false : $answer);

		return $result;

	}//end result()

	/**
	 * Invoke a private method of the migrator.
	 *
	 * @param LegacyToRegisterMigrator $migrator The migrator.
	 * @param string $method The method.
	 * @param array $args The arguments, by reference where the method takes one.
	 *
	 * @return mixed What it returned.
	 */
	private function invoke(LegacyToRegisterMigrator $migrator, string $method, array $args): mixed {
		return (new ReflectionMethod(LegacyToRegisterMigrator::class, $method))->invokeArgs($migrator, $args);

	}//end invoke()

	/**
	 * Parity holds per entity when legacy minus skipped equals the register count.
	 *
	 * @return void
	 */
	public function testRowCountParityComparesLegacyMinusSkippedAgainstTheRegister(): void {
		$this->answers['openregister_registers'] = [['id' => 7]];
		$this->answers['openregister_schemas'] = [
			['id' => 11, 'slug' => 'source'],
			['id' => 12, 'slug' => 'consumer'],
		];
		$this->existingTables = ['oc_openconnector_sources', 'oc_openconnector_consumers'];
		$this->answers['openconnector_sources'] = '5';
		$this->answers['openconnector_consumers'] = '3';
		$this->answers['openregister_objects'] = '4';

		$report = $this->migrator()->verifyRowCounts(skippedBySlug: ['source' => 1]);

		$this->assertCount(count(LegacyToRegisterMigrator::ENTITY_ORDER), $report);
		$this->assertSame(
			array_column(LegacyToRegisterMigrator::ENTITY_ORDER, 'slug'),
			array_column($report, 'slug')
		);
		$this->assertSame(['slug' => 'source', 'legacy' => 5, 'register' => 4, 'skipped' => 1, 'equal' => true], $report[0]);
		$this->assertSame(['slug' => 'consumer', 'legacy' => 3, 'register' => 4, 'skipped' => 0, 'equal' => false], $report[1]);
		$this->assertSame([7, 11, 7, 12], $this->parameters['openregister_objects']);

	}//end testRowCountParityComparesLegacyMinusSkippedAgainstTheRegister()

	/**
	 * A dropped legacy table counts as zero and an unprovisioned schema has no register objects.
	 *
	 * @return void
	 */
	public function testADroppedTableAndAnUnprovisionedSchemaBothCountZero(): void {
		$this->answers['openregister_registers'] = [['id' => 7]];
		$this->answers['openregister_schemas'] = [];
		$this->answers['openregister_objects'] = '99';

		$report = $this->migrator()->verifyRowCounts();

		$endpoint = $report[2];
		$this->assertSame(['slug' => 'endpoint', 'legacy' => 0, 'register' => 0, 'skipped' => 0, 'equal' => true], $endpoint);
		$this->assertArrayNotHasKey('openregister_objects', $this->parameters, 'no schema, so the register is never queried');
		foreach ($report as $row) {
			$this->assertTrue($row['equal']);
		}

	}//end testADroppedTableAndAnUnprovisionedSchemaBothCountZero()

	/**
	 * JSON-shaped columns are decoded into the body, broken JSON is kept as text, and OR columns are left out.
	 *
	 * @return void
	 */
	public function testJsonColumnsAreDecodedAndBrokenJsonIsKeptAsText(): void {
		$counts = [];
		$body = $this->invoke(
			$this->migrator(),
			'buildJsonBody',
			[
				[
					'id' => 3,
					'uuid' => 'u-3',
					'owner' => 'admin',
					'configuration' => '{"timeout":30}',
					'allowed_methods' => '["GET","POST"]',
					'broken_json' => '{not json',
					'base_url' => 'https://example.test',
				],
				false,
				&$counts,
			]
		);

		$this->assertSame(['timeout' => 30], $body['configuration']);
		$this->assertSame(['GET', 'POST'], $body['allowedMethods']);
		$this->assertSame('{not json', $body['brokenJson']);
		$this->assertSame('https://example.test', $body['baseUrl']);
		$this->assertArrayNotHasKey('id', $body);
		$this->assertArrayNotHasKey('uuid', $body);
		$this->assertArrayNotHasKey('owner', $body);

	}//end testJsonColumnsAreDecodedAndBrokenJsonIsKeptAsText()

	/**
	 * An integer source reference with no matching source keeps its value.
	 *
	 * @return void
	 */
	public function testAnIntegerSourceReferenceWithNoSourceKeepsItsValue(): void {
		$this->answers['openconnector_sources'] = [];

		$resolved = $this->invoke($this->migrator(), 'resolveSyncRef', ['42']);

		$this->assertSame(['value' => '42', 'variant' => 'integer-pk'], $resolved);
		$this->assertSame([42], $this->parameters['openconnector_sources']);

	}//end testAnIntegerSourceReferenceWithNoSourceKeepsItsValue()

	/**
	 * A failing source lookup is treated as no source rather than aborting the row.
	 *
	 * @return void
	 */
	public function testAFailingSourceLookupKeepsTheValue(): void {
		$this->answers['openconnector_sources'] = new RuntimeException('table gone');

		$resolved = $this->invoke($this->migrator(), 'resolveSyncRef', ['17']);

		$this->assertSame(['value' => '17', 'variant' => 'integer-pk'], $resolved);

	}//end testAFailingSourceLookupKeepsTheValue()

	/**
	 * A summary audit-trail write that fails is logged and does not abort the run.
	 *
	 * @return void
	 */
	public function testAFailingSummaryAuditTrailIsLoggedAndSwallowed(): void {
		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willThrowException(new RuntimeException('no entropy'));

		$migrator = $this->migrator(secureRandom: $secureRandom);
		(new ReflectionProperty(LegacyToRegisterMigrator::class, 'registerPk'))->setValue($migrator, 7);

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'chain-B: summary audit-trail emission failed (no entropy) — proceeding anyway; migration counts remain in the log'
			);

		$this->assertNull(
			$this->invoke($migrator, 'emitSummaryAuditTrail', [[['slug' => 'source']], true, 'source'])
		);

	}//end testAFailingSummaryAuditTrailIsLoggedAndSwallowed()
}//end class
