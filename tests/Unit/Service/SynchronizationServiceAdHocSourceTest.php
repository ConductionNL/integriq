<?php

/**
 * Unit tests for ad-hoc Source resolution non-persistence.
 *
 * Reproduces ConductionNL/openconnector#1009: a caller-supplied ad-hoc
 * `source` location string that matches no configured Source must resolve to
 * a transient, in-memory source for that call only — it must never silently
 * persist a new, enabled Source object. Resolution against an existing,
 * admin-configured Source (matched by `location`) is unchanged (spec REQ-012,
 * change sync-safety-guardrails).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-012: ad-hoc Source resolution never persists a new Source.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012
 */
class SynchronizationServiceAdHocSourceTest extends TestCase {

	private const SYNC_ID = 'sync-uuid-adhoc';
	private const ADHOC_URL = 'https://example.test/ad-hoc-feed';
	private const MATCHED_URL = 'https://example.test/configured-feed';
	private const MATCHED_UUID = 'existing-source-uuid';

	/**
	 * @var SynchronizationService&MockObject
	 */
	private $service;

	/**
	 * @var ORObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * Schemas passed to every saveObject() call, in order.
	 *
	 * @var string[]
	 */
	private array $savedSchemas = [];

	/**
	 * The source entities CallService::call() was invoked with, in order.
	 *
	 * @var ObjectEntity[]
	 */
	private array $calledSources = [];

	/**
	 * Set up test fixtures: partial mock isolating `synchronizeContract()`
	 * (contract mechanics are covered elsewhere) with recording stubs for
	 * saveObject schemas and CallService source arguments.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->savedSchemas = [];
		$this->calledSources = [];

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		// Record every saveObject schema so the tests can assert no `source`
		// write ever happens (other schemas — e.g. `synchronization` for the
		// non-test run's targetLastSynced — are legitimate).
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ...$rest) {
				$this->savedSchemas[] = (string)$schema;

				return ObjectServiceMockBuilder::objectEntity($this, is_array($object) ? $object : [], 'saved-uuid');
			}
		);

		// Record the source entity of every CallService call and return one
		// page with a single object, then a naturally-empty page.
		$page = 0;
		$this->callService->method('call')->willReturnCallback(
			function ($source, ...$rest) use (&$page) {
				$this->calledSources[] = $source;
				$page++;
				$items = ($page === 1) ? [['id' => 'origin-1']] : [];

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					[
						'response' => [
							'statusCode' => 200,
							'body' => json_encode(['items' => $items]),
							'encoding' => 'UTF-8',
							'headers' => [],
						],
					],
					'call-log-' . $page
				);
			}
		);

		$mappingService = $this->createMock(MappingService::class);
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$logOrService = ObjectServiceMockBuilder::make($this);
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$session = $this->createMock(\OCP\ISession::class);
		$logService = new SynchronizationLogService($logOrService, $userSession, $session);

		$this->service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$this->callService,
					$mappingService,
					$container,
					$this->orObjectService,
					$objectService,
					$logger,
					$logService,
					$appConfig,
					$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
				]
			)
			->onlyMethods(['synchronizeContract'])
			->getMock();

		$this->service->method('synchronizeContract')->willReturn(
			[
				'log' => [],
				'contract' => ['uuid' => 'contract-uuid-1', 'targetId' => 'target-1'],
				'resultAction' => 'skip',
			]
		);
	}//end setUp()

	/**
	 * Synchronization payload; `sourceId` is intentionally absent because the
	 * ad-hoc `source` parameter overrides it.
	 *
	 * @return array
	 */
	private function makeSyncPayload(): array {
		return [
			'id' => self::SYNC_ID,
			'uuid' => self::SYNC_ID,
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => [
				'endpoint' => '/items',
				'resultsPosition' => 'items',
				'usesPagination' => true,
			],
		];
	}//end makeSyncPayload()

	/**
	 * TC-14: an ad-hoc location matching no configured Source fetches
	 * successfully AND never persists a `source` object.
	 *
	 * @return void
	 */
	public function testUnmatchedAdHocLocationDoesNotPersistSource(): void {
		// The location lookup (and the contract lookups) find nothing.
		$this->orObjectService->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->synchronize(
			synchronization: $this->makeSyncPayload(),
			source: self::ADHOC_URL
		);

		// The fetch succeeded against the transient source: CallService was
		// invoked with an in-memory entity carrying the ad-hoc location.
		$this->assertNotEmpty($this->calledSources, 'The run must fetch from the ad-hoc location');
		$this->assertSame(self::ADHOC_URL, $this->calledSources[0]->getObject()['location']);
		$this->assertTrue(
			($this->calledSources[0]->getObject()['_transient'] ?? false),
			'The resolved source must be the transient, never-persisted one'
		);
		$this->assertSame(1, $result['result']['objects']['found']);

		// The one thing #1009 forbids: persisting the ad-hoc Source.
		$this->assertNotContains('source', $this->savedSchemas, 'No Source object may be persisted for an ad-hoc location');
	}//end testUnmatchedAdHocLocationDoesNotPersistSource()

	/**
	 * TC-15: an ad-hoc location matching an existing configured Source reuses
	 * that Source unchanged (rate-limit watermark state included) and creates
	 * no new Source.
	 *
	 * @return void
	 */
	public function testMatchedAdHocLocationReusesExistingSource(): void {
		$existingSource = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'location' => self::MATCHED_URL,
				'enabled' => true,
				'rateLimitLimit' => 100,
				'rateLimitRemaining' => 50,
				'rateLimitReset' => (time() + 3600),
			],
			self::MATCHED_UUID
		);

		// The location lookup finds the configured Source; contract lookups
		// find nothing.
		$this->orObjectService->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest) use ($existingSource) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$existingSource], 'total' => 1];
				}

				return ['results' => [], 'total' => 0];
			}
		);

		// callSourceObject() re-resolves the persisted source by uuid.
		$this->orObjectService->method('find')->willReturn($existingSource);

		$result = $this->service->synchronize(
			synchronization: $this->makeSyncPayload(),
			source: self::MATCHED_URL
		);

		$this->assertNotEmpty($this->calledSources);
		$this->assertSame(
			self::MATCHED_UUID,
			$this->calledSources[0]->getUuid(),
			'The existing configured Source (with its rate-limit watermark) must be reused'
		);
		$this->assertSame(1, $result['result']['objects']['found']);
		$this->assertNotContains('source', $this->savedSchemas, 'No new Source object may be created for a matched location');
	}//end testMatchedAdHocLocationReusesExistingSource()
}//end class
