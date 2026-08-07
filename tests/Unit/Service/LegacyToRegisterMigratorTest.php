<?php
/**
 * Unit tests for the chain-B LegacyToRegisterMigrator.
 *
 * Verifies the four documented Synchronization.sourceId branching variants
 * (integer-PK → source uuid, register/schema slug passthrough, uuid passthrough,
 * unrecognised passthrough) by driving migrateAll() for the `synchronization`
 * entity against a mocked IDBConnection and capturing the JSON body that the
 * migrator writes into oc_openregister_objects.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openconnector.app
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenConnector\Service\Migration\LegacyToRegisterMigrator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the legacy → OpenRegister storage migrator.
 */
class LegacyToRegisterMigratorTest extends TestCase
{

    /**
     * @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    private $db;

    /**
     * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    private $appConfig;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * Captured JSON bodies inserted into oc_openregister_objects, keyed by call order.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $insertedBodies = [];


    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db        = $this->createMock(IDBConnection::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->insertedBodies = [];

        // The migrator only does `instanceof PostgreSQLPlatform / AbstractMySQLPlatform`
        // checks on the platform object; the concrete Doctrine platform classes are not
        // in the bare unit vendor, so a generic object suffices — the migrator falls back
        // to the MySQL JSON syntax path, which is irrelevant to sourceId branching (that
        // happens in buildJsonBody, platform-agnostic).
        $platform = new \stdClass();
        $this->db->method('getDatabasePlatform')->willReturn($platform);
        $this->db->method('getDatabaseProvider')->willReturn('sqlite');

    }//end setUp()


    /**
     * Build an expression-builder mock returning trivial truthy predicates.
     *
     * @return IExpressionBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeExpr()
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');
        return $expr;

    }//end makeExpr()


    /**
     * Build a fake Doctrine-style result yielding the given associative rows.
     *
     * The migrator consumes QueryBuilder::executeQuery() return values via the
     * Doctrine DBAL Result API (fetchAssociative / fetchAllAssociative), NOT the
     * OCP IResult API (fetch / fetchAll). The OCP IResult interface has no
     * fetchAssociative method, so a hand-rolled fake is used instead of a mock.
     *
     * @param array<int,array<string,mixed>> $rows Rows to yield in order.
     *
     * @return object
     */
    private function makeFakeResult(array $rows): \OCP\DB\IResult
    {
        // Implements OCP\DB\IResult so it satisfies IQueryBuilder::executeQuery()'s
        // declared return type, while additionally exposing the Doctrine DBAL
        // Result methods (fetchAssociative / fetchAllAssociative) the migrator
        // actually calls on the returned value.
        return new class($rows) implements \OCP\DB\IResult {

            /**
             * @var array<int,array<string,mixed>>
             */
            private array $rows;

            /**
             * @var int
             */
            private int $cursor = 0;


            /**
             * @param array<int,array<string,mixed>> $rows Rows to serve.
             */
            public function __construct(array $rows)
            {
                $this->rows = array_values($rows);
            }


            /**
             * NC33 tightened OCP\DB\IResult::fetchAssociative() to declare
             * `: array|false`. An implementation without the native return type
             * is a FATAL there ("must be compatible with"), while remaining valid
             * on NC32 — so this mock silently pinned the suite to <=32.
             *
             * @return array<string,mixed>|false
             */
            public function fetchAssociative(): array|false
            {
                if (isset($this->rows[$this->cursor]) === false) {
                    return false;
                }

                $row = $this->rows[$this->cursor];
                $this->cursor++;
                return $row;
            }


            /**
             * @return array<int,array<string,mixed>>
             */
            public function fetchAllAssociative(): array
            {
                return $this->rows;
            }


            public function closeCursor(): bool
            {
                return true;
            }


            /**
             * @param int $fetchMode Fetch mode (ignored).
             *
             * @return array<string,mixed>|false
             */
            public function fetch(int $fetchMode=\PDO::FETCH_ASSOC)
            {
                return $this->fetchAssociative();
            }


            /**
             * @param int $fetchMode Fetch mode (ignored).
             *
             * @return array<int,array<string,mixed>>
             */
            public function fetchAll(int $fetchMode=\PDO::FETCH_ASSOC): array
            {
                return $this->rows;
            }


            /**
             * @return mixed
             */
            public function fetchColumn()
            {
                $row = $this->fetchAssociative();
                return $row === false ? false : reset($row);
            }


            /**
             * @return mixed
             */
            public function fetchOne()
            {
                return $this->fetchColumn();
            }


            public function rowCount(): int
            {
                return count($this->rows);
            }


            /*
             * The five members below exist ONLY because NC33 expanded
             * OCP\DB\IResult. NC32 declares 6 methods (closeCursor, fetch,
             * fetchAll, fetchColumn, fetchOne, rowCount); NC33 adds
             * fetchAssociative, fetchNumeric, fetchAllAssociative,
             * fetchAllNumeric, fetchFirstColumn, iterateNumeric and
             * iterateAssociative.
             *
             * An anonymous class that implements the interface but omits them
             * is a FATAL on NC33 while remaining valid on NC32 — so this mock
             * silently pinned the whole suite to <=32.
             */

            public function fetchNumeric(): array|false
            {
                $row = $this->fetchAssociative();
                if ($row === false) {
                    return false;
                }

                return array_values($row);
            }


            public function fetchAllNumeric(): array
            {
                return array_map('array_values', $this->rows);
            }


            public function fetchFirstColumn(): array
            {
                return array_map(
                    static function (array $row) {
                        return reset($row);
                    },
                    $this->rows
                );
            }


            public function iterateNumeric(): \Traversable
            {
                foreach ($this->rows as $row) {
                    yield array_values($row);
                }
            }


            public function iterateAssociative(): \Traversable
            {
                foreach ($this->rows as $row) {
                    yield $row;
                }
            }
        };

    }//end makeFakeResult()


    /**
     * Single-row convenience wrapper.
     *
     * @param array<string,mixed>|false $row Row, or false for an empty result.
     *
     * @return object
     */
    private function makeResult($row): object
    {
        return $this->makeFakeResult($row === false ? [] : [$row]);

    }//end makeResult()


    /**
     * Multi-row convenience wrapper for the schema-slug lookup loop.
     *
     * @param array<int,array<string,mixed>> $rows Rows.
     *
     * @return object
     */
    private function makeMultiResult(array $rows): object
    {
        return $this->makeFakeResult($rows);

    }//end makeMultiResult()


    /**
     * Build a fluent IQueryBuilder mock whose select/from/where chain is inert
     * and whose executeQuery() / executeStatement() behaviour is driven by a
     * callback dispatched on the first from()-table seen.
     *
     * For insert() builders, captures the `object` named-parameter (the JSON body)
     * into $this->insertedBodies.
     *
     * @param callable $resolveQuery fn(string $fromTable, array $selectFields): IResult
     *
     * @return IQueryBuilder|\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeQb(callable $resolveQuery)
    {
        $qb = $this->createMock(IQueryBuilder::class);

        $state = (object) ['from' => '', 'select' => [], 'insert' => false, 'values' => []];

        $qb->method('select')->willReturnCallback(
            function (...$fields) use ($qb, $state) {
                $state->select = $fields;
                return $qb;
            }
        );
        $qb->method('from')->willReturnCallback(
            function ($table) use ($qb, $state) {
                if ($state->from === '') {
                    $state->from = (string) $table;
                }

                return $qb;
            }
        );
        $qb->method('insert')->willReturnCallback(
            function ($table) use ($qb, $state) {
                $state->insert = true;
                $state->from   = (string) $table;
                return $qb;
            }
        );
        $qb->method('values')->willReturnCallback(
            function ($values) use ($qb, $state) {
                $state->values = $values;
                return $qb;
            }
        );
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('expr')->willReturn($this->makeExpr());
        // createNamedParameter passes the raw value through so insert capture works.
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('func')->willReturnCallback(
            function () {
                $func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
                $func->method('count')->willReturn(
                    $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class)
                );
                return $func;
            }
        );

        $qb->method('executeQuery')->willReturnCallback(
            function () use ($state, $resolveQuery) {
                return $resolveQuery($state->from, $state->select);
            }
        );

        $qb->method('executeStatement')->willReturnCallback(
            function () use ($state) {
                if ($state->insert === true && $state->from === 'openregister_objects') {
                    $object = $state->values['object'] ?? null;
                    if (is_string($object) === true) {
                        $decoded = json_decode($object, true);
                        if (is_array($decoded) === true) {
                            $this->insertedBodies[] = $decoded;
                        }
                    }
                }

                return 1;
            }
        );

        return $qb;

    }//end makeQb()


    /**
     * Wire up the IDBConnection so a single-row synchronization migration runs.
     *
     * The migrator issues, in order:
     *   1. registers SELECT          → {id: 1}
     *   2. schemas SELECT (loop)     → [{id:10, slug:'source'}, {id:20, slug:'synchronization'}]
     *   3. countLegacyRows           → {c: 1}
     *   4. fetchLegacyBatch          → [$syncRow]
     *   5. (integer-PK only) source uuid lookup → {uuid: '...-0042'}
     *   6. insertObjectRow           → executeStatement (captured)
     *
     * @param array<string,mixed> $syncRow      The single legacy synchronization row.
     * @param string|null         $sourceUuid   UUID returned by the integer-PK lookup (null = not found).
     *
     * @return void
     */
    private function wireSingleSyncRow(array $syncRow, ?string $sourceUuid='00000000-0000-0000-0000-000000000042'): void
    {
        // information_schema.tables probe used by tableExists() — return a truthy row.
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('execute')->willReturn($this->createMock(\OCP\DB\IResult::class));
        $stmt->method('fetch')->willReturn(['1' => 1]);
        $this->db->method('prepare')->willReturn($stmt);

        $resolve = function (string $from, array $select) use ($syncRow, $sourceUuid) {
            // registers lookup.
            if ($from === 'openregister_registers') {
                return $this->makeResult(['id' => 1]);
            }

            // schemas lookup loop.
            if ($from === 'openregister_schemas') {
                return $this->makeMultiResult(
                    [
                        ['id' => 10, 'slug' => 'source'],
                        ['id' => 20, 'slug' => 'synchronization'],
                    ]
                );
            }

            // integer-PK source uuid lookup.
            if ($from === 'openconnector_sources') {
                return $this->makeResult($sourceUuid === null ? false : ['uuid' => $sourceUuid]);
            }

            return $this->makeResult(false);
        };

        // countLegacyRows and fetchLegacyBatch both hit openconnector_synchronizations;
        // distinguish by tracking call order with a stateful closure.
        $syncCalls = 0;
        $resolveWithOrder = function (string $from, array $select) use ($resolve, $syncRow, $sourceUuid, &$syncCalls) {
            if ($from === 'openconnector_synchronizations') {
                $syncCalls++;
                if ($syncCalls === 1) {
                    return $this->makeResult(['c' => 1]);
                }

                return $this->makeBatchResult([$syncRow]);
            }

            return $resolve($from, $select);
        };

        $this->db->method('getQueryBuilder')->willReturnCallback(
            function () use ($resolveWithOrder) {
                return $this->makeQb($resolveWithOrder);
            }
        );

    }//end wireSingleSyncRow()


    /**
     * Build a fake result that returns a full batch via fetchAllAssociative().
     *
     * @param array<int,array<string,mixed>> $rows Batch rows.
     *
     * @return object
     */
    private function makeBatchResult(array $rows): object
    {
        return $this->makeFakeResult($rows);

    }//end makeBatchResult()


    /**
     * Construct the migrator under test.
     *
     * @return LegacyToRegisterMigrator
     */
    private function makeMigrator(): LegacyToRegisterMigrator
    {
        return new LegacyToRegisterMigrator($this->db, $this->appConfig, $this->logger);

    }//end makeMigrator()


    /**
     * GIVEN a synchronization row with source_id='42' and a source row with
     * uuid '...-0042' WHEN migrateAll runs for the synchronization entity THEN
     * the inserted OR object's sourceId equals the resolved source uuid.
     *
     * @return void
     */
    public function testIntegerPkSourceIdResolvesToSourceUuid(): void
    {
        $this->wireSingleSyncRow(
            [
                'id'        => 1,
                'uuid'      => 'aaaaaaaa-0000-0000-0000-000000000001',
                'source_id' => '42',
                'created'   => '2024-01-01 00:00:00',
                'updated'   => '2024-01-01 00:00:00',
            ],
            '00000000-0000-0000-0000-000000000042'
        );

        $this->makeMigrator()->migrateAll(dryRun: false, entitySlug: 'synchronization', batchSize: 1000);

        $this->assertCount(1, $this->insertedBodies);
        $this->assertSame(
            '00000000-0000-0000-0000-000000000042',
            $this->insertedBodies[0]['sourceId'],
            'integer-PK source_id must resolve to the looked-up source uuid'
        );

    }//end testIntegerPkSourceIdResolvesToSourceUuid()


    /**
     * GIVEN source_id='zaken/zaak' WHEN migrated THEN sourceId is preserved
     * unchanged (register-schema slug variant).
     *
     * @return void
     */
    public function testRegisterSchemaSourceIdPreservedUnchanged(): void
    {
        $this->wireSingleSyncRow(
            [
                'id'        => 2,
                'uuid'      => 'aaaaaaaa-0000-0000-0000-000000000002',
                'source_id' => 'zaken/zaak',
                'created'   => '2024-01-01 00:00:00',
                'updated'   => '2024-01-01 00:00:00',
            ]
        );

        $this->makeMigrator()->migrateAll(dryRun: false, entitySlug: 'synchronization', batchSize: 1000);

        $this->assertCount(1, $this->insertedBodies);
        $this->assertSame('zaken/zaak', $this->insertedBodies[0]['sourceId']);

    }//end testRegisterSchemaSourceIdPreservedUnchanged()


    /**
     * GIVEN source_id is a uuid WHEN migrated THEN sourceId passes through
     * unchanged (uuid variant).
     *
     * @return void
     */
    public function testUuidSourceIdPassesThroughUnchanged(): void
    {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $this->wireSingleSyncRow(
            [
                'id'        => 3,
                'uuid'      => 'aaaaaaaa-0000-0000-0000-000000000003',
                'source_id' => $uuid,
                'created'   => '2024-01-01 00:00:00',
                'updated'   => '2024-01-01 00:00:00',
            ]
        );

        $this->makeMigrator()->migrateAll(dryRun: false, entitySlug: 'synchronization', batchSize: 1000);

        $this->assertCount(1, $this->insertedBodies);
        $this->assertSame($uuid, $this->insertedBodies[0]['sourceId']);

    }//end testUuidSourceIdPassesThroughUnchanged()


    /**
     * GIVEN source_id='not-recognised' WHEN migrated THEN the row is still
     * migrated and the unrecognised value is preserved as-is.
     *
     * @return void
     */
    public function testUnrecognisedSourceIdStillMigratedAndPreserved(): void
    {
        $this->wireSingleSyncRow(
            [
                'id'        => 4,
                'uuid'      => 'aaaaaaaa-0000-0000-0000-000000000004',
                'source_id' => 'not-recognised',
                'created'   => '2024-01-01 00:00:00',
                'updated'   => '2024-01-01 00:00:00',
            ]
        );

        $results = $this->makeMigrator()->migrateAll(dryRun: false, entitySlug: 'synchronization', batchSize: 1000);

        $this->assertCount(1, $this->insertedBodies);
        $this->assertSame('not-recognised', $this->insertedBodies[0]['sourceId']);

        // The synchronization entity result row is present and the row was migrated.
        $syncResult = null;
        foreach ($results as $r) {
            if ($r['slug'] === 'synchronization') {
                $syncResult = $r;
                break;
            }
        }

        $this->assertNotNull($syncResult);
        $this->assertSame(1, $syncResult['migratedCount']);

    }//end testUnrecognisedSourceIdStillMigratedAndPreserved()


    /**
     * GIVEN an out-of-range batchSize WHEN migrateAll is called THEN an
     * InvalidArgumentException is thrown before any DB access.
     *
     * @return void
     */
    public function testBatchSizeOutOfRangeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeMigrator()->migrateAll(dryRun: false, entitySlug: 'synchronization', batchSize: 50);

    }//end testBatchSizeOutOfRangeThrows()


    /**
     * GIVEN an entitySlug not present in ENTITY_ORDER WHEN migrateAll is called
     * THEN an InvalidArgumentException is thrown.
     *
     * @return void
     */
    public function testUnknownEntitySlugThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeMigrator()->migrateAll(dryRun: false, entitySlug: 'does-not-exist', batchSize: 1000);

    }//end testUnknownEntitySlugThrows()
}//end class
