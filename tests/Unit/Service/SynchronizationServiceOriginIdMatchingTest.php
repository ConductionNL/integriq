<?php

/**
 * Unit tests pinning originId-based contract matching on re-sync (#1016) and
 * the read-only duplicate-contract detector (REQ-013).
 *
 * Design.md Decision 5 (change sync-safety-guardrails): static review of
 * `findContractBySyncAndOrigin()` / `processSynchronizationObject()` found the
 * lookup-before-create flow already correct — these tests are the authoritative
 * verification. They pin that resyncing the same originId under the same
 * synchronization reuses the existing contract (exactly one create across two
 * runs), including the `findContractByOriginIdOnly` variant, and that
 * pre-existing duplicate contracts are logged for admin review but never
 * auto-deleted.
 *
 * The tests use a small stateful in-memory contract store built on the OR
 * ObjectService mock (saveObject records, findAll replays) so both runs see
 * genuinely persisted state. Verified against HEAD behaviour — where observed
 * behaviour differs from the naive expectation it is documented inline, not
 * silently asserted away.
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
 * Tests #1016 originId contract matching + REQ-013 duplicate surfacing.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-duplicate-synchronization-contracts-are-surfaced-never-silently-removed-req-013
 */
class SynchronizationServiceOriginIdMatchingTest extends TestCase {

	private const SYNC_ID = 'sync-uuid-origin';

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
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * In-memory contract store: uuid => contract payload array.
	 *
	 * @var array<string, array>
	 */
	private array $contractStore = [];

	/**
	 * Number of saveObject() calls against the synchronization_contract schema.
	 *
	 * @var integer
	 */
	private int $contractSaves = 0;

	/**
	 * Captured logger->warning() invocations: [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * Set up the stateful OR fake + partial-mocked service (only
	 * `updateTarget()` is isolated; contract lookup/persist logic is real).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->contractStore = [];
		$this->contractSaves = 0;
		$this->warnings = [];

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		// Stateful saveObject: contract writes are recorded and replayed by
		// findAll below, so a second run sees the first run's contract.
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, ...$rest) {
				if ($schema === 'synchronization_contract') {
					$this->contractSaves++;
					$key = ($uuid ?? ($object['uuid'] ?? 'contract-' . $this->contractSaves));
					$this->contractStore[$key] = $object;
				}

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					is_array($object) ? $object : [],
					(string)($uuid ?? ($object['uuid'] ?? 'saved-uuid'))
				);
			}
		);

		// findAll replays the contract store for contract lookups (filters
		// carrying an originId, or the cleanup pass's synchronizationId-only
		// filter); everything else (source-by-location) finds nothing.
		$this->orObjectService->method('findAll')->willReturnCallback(
			function (array $config = [], ...$rest) {
				$filters = ($config['filters'] ?? []);

				if (isset($filters['originId']) === true || isset($filters['synchronizationId']) === true) {
					$matches = [];
					foreach ($this->contractStore as $uuid => $payload) {
						if (isset($filters['originId']) === true && ($payload['originId'] ?? null) !== $filters['originId']) {
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

		// Source resolution for findSource()/callSourceObject().
		$this->orObjectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['location' => 'https://example.test', 'enabled' => true],
				'source-uuid-origin'
			)
		);

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) {
				$this->warnings[] = [$message, $context];
			}
		);

		$mappingService = $this->createMock(MappingService::class);
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectService::class);
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
					$this->logger,
					$logService,
					$appConfig,
					$this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
				]
			)
			->onlyMethods(['updateTarget'])
			->getMock();

		// Target writes are stubbed: the create path stamps a stable targetId
		// exactly like updateTargetOpenRegister() would.
		$this->service->method('updateTarget')->willReturnCallback(
			static fn (array $synchronizationContract, ...$rest) => array_merge(
				$synchronizationContract,
				['targetId' => 'target-1', 'targetLastAction' => 'create']
			)
		);
	}//end setUp()

	/**
	 * Synchronization payload; sourceConfig extras are merged in.
	 *
	 * @param array $sourceConfigExtra Extra sourceConfig keys.
	 *
	 * @return array
	 */
	private function makeSyncPayload(array $sourceConfigExtra = []): array {
		return [
			'id' => self::SYNC_ID,
			'uuid' => self::SYNC_ID,
			'sourceId' => 'source-uuid-origin',
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => array_merge(
				[
					'endpoint' => '/items',
					'resultsPosition' => 'items',
					'usesPagination' => true,
				],
				$sourceConfigExtra
			),
		];
	}//end makeSyncPayload()

	/**
	 * Stub the source to return the same single object on every run (page 1),
	 * followed by a naturally-empty page.
	 *
	 * @param array $object The source object (must carry a stable `id`).
	 *
	 * @return void
	 */
	private function stubSourceObject(array $object): void {
		$page = 0;
		$this->callService->method('call')->willReturnCallback(
			function (...$args) use (&$page, $object) {
				$page++;
				// Page 1 of each run returns the object; page 2 is empty. A
				// run makes 2 calls, so odd pages carry the object.
				$items = (($page % 2) === 1) ? [$object] : [];

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
	}//end stubSourceObject()

	/**
	 * Replicate SynchronizationService::hashObject() (recursive ksort + md5
	 * of serialize) so tests can pre-seed contracts whose originHash matches
	 * the object the stubbed source returns.
	 *
	 * @param array $object The object to hash.
	 *
	 * @return string
	 */
	private function hashLikeEngine(array $object): string {
		$sort = function (array &$array) use (&$sort): void {
			ksort($array);
			foreach (array_keys($array) as $key) {
				if (is_array($array[$key]) === true) {
					$sort($array[$key]);
				}
			}
		};
		$sort($object);

		return md5(serialize($object));
	}//end hashLikeEngine()

	/**
	 * TC-16 (#1016 verification): resyncing the same originId twice under the
	 * same synchronization reuses the existing contract — exactly one
	 * contract-create across both runs, and the second run's lookup returns
	 * the persisted contract (resultAction `skip`, no second persist).
	 *
	 * @return void
	 */
	public function testResyncSameOriginIdMatchesExistingContract(): void {
		$this->stubSourceObject(['id' => 'origin-1', 'name' => 'Object One']);

		$first = $this->service->synchronize(synchronization: $this->makeSyncPayload());
		$this->assertSame(1, $first['result']['objects']['created'], 'First run creates the contract');
		// ocon#109: the first run writes the SAME contract twice — the identity
		// mapping is persisted straight after updateTarget() (so a throw in the
		// `after` rules can no longer lose it and cause a duplicate object on the
		// next run), then updated with the rule outcomes at the end of
		// synchronizeContract(). Both writes carry the same uuid, so exactly ONE
		// contract row exists — asserted via $contractStore below, which is the
		// invariant that actually matters.
		$this->assertSame(2, $this->contractSaves, 'First run writes the contract twice: identity, then outcomes');
		$this->assertCount(1, $this->contractStore, 'but only one contract ROW exists');

		$second = $this->service->synchronize(synchronization: $this->makeSyncPayload());

		// The second run matches the existing contract and returns `skip` before
		// updateTarget(), so it performs no contract write at all.
		$this->assertSame(2, $this->contractSaves, 'Second run must NOT persist another contract');
		$this->assertCount(1, $this->contractStore, 'Exactly one contract exists after both runs');
		$this->assertSame(
			1,
			$second['result']['objects']['skipped'],
			'Second run matches the existing contract (unchanged hash) and skips'
		);
		$this->assertSame(0, $second['result']['objects']['created']);
	}//end testResyncSameOriginIdMatchesExistingContract()

	/**
	 * TC-16 variant: the `sourceConfig.findContractByOriginIdOnly` lookup
	 * branch (originId-only filter) also reuses the existing contract.
	 *
	 * @return void
	 */
	public function testResyncWithFindContractByOriginIdOnlyMatchesExistingContract(): void {
		$this->stubSourceObject(['id' => 'origin-1', 'name' => 'Object One']);
		$payload = $this->makeSyncPayload(['findContractByOriginIdOnly' => true]);

		$this->service->synchronize(synchronization: $payload);
		$this->service->synchronize(synchronization: $payload);

		// Two writes from the first run only (identity + outcomes, same uuid); the
		// second run skips before updateTarget(). One contract ROW either way.
		$this->assertSame(2, $this->contractSaves, 'originId-only lookup must also reuse the existing contract');
		$this->assertCount(1, $this->contractStore);
	}//end testResyncWithFindContractByOriginIdOnlyMatchesExistingContract()

	/**
	 * TC (Task 14, exploratory per design.md Decision 5): a resync after the
	 * target object was deleted out-of-band (contract still present) does not
	 * error uncaught.
	 *
	 * OBSERVED BEHAVIOUR (documented, not corrected here): when the source
	 * hash is unchanged, `synchronizeContract()`'s skip branch trusts the
	 * contract's stored `targetId`/`targetHash` and never checks whether the
	 * target still exists — the run reports `skip` and does NOT recreate the
	 * missing target. Reconciliation of out-of-band deletions is a separate
	 * concern; this test pins that the engine at least degrades gracefully.
	 *
	 * @return void
	 */
	public function testResyncAfterOutOfBandTargetDeletionDoesNotCrash(): void {
		$object = ['id' => 'origin-1', 'name' => 'Object One'];
		$this->stubSourceObject($object);

		// Pre-seed the contract as a fully-synced previous run would have
		// left it — but its target object no longer exists anywhere.
		$this->contractStore['contract-uuid-gone'] = [
			'uuid' => 'contract-uuid-gone',
			'synchronizationId' => self::SYNC_ID,
			'originId' => 'origin-1',
			'originHash' => $this->hashLikeEngine($object),
			'sourceLastChecked' => '2099-01-01T00:00:00+00:00',
			'targetId' => 'target-deleted-out-of-band',
			'targetHash' => 'hash-of-deleted-target',
		];

		$result = $this->service->synchronize(synchronization: $this->makeSyncPayload());

		$this->assertSame('Success', $result['message'], 'The run completes without an uncaught error');
		$this->assertSame(1, $result['result']['objects']['skipped'], 'Observed: unchanged hash skips, no recreate');
		$this->assertSame(0, $this->contractSaves, 'No duplicate contract is created for the origin id');
	}//end testResyncAfterOutOfBandTargetDeletionDoesNotCrash()

	/**
	 * TC-17 (REQ-013): two pre-existing contracts for the same
	 * (synchronizationId, originId) pair are surfaced via a warning naming
	 * both contract ids — and neither is deleted.
	 *
	 * @return void
	 */
	public function testDuplicateContractsLoggedNotDeleted(): void {
		$object = ['id' => 'origin-1', 'name' => 'Object One'];
		$this->stubSourceObject($object);

		// Two duplicates for the same pair (both pointing at the same target,
		// as a real duplicate-creation bug/race would produce).
		$shared = [
			'synchronizationId' => self::SYNC_ID,
			'originId' => 'origin-1',
			'originHash' => $this->hashLikeEngine($object),
			'sourceLastChecked' => '2099-01-01T00:00:00+00:00',
			'targetId' => 'target-1',
			'targetHash' => 'target-hash-1',
		];
		$this->contractStore['contract-uuid-dup-a'] = array_merge($shared, ['uuid' => 'contract-uuid-dup-a']);
		$this->contractStore['contract-uuid-dup-b'] = array_merge($shared, ['uuid' => 'contract-uuid-dup-b']);

		$this->orObjectService->expects($this->never())->method('deleteObject');

		$this->service->synchronize(synchronization: $this->makeSyncPayload());

		$duplicateWarnings = array_values(
			array_filter(
				$this->warnings,
				static fn (array $warning) => str_contains($warning[0], 'multiple synchronization contracts')
			)
		);

		$this->assertCount(1, $duplicateWarnings, 'Exactly one duplicate-contract warning is logged');
		$this->assertSame(self::SYNC_ID, $duplicateWarnings[0][1]['synchronizationId']);
		$this->assertSame('origin-1', $duplicateWarnings[0][1]['originId']);
		$this->assertContains('contract-uuid-dup-a', $duplicateWarnings[0][1]['contractIds']);
		$this->assertContains('contract-uuid-dup-b', $duplicateWarnings[0][1]['contractIds']);

		$this->assertCount(2, $this->contractStore, 'Neither duplicate contract is deleted or merged');
	}//end testDuplicateContractsLoggedNotDeleted()

	/**
	 * TC-17 control (REQ-013 second scenario): the common single-contract
	 * case logs no duplicate warning.
	 *
	 * @return void
	 */
	public function testSingleContractLogsNoDuplicateWarning(): void {
		$this->stubSourceObject(['id' => 'origin-1', 'name' => 'Object One']);

		$this->service->synchronize(synchronization: $this->makeSyncPayload());
		$this->service->synchronize(synchronization: $this->makeSyncPayload());

		$duplicateWarnings = array_filter(
			$this->warnings,
			static fn (array $warning) => str_contains($warning[0], 'multiple synchronization contracts')
		);

		$this->assertCount(0, $duplicateWarnings, 'No duplicate warning for the common single-contract case');
	}//end testSingleContractLogsNoDuplicateWarning()
}//end class
