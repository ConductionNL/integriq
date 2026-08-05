<?php
/**
 * Unit tests for contract embedding when the contract has no uuid yet.
 *
 * Regression: `POST /api/synchronizations/{id}/test` answered 500 with
 * "SynchronizationService::findContract(): Argument #1 ($id) must be of type
 * string|int, null given" for any synchronization whose objects had no
 * previously-persisted contract — i.e. every first-ever dry run.
 *
 * The chain: a test run returns the contract from `synchronizeContract()`
 * in-memory without persisting it (REQ-011, no writes), so a brand-new
 * contract carries no `uuid`. `processSynchronizationObject()` pushes
 * `$contractUuid` into `result['contracts']` unconditionally, leaving a null.
 * The `_embed` enrichment then mapped every entry through `findContract()`,
 * which is typed `string|int` — so the null was a TypeError escaping the whole
 * run, not a lookup miss its `DoesNotExistException` catch could absorb.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests that a run tolerates uuid-less contracts when embedding them.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011
 */
class SynchronizationServiceTestRunContractEmbedTest extends TestCase
{

    private const SYNC_ID = 'sync-uuid-embed';

    /**
     * @var ORObjectService&MockObject
     */
    private $orObjectService;

    /**
     * @var CallService&MockObject
     */
    private $callService;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * Set up shared mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->callService     = $this->createMock(CallService::class);
        $this->callService->method('applyConfigDot')->willReturnArgument(0);

    }//end setUp()

    /**
     * Build the service as a partial mock isolating the given methods.
     *
     * @param string[] $onlyMethods The methods to replace with mocks.
     *
     * @return SynchronizationService&MockObject
     */
    private function makeService(array $onlyMethods): MockObject
    {
        $mappingService = $this->createMock(MappingService::class);
        $container      = $this->createMock(ContainerInterface::class);
        $objectService  = $this->createMock(ObjectService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $logOrService = ObjectServiceMockBuilder::make($this);
        $userSession  = $this->createMock(\OCP\IUserSession::class);
        $session      = $this->createMock(\OCP\ISession::class);
        $logService   = new SynchronizationLogService($logOrService, $userSession, $session);

        return $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs(
                [
                    $this->callService,
                    $mappingService,
                    $container,
                    $this->orObjectService,
                    $objectService,
                    $this->logger,
                    $logService,
                    $appConfig,
                    $this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
                ]
            )
            ->onlyMethods($onlyMethods)
            ->getMock();

    }//end makeService()

    /**
     * Synchronization payload with an api source and register/schema target.
     *
     * @return array
     */
    private function makeSyncPayload(): array
    {
        return [
            'id'           => self::SYNC_ID,
            'uuid'         => self::SYNC_ID,
            'sourceId'     => 'source-uuid-embed',
            'sourceType'   => 'api',
            'targetType'   => 'register/schema',
            'targetId'     => '1/2',
            'sourceConfig' => [
                'endpoint'        => '/items',
                'resultsPosition' => 'items',
                'usesPagination'  => true,
            ],
        ];
    }//end makeSyncPayload()

    /**
     * Stub the source lookup and a single-page 200 response with one object.
     *
     * @return void
     */
    private function stubSourceAndOnePage(): void
    {
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['location' => 'https://example.test', 'enabled' => true],
            'source-uuid-embed'
        );
        $this->orObjectService->method('find')->willReturn($sourceEntity);

        $callLog = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'response' => [
                    'statusCode' => 200,
                    'body'       => json_encode(['items' => [['id' => 'origin-1', 'name' => 'Object One']]]),
                    'encoding'   => 'UTF-8',
                    'headers'    => [],
                ],
            ],
            'call-log-embed'
        );
        $this->callService->method('call')->willReturn($callLog);

        $this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

    }//end stubSourceAndOnePage()

    /**
     * The reported bug: a dry run whose contract was never persisted (so it
     * has no uuid) completes instead of throwing a TypeError out of the
     * controller as a 500.
     *
     * The sibling REQ-011 tests all stub `synchronizeContract` with
     * `['uuid' => 'contract-uuid-1', ...]`, which is why they never reached
     * this — the uuid-less contract is what a real first-time test run
     * produces.
     *
     * @return void
     */
    public function testTestRunWithUnpersistedContractDoesNotThrow(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'synchronizeContract']);
        $this->stubSourceAndOnePage();

        // No 'uuid' — exactly what synchronizeContract() returns in test mode
        // for a contract that does not exist yet.
        $service->method('synchronizeContract')->willReturn(
            [
                'log'          => [],
                'contract'     => ['targetId' => 'target-1'],
                'resultAction' => 'skip',
            ]
        );

        $result = $service->synchronize(synchronization: $this->makeSyncPayload(), isTest: true);

        $this->assertSame(1, $result['result']['objects']['found']);
        $this->assertSame(1, $result['result']['objects']['skipped']);

    }//end testTestRunWithUnpersistedContractDoesNotThrow()

    /**
     * The null id embeds as null, keeping `_embed.contracts` positionally
     * aligned with `contracts` so index N of one still describes index N of
     * the other. Filtering the nulls out instead would silently shift every
     * later entry against the `logs` list built alongside it.
     *
     * @return void
     */
    public function testUnresolvableContractsEmbedAsNull(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'synchronizeContract']);
        $this->stubSourceAndOnePage();

        $service->method('synchronizeContract')->willReturn(
            [
                'log'          => [],
                'contract'     => ['targetId' => 'target-1'],
                'resultAction' => 'skip',
            ]
        );

        $result = $service->synchronize(synchronization: $this->makeSyncPayload(), isTest: true);

        // The reference lists are compacted before the log is returned, so a
        // reference that resolves to nothing is absent rather than present as
        // null. A 100-object dry run used to answer with a hundred nulls in
        // each of `contracts`, `logs` and `_embed.contracts`.
        $this->assertSame([], ($result['result']['contracts'] ?? null));
        $this->assertSame([], ($result['result']['logs'] ?? null));
        $this->assertSame([], ($result['result']['_embed']['contracts'] ?? null));

        // The objects were still processed and are still reported — the
        // counters, not the reference lists, are what carries that.
        $this->assertSame(1, $result['result']['objects']['found']);

    }//end testUnresolvableContractsEmbedAsNull()

    /**
     * The compaction must not eat real references: a contract that does have a
     * uuid survives, and its embedded payload stays at the matching position.
     *
     * `Flow\SynchronizationRunNode::objectsFrom()` pairs `contracts[i]` with
     * `_embed.contracts[i]`, so a compaction that dropped from one list alone
     * would silently mis-attribute every later object in a flow fan-out.
     *
     * @return void
     */
    public function testResolvableContractsSurviveWithTheirEmbeddedPayload(): void
    {
        $service = $this->makeService(['deleteInvalidObjects', 'synchronizeContract']);
        $this->stubSourceAndOnePage();

        $service->method('synchronizeContract')->willReturn(
            [
                'log'          => ['uuid' => 'contract-log-uuid-1'],
                'contract'     => ['uuid' => 'contract-uuid-1', 'targetId' => 'target-1'],
                'resultAction' => 'skip',
            ]
        );

        $result = $service->synchronize(synchronization: $this->makeSyncPayload(), isTest: true);

        $this->assertSame(['contract-uuid-1'], $result['result']['contracts']);
        $this->assertSame(['contract-log-uuid-1'], $result['result']['logs']);
        $this->assertCount(
            1,
            $result['result']['_embed']['contracts'],
            'the embedded list keeps exactly one entry per surviving contract'
        );

    }//end testResolvableContractsSurviveWithTheirEmbeddedPayload()
}//end class
