<?php

/**
 * Regression tests for the endoflife-date-source preset's runtime behaviour:
 * per-product SynchronizationContract identity isolation, idempotent
 * re-sync, and the raised (0.5) deletion-ratio guard.
 *
 * Exercises the REAL SynchronizationService::synchronize() end to end (real
 * MappingService, real per-item identity/contract/target-write pipeline)
 * against two of this preset's seeded product triples
 * (`endoflife-date-python-cycles` / `endoflife-date-nodejs-cycles`), using a
 * small stateful in-memory OR fake — mirroring the harness established by
 * SynchronizationServiceOriginIdMatchingTest.php — so the assertions cover
 * the SEEDED CONFIGURATION's behaviour, not just the (already-covered)
 * generic engine mechanics.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-repeated-syncs-upsert-idempotently-and-garbage-collect-soft-deleted-cycles
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-each-curated-product-syncs-its-cycles-via-a-dedicated-engine-native-synchronization
 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-repeated-syncs-upsert-idempotently-and-garbage-collect-soft-deleted-cycles
 */
class EndoflifeDateSyncTest extends TestCase {

	private const SOURCE_UUID = 'source-uuid-endoflife-date';
	private const SYNC_PYTHON = 'endoflife-date-python-cycles';
	private const SYNC_NODEJS = 'endoflife-date-nodejs-cycles';

	/**
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * @var ORObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * In-memory synchronization_contract store: uuid => payload.
	 *
	 * @var array<string, array>
	 */
	private array $contractStore = [];

	/**
	 * In-memory eolCycle target store: uuid => payload.
	 *
	 * @var array<string, array>
	 */
	private array $eolCycleStore = [];

	/**
	 * Per-endpoint fetch-call counters, so the first call for an endpoint
	 * returns the fixture and every subsequent call returns an empty page
	 * (natural pagination termination — mirrors the established
	 * stubSourceObject() convention).
	 *
	 * @var array<string, int>
	 */
	private array $endpointCallCounts = [];

	/**
	 * Per-endpoint cycle payloads the mocked CallService should serve.
	 *
	 * @var array<string, array>
	 */
	private array $endpointFixtures = [];

	/**
	 * @var array<string, array>
	 */
	private array $syncPayloads = [];

	/**
	 * @var array<string, array>
	 */
	private array $mappingPayloads = [];

	/**
	 * Build the exact per-product mapping recipe seeded in
	 * lib/Settings/register.d/endoflife-date-source-cycles.json.
	 *
	 * @param string $productSlug The product slug (literal `product` value).
	 *
	 * @return array
	 */
	private function buildProductMapping(string $productSlug): array {
		return [
			'mapping' => [
				'product' => $productSlug,
				'cycle' => 'cycle',
				'releaseDate' => 'releaseDate',
				'eol' => 'eol',
				'support' => 'support',
				'latest' => 'latest',
				'latestReleaseDate' => 'latestReleaseDate',
				'lts' => 'lts',
				'discontinued' => "{{ discontinued|default('') }}",
			],
			'cast' => [
				'eol' => 'string',
				'support' => 'string',
				'discontinued' => 'string',
			],
			'passThrough' => false,
		];

	}//end buildProductMapping()

	/**
	 * Build the exact per-product synchronization payload seeded in the
	 * fragment (design.md Seed Data / Decision 5/7).
	 *
	 * @param string $productSlug The product slug.
	 * @param string $syncId The synchronization's `@self.slug`/id.
	 * @param float $deletionRatioThreshold The deletion-ratio override.
	 *
	 * @return array
	 */
	private function buildSyncPayload(string $productSlug, string $syncId, float $deletionRatioThreshold = 0.5): array {
		return [
			'id' => $syncId,
			'uuid' => $syncId,
			'sourceId' => self::SOURCE_UUID,
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => 'openconnector/eolCycle',
			'sourceTargetMapping' => $syncId . '-mapping',
			'sourceConfig' => [
				'endpoint' => "/{$productSlug}.json",
				'resultsPosition' => '_root',
				'idPosition' => 'cycle',
				'deletionRatioThreshold' => $deletionRatioThreshold,
			],
		];

	}//end buildSyncPayload()

	/**
	 * Map ORObjectService::find() parameter names to positional indices
	 * (production calls with named args; the mock stub receives the
	 * expanded positional array).
	 *
	 * @return array<string, int>
	 */
	private function findParamPositions(): array {
		$rm = new ReflectionMethod(ORObjectService::class, 'find');
		$positions = [];
		foreach ($rm->getParameters() as $i => $p) {
			$positions[$p->getName()] = $i;
		}

		return $positions;
	}//end findParamPositions()

	/**
	 * Map CallService::call() parameter names to positional indices.
	 *
	 * @return array<string, int>
	 */
	private function callParamPositions(): array {
		$rm = new ReflectionMethod(CallService::class, 'call');
		$positions = [];
		foreach ($rm->getParameters() as $i => $p) {
			$positions[$p->getName()] = $i;
		}

		return $positions;
	}//end callParamPositions()

	/**
	 * Set up the stateful OR fake + a REAL SynchronizationService/MappingService
	 * pair (only network I/O and OR persistence are faked).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->contractStore = [];
		$this->eolCycleStore = [];
		$this->endpointCallCounts = [];
		$this->endpointFixtures = [];

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		$this->syncPayloads = [
			self::SYNC_PYTHON => $this->buildSyncPayload('python', self::SYNC_PYTHON),
			self::SYNC_NODEJS => $this->buildSyncPayload('nodejs', self::SYNC_NODEJS),
		];

		$this->mappingPayloads = [
			self::SYNC_PYTHON . '-mapping' => $this->buildProductMapping('python'),
			self::SYNC_NODEJS . '-mapping' => $this->buildProductMapping('nodejs'),
		];

		// Stateful saveObject: contracts AND eolCycle target writes are both
		// recorded and replayed, exactly like the origin-id-matching harness
		// does for contracts alone.
		// The BULK path, which is how target and contract writes actually land
		// now: the engine buffers them for the duration of a run and flushes
		// through `saveObjects()`. Stubbing only the singular left both stores
		// empty while the run reported success, so two tests here asserted
		// `actual size 0 matches expected size 2` and read as sync bugs.
		//
		// Keyed by uuid, mirroring the real bulk save's `deduplicateIds: true`:
		// two writes of one object must collapse to ONE row, or a store that
		// appended would report a duplicate that production does not create.
		$this->orObjectService->method('saveObjects')->willReturnCallback(
			function (array $objects, $register = null, $schema = null, ...$rest): array {
				$saved = [];
				foreach ($objects as $object) {
					$key = ($object['uuid'] ?? ($object['id'] ?? null));

					if ((string)$schema === 'synchronization_contract') {
						$key = ($key ?? 'contract-' . (count($this->contractStore) + 1));
						$this->contractStore[$key] = $object;
					} elseif ((string)$schema === 'eolCycle') {
						$key = ($key ?? 'eolcycle-' . (count($this->eolCycleStore) + 1));
						$this->eolCycleStore[$key] = $object;
					}

					$saved[] = ObjectServiceMockBuilder::objectEntity($this, $object, (string)$key);
				}

				return ['saved' => $saved, 'errors' => [], 'statistics' => ['saved' => count($saved)]];
			}
		);

		$this->orObjectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, ...$rest) {
				if ($schema === 'synchronization_contract') {
					$key = ($uuid ?? ($object['uuid'] ?? 'contract-' . (count($this->contractStore) + 1)));
					$this->contractStore[$key] = $object;

					return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$key);
				}

				if ($schema === 'eolCycle') {
					$key = ($uuid ?? ('eolcycle-' . (count($this->eolCycleStore) + 1)));
					$this->eolCycleStore[$key] = $object;

					return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$key);
				}

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					is_array($object) ? $object : [],
					(string)($uuid ?? ($object['uuid'] ?? 'saved-uuid'))
				);
			}
		);

		// findAll replays the contract store for contract lookups (originId /
		// synchronizationId filters); everything else finds nothing.
		$this->orObjectService->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest) {
				$filters = ($config['filters'] ?? []);

				if (isset($filters['originId']) === true || isset($filters['synchronizationId']) === true) {
					$matches = [];
					foreach ($this->contractStore as $uuid => $payload) {
						// `originId` may be a SINGLE id or a LIST of them: the engine
						// warms a contract index for a whole page in one query, so
						// it filters on an array. Comparing that with `!==` against
						// a scalar never matches, the index comes back empty, and
						// the engine correctly concludes no contract exists — then
						// creates a second one. That reads as a lost-idempotency
						// bug in the sync rather than a gap in this double.
						$wantedOriginIds = ($filters['originId'] ?? null);
						if ($wantedOriginIds !== null && is_array($wantedOriginIds) === false) {
							$wantedOriginIds = [$wantedOriginIds];
						}

						if ($wantedOriginIds !== null
							&& in_array(($payload['originId'] ?? null), $wantedOriginIds, true) === false
						) {
							continue;
						}

						if (isset($filters['synchronizationId']) === true
							&& ($payload['synchronizationId'] ?? null) !== $filters['synchronizationId']
						) {
							continue;
						}

						$matches[] = ObjectServiceMockBuilder::objectEntity($this, $payload, (string)$uuid);
					}

					return ['results' => $matches, 'total' => count($matches)];
				}

				return ['results' => [], 'total' => 0];
			}
		);

		// find() dispatches by schema: the source, either synchronization, or
		// either mapping — matching every lookup the real engine performs
		// for this preset's seeded configuration.
		$findPos = $this->findParamPositions();
		$this->orObjectService->method('find')->willReturnCallback(
			function (...$args) use ($findPos) {
				$id = ($args[$findPos['id']] ?? null);
				$schema = ($args[$findPos['schema']] ?? null);

				if ($schema === 'source') {
					return ObjectServiceMockBuilder::objectEntity(
						$this,
						['location' => 'https://endoflife.date/api', 'auth' => 'none', 'isEnabled' => true],
						self::SOURCE_UUID
					);
				}

				if ($schema === 'synchronization' && isset($this->syncPayloads[$id]) === true) {
					return ObjectServiceMockBuilder::objectEntity($this, $this->syncPayloads[$id], (string)$id);
				}

				if ($schema === 'mapping' && isset($this->mappingPayloads[$id]) === true) {
					return ObjectServiceMockBuilder::objectEntity($this, $this->mappingPayloads[$id], (string)$id);
				}

				// deleteInvalidObjects()'s per-target scope-check: any
				// eolCycle target id is treated as in-scope (this harness
				// never seeds a foreign-scope UUID collision — that guard
				// is generic engine behaviour, already covered by
				// SynchronizationServiceCleanupTest).
				if ($schema === 'eolCycle') {
					return ObjectServiceMockBuilder::objectEntity($this, [], (string)$id);
				}

				return null;
			}
		);

		// CallService::call dispatches by endpoint: first call for a given
		// endpoint returns the configured fixture array, every subsequent
		// call for the SAME endpoint returns an empty page (natural
		// pagination termination).
		$callPos = $this->callParamPositions();
		$this->callService->method('call')->willReturnCallback(
			function (...$args) use ($callPos) {
				$endpoint = ($args[$callPos['endpoint']] ?? '');
				$this->endpointCallCounts[$endpoint] = (($this->endpointCallCounts[$endpoint] ?? 0) + 1);

				$items = [];
				if ($this->endpointCallCounts[$endpoint] === 1) {
					$items = ($this->endpointFixtures[$endpoint] ?? []);
				}

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					[
						'response' => [
							'statusCode' => 200,
							'body' => json_encode($items),
							'encoding' => 'UTF-8',
							'headers' => [],
						],
					],
					'call-log-' . $endpoint . '-' . $this->endpointCallCounts[$endpoint]
				);
			}
		);

		$loader = new ArrayLoader([]);
		$fileService = $this->createMock(FileService::class);
		$ocObjectService = $this->createMock(ObjectService::class);
		$mappingService = new MappingService(
			$loader,
			$this->callService,
			$fileService,
			$ocObjectService,
			$this->orObjectService,
			$this->createMock(\OCA\OpenConnector\Service\SynchronizationContractService::class),
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);
		$logOrService = ObjectServiceMockBuilder::make($this);
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$session = $this->createMock(\OCP\ISession::class);
		$logService = new SynchronizationLogService($logOrService, $userSession, $session);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$this->service = new SynchronizationService(
			$this->callService,
			$mappingService,
			$container,
			$this->orObjectService,
			$ocObjectService,
			$logger,
			$logService,
			$appConfig,
			$this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
		);

	}//end setUp()

	/**
	 * Task 5 / TC-8: two curated products whose fetched cycle lists share a
	 * coincidentally-identical cycle label ("20") never collide — each
	 * product's dedicated Synchronization (distinct synchronizationId) keeps
	 * their SynchronizationContracts, and therefore their eolCycle target
	 * objects, fully independent.
	 *
	 * @return void
	 */
	public function testTwoProductsSharingACycleLabelNeverCollide(): void {
		$this->endpointFixtures['/python.json'] = [
			['cycle' => '20', 'releaseDate' => '2020-10-05', 'eol' => '2025-10-05', 'support' => '2022-10-05', 'latest' => '20.0.0', 'latestReleaseDate' => '2020-10-05', 'lts' => false],
		];
		$this->endpointFixtures['/nodejs.json'] = [
			['cycle' => '20', 'releaseDate' => '2023-04-18', 'eol' => '2026-04-30', 'support' => '2024-10-24', 'latest' => '20.19.5', 'latestReleaseDate' => '2025-07-22', 'lts' => '2023-10-24'],
		];

		$pythonResult = $this->service->synchronize(synchronization: $this->syncPayloads[self::SYNC_PYTHON]);
		$nodejsResult = $this->service->synchronize(synchronization: $this->syncPayloads[self::SYNC_NODEJS]);

		$this->assertSame(1, $pythonResult['result']['objects']['created']);
		$this->assertSame(1, $nodejsResult['result']['objects']['created']);

		$this->assertCount(2, $this->contractStore, 'Two distinct contracts must exist despite the shared cycle label');
		$this->assertCount(2, $this->eolCycleStore, 'Two distinct eolCycle target objects must exist');

		$products = array_map(static fn (array $o) => ($o['product'] ?? null), array_values($this->eolCycleStore));
		sort($products);
		$this->assertSame(['nodejs', 'python'], $products);

		$cycles = array_map(static fn (array $o) => ($o['cycle'] ?? null), array_values($this->eolCycleStore));
		$this->assertSame(['20', '20'], $cycles, 'Both target objects correctly carry the shared cycle label');

		// Neither overwrote the other's target: two distinct target uuids.
		$this->assertCount(2, array_unique(array_keys($this->eolCycleStore)));

	}//end testTwoProductsSharingACycleLabelNeverCollide()

	/**
	 * Task 5 acceptance criterion 2 / TC-9: re-running the same product's
	 * synchronization against unchanged source data produces no duplicate
	 * eolCycle objects or contracts.
	 *
	 * @return void
	 */
	public function testRepeatedSyncIsIdempotent(): void {
		$this->endpointFixtures['/python.json'] = [
			['cycle' => '3.14', 'releaseDate' => '2025-10-07', 'eol' => '2030-10-31', 'support' => '2027-10-01', 'latest' => '3.14.6', 'latestReleaseDate' => '2026-06-10', 'lts' => false],
			['cycle' => '3.13', 'releaseDate' => '2024-10-07', 'eol' => '2029-10-31', 'support' => '2026-10-01', 'latest' => '3.13.9', 'latestReleaseDate' => '2026-01-10', 'lts' => false],
		];

		$first = $this->service->synchronize(synchronization: $this->syncPayloads[self::SYNC_PYTHON]);
		$this->assertSame(2, $first['result']['objects']['created']);
		$this->assertCount(2, $this->contractStore);
		$this->assertCount(2, $this->eolCycleStore);

		// Second run: the mocked CallService serves an empty page for
		// subsequent calls to the same endpoint, so re-configure the fixture
		// to be served again (simulating the source returning unchanged data
		// on a second, independent daily run).
		$this->endpointCallCounts['/python.json'] = 0;

		$second = $this->service->synchronize(synchronization: $this->syncPayloads[self::SYNC_PYTHON]);

		$this->assertSame(0, $second['result']['objects']['created'], 'No new objects on the second, unchanged run');
		$this->assertSame(2, $second['result']['objects']['skipped'], 'Both cycles match their existing, unchanged contract');
		$this->assertCount(2, $this->contractStore, 'Still exactly 2 contracts — no duplicates');
		$this->assertCount(2, $this->eolCycleStore, 'Still exactly 2 eolCycle objects — no duplicates');

	}//end testRepeatedSyncIsIdempotent()

	/**
	 * Seed N pre-existing python-cycles contracts (each with its own
	 * cycle-label originId and a distinct targetId), so
	 * deleteInvalidObjects() has real prior state to diff against.
	 *
	 * @param int $count Number of contracts to seed.
	 *
	 * @return string[] The seeded contracts' targetIds, in order.
	 */
	private function seedPythonContracts(int $count): array {
		$targetIds = [];
		for ($i = 1; $i <= $count; $i++) {
			$targetId = 'eolcycle-target-' . $i;
			$targetIds[] = $targetId;

			$this->contractStore['contract-' . $i] = [
				'uuid' => 'contract-' . $i,
				'synchronizationId' => self::SYNC_PYTHON,
				'originId' => 'cycle-' . $i,
				'targetId' => $targetId,
			];
		}

		return $targetIds;
	}//end seedPythonContracts()

	/**
	 * Task 5 acceptance criterion 3 / TC-10 (spec.md scenario): 4 existing
	 * eolCycle contracts, the next complete fetch no longer reports 1 of
	 * them (25%) — the now-absent cycle's object IS deleted, because 25% is
	 * within the seeded, raised `0.5` deletionRatioThreshold (whereas the
	 * engine's unmodified `0.10` default would have blocked it).
	 *
	 * @return void
	 */
	public function testRetiredCycleIsGarbageCollectedWithinRaisedDeletionRatioGuard(): void {
		$targetIds = $this->seedPythonContracts(4);
		$survivors = array_slice($targetIds, 0, 3);

		$guardInfo = null;
		$deleted = $this->service->deleteInvalidObjects(
			$this->syncPayloads[self::SYNC_PYTHON],
			$survivors,
			false,
			[],
			true,
			false,
			$guardInfo
		);

		$this->assertSame(1, $deleted, '25% removal is within the raised 0.5 threshold — the retired cycle IS deleted');
		$this->assertFalse($guardInfo['guarded']);
		$this->assertEqualsWithDelta(0.5, $guardInfo['threshold'], 0.0001, 'The seeded deletionRatioThreshold (0.5) must be honoured, not the engine default (0.10)');
		$this->assertEqualsWithDelta(0.25, $guardInfo['ratio'], 0.0001);

	}//end testRetiredCycleIsGarbageCollectedWithinRaisedDeletionRatioGuard()

	/**
	 * Task 5 acceptance criterion 4 / TC-11: a mocked non-2xx/incomplete
	 * fetch (`fetchComplete: false`) never triggers deletion for that run,
	 * regardless of how many contracts would otherwise look removed —
	 * unchanged synchronization-engine REQ-010 behaviour; this test only
	 * confirms the preset's own configuration does not accidentally disable
	 * it.
	 *
	 * @return void
	 */
	public function testIncompleteFetchNeverTriggersDeletion(): void {
		$this->seedPythonContracts(4);

		$guardInfo = null;
		$deleted = $this->service->deleteInvalidObjects(
			$this->syncPayloads[self::SYNC_PYTHON],
			[],
			false,
			[],
			false,
			false,
			$guardInfo
		);

		$this->assertSame(0, $deleted, 'No object is deleted for an incomplete fetch, even though the diff would otherwise be 100%');
		$this->assertTrue($guardInfo['guarded']);
		$this->assertSame('fetch_incomplete', $guardInfo['reason']);

	}//end testIncompleteFetchNeverTriggersDeletion()
}//end class
