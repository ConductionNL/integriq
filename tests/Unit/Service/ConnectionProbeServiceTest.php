<?php

/**
 * Unit tests for ConnectionProbeService (connection-registry, umbrella D7 and D9).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Exception\ConnectionLinkException;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Service\ConnectionProbeService;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\ConnectionStatusResolver;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\SourceTestService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Breaker rule, probe cap and order, probe messages, and linking.
 */
class ConnectionProbeServiceTest extends TestCase {

	/**
	 * The store double.
	 *
	 * @var ConnectionStore&MockObject
	 */
	private ConnectionStore $store;

	/**
	 * The source test double.
	 *
	 * @var SourceTestService&MockObject
	 */
	private SourceTestService $sourceTest;

	/**
	 * The catalog double.
	 *
	 * @var CatalogRegistryService&MockObject
	 */
	private CatalogRegistryService $catalog;

	/**
	 * Sources by uuid.
	 *
	 * @var array<string,ObjectEntity>
	 */
	private array $sources = [];

	/**
	 * Saves the store received: [uuid, data].
	 *
	 * @var array<int,array{0:?string,1:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = $this->getMockBuilder(className: ConnectionStore::class)
			->disableOriginalConstructor()
			->onlyMethods(['findRows', 'findRow', 'save', 'findSource', 'findSourceBySlug', 'createSource'])
			->getMock();
		$this->store->method('findSource')->willReturnCallback(fn (string $uuid): ?ObjectEntity => $this->sources[$uuid] ?? null);
		$this->store->method('save')->willReturnCallback(
			function (array $data, ?string $uuid = null): string {
				$this->saves[] = [$uuid, $data];
				return (string)$uuid;
			}
		);

		$this->sourceTest = $this->createMock(originalClassName: SourceTestService::class);
		$this->catalog = $this->createMock(originalClassName: CatalogRegistryService::class);
	}//end setUp()

	/**
	 * Build the service with a real resolver and registry around the doubles.
	 *
	 * @return ConnectionProbeService
	 */
	private function makeService(): ConnectionProbeService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-09-14T12:00:00+00:00'));
		$resolver = new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time);

		$appManager = $this->createMock(originalClassName: \OCP\App\IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);
		$registry = new ConnectionRegistryService(
			appManager: $appManager,
			validator: new \OCA\Integriq\Service\ConnectionDeclarationValidator(),
			resolver: $resolver,
			store: $this->store,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		return new ConnectionProbeService(
			store: $this->store,
			registry: $registry,
			resolver: $resolver,
			sourceTest: $this->sourceTest,
			catalog: $this->catalog,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end makeService()

	/**
	 * Register a source object.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string,mixed> $data The source data.
	 *
	 * @return ObjectEntity
	 */
	private function addSource(string $uuid, array $data = []): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid($uuid);
		$source->setObject($data);
		$this->sources[$uuid] = $source;

		return $source;
	}//end addSource()

	/**
	 * A source test outcome.
	 *
	 * @param string $outcome The outcome constant.
	 * @param int|null $code The HTTP code.
	 * @param string $message The HTTP reason.
	 *
	 * @return array{outcome:string,result:?array,statusCode:?int,statusMessage:string,error:string}
	 */
	private function testOutcome(string $outcome, ?int $code, string $message = ''): array {
		return ['outcome' => $outcome, 'result' => [], 'statusCode' => $code, 'statusMessage' => $message, 'error' => 'boom'];
	}//end testOutcome()

	/**
	 * An open breaker is recorded as an error and makes no call.
	 *
	 * @return void
	 */
	public function testOpenBreakerIsNotCalled(): void {
		$this->addSource(uuid: 's-1', data: ['circuitBreakerState' => 'open', 'circuitBreakerFailureCount' => 5]);
		$this->sourceTest->expects($this->never())->method('run');

		$row = $this->makeService()->probe(['uuid' => 'c-1', 'data' => ['app' => 'dossiq', 'key' => 'pdok', 'source' => 's-1']]);

		$this->assertSame(
			expected: ['status' => 'error', 'message' => 'The circuit breaker is open after 5 failures.', 'at' => '2026-09-14T12:00:00+00:00'],
			actual: $row['data']['lastProbe']
		);
		$this->assertSame(expected: 'error', actual: $row['data']['status']);
		$this->assertSame(expected: '2026-09-14T12:00:00+00:00', actual: $row['data']['checkedAt']);
	}//end testOpenBreakerIsNotCalled()

	/**
	 * A failing source turns the row red with a message naming the code.
	 *
	 * @return void
	 */
	public function testFailingSourceNamesTheCode(): void {
		$this->addSource(uuid: 's-1', data: ['circuitBreakerState' => 'closed']);
		$this->sourceTest->expects($this->once())->method('run')
			->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_RESPONSE, code: 503, message: 'Service Unavailable'));

		$row = $this->makeService()->probe(['uuid' => 'c-1', 'data' => ['app' => 'dossiq', 'key' => 'pdok', 'source' => 's-1']]);

		$this->assertSame(expected: 'error', actual: $row['data']['lastProbe']['status']);
		$this->assertSame(expected: 'The source answered with HTTP 503 Service Unavailable.', actual: $row['data']['lastProbe']['message']);
	}//end testFailingSourceNamesTheCode()

	/**
	 * A passing source records ok, read as configured.
	 *
	 * @return void
	 */
	public function testPassingSourceIsConfigured(): void {
		$this->addSource(uuid: 's-1');
		$this->sourceTest->method('run')->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_RESPONSE, code: 200, message: 'OK'));

		$row = $this->makeService()->probe(['uuid' => 'c-1', 'data' => ['app' => 'dossiq', 'key' => 'pdok', 'source' => 's-1']]);

		$this->assertSame(expected: 'ok', actual: $row['data']['lastProbe']['status']);
		$this->assertSame(expected: 'configured', actual: $row['data']['status']);
		$this->assertSame(expected: 'The source answered with HTTP 200.', actual: $row['data']['statusMessage']);
	}//end testPassingSourceIsConfigured()

	/**
	 * A failed call and a missing source are both errors.
	 *
	 * @return void
	 */
	public function testFailedCallAndMissingSourceAreErrors(): void {
		$this->addSource(uuid: 's-1');
		$this->sourceTest->method('run')->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_FAILED, code: null));
		$service = $this->makeService();

		$failed = $service->probe(['uuid' => 'c-1', 'data' => ['app' => 'dossiq', 'source' => 's-1']]);
		$this->assertSame(expected: 'The call failed: boom', actual: $failed['data']['lastProbe']['message']);

		$missing = $service->probe(['uuid' => 'c-2', 'data' => ['app' => 'dossiq', 'source' => 'gone']]);
		$this->assertSame(expected: 'The linked source no longer exists.', actual: $missing['data']['lastProbe']['message']);
	}//end testFailedCallAndMissingSourceAreErrors()

	/**
	 * At most 25 probes per run, oldest probe first, never-probed rows before all.
	 *
	 * @return void
	 */
	public function testProbeCapAndOrder(): void {
		$rows = [];
		for ($index = 0; $index < 30; $index++) {
			$this->addSource(uuid: 's-' . $index);
			$rows[] = [
				'uuid' => 'c-' . $index,
				'data' => [
					'app' => 'dossiq',
					'key' => 'k' . $index,
					'source' => 's-' . $index,
					// Row 0 is the newest; rows count back one hour each.
					'lastProbe' => ['status' => 'ok', 'message' => '', 'at' => date(DATE_ATOM, 1_790_000_000 - ($index * 3600))],
				],
			];
		}

		$rows[5]['data']['lastProbe'] = null;
		$rows[] = ['uuid' => 'unlinked', 'data' => ['app' => 'dossiq', 'key' => 'none']];
		$this->store->method('findRows')->willReturn($rows);
		$this->sourceTest->expects($this->exactly(count: 25))->method('run')
			->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_RESPONSE, code: 200));

		$probed = $this->makeService()->probeDue();

		$this->assertSame(expected: 25, actual: $probed);
		$order = array_map(static fn (array $save): ?string => $save[0], $this->saves);
		$this->assertSame(expected: 'c-5', actual: $order[0]);
		$this->assertSame(expected: 'c-29', actual: $order[1]);
		$this->assertNotContains(needle: 'c-0', haystack: $order);
		$this->assertNotContains(needle: 'unlinked', haystack: $order);
	}//end testProbeCapAndOrder()

	/**
	 * Linking an existing source stores its uuid and probes at once.
	 *
	 * @return void
	 */
	public function testLinkSourceProbesStraightAway(): void {
		$this->addSource(uuid: 's-kvk');
		$this->store->method('findRow')->willReturn(['uuid' => 'c-kvk', 'data' => ['app' => 'dossiq', 'key' => 'kvk']]);
		$this->sourceTest->expects($this->once())->method('run')->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_RESPONSE, code: 200));

		$row = $this->makeService()->linkSource('c-kvk', 's-kvk');

		$this->assertSame(expected: 's-kvk', actual: $row['data']['source']);
		$this->assertSame(expected: 'ok', actual: $row['data']['lastProbe']['status']);
		$this->assertSame(expected: 'c-kvk', actual: $this->saves[0][0]);
	}//end testLinkSourceProbesStraightAway()

	/**
	 * A connection that already has a source is refused.
	 *
	 * @return void
	 */
	public function testAlreadyLinkedIsRefused(): void {
		$this->store->method('findRow')->willReturn(['uuid' => 'c-kvk', 'data' => ['app' => 'dossiq', 'source' => 's-old']]);
		$this->sourceTest->expects($this->never())->method('run');

		try {
			$this->makeService()->linkSource('c-kvk', 's-new');
			$this->fail(message: 'A linked connection must be refused.');
		} catch (ConnectionLinkException $e) {
			$this->assertSame(expected: ConnectionLinkException::ALREADY_LINKED, actual: $e->getReason());
		}

		$this->assertSame(expected: [], actual: $this->saves);
	}//end testAlreadyLinkedIsRefused()

	/**
	 * Unknown connection and unknown source are refused.
	 *
	 * @return void
	 */
	public function testUnknownConnectionOrSourceIsRefused(): void {
		$this->store->method('findRow')->willReturnCallback(
			static function (string $uuid): ?array {
				if ($uuid !== 'c-1') {
					return null;
				}

				return ['uuid' => 'c-1', 'data' => ['app' => 'dossiq']];
			}
		);
		$service = $this->makeService();

		$cases = [
			['missing', 's-1', ConnectionLinkException::CONNECTION_NOT_FOUND],
			['c-1', 'nope', ConnectionLinkException::SOURCE_NOT_FOUND],
		];
		foreach ($cases as [$connection, $source, $reason]) {
			try {
				$service->linkSource($connection, $source);
				$this->fail(message: 'Expected a refusal for ' . $reason);
			} catch (ConnectionLinkException $e) {
				$this->assertSame(expected: $reason, actual: $e->getReason());
			}
		}
	}//end testUnknownConnectionOrSourceIsRefused()

	/**
	 * Linking from a template reuses a source with the template slug.
	 *
	 * @return void
	 */
	public function testLinkTemplateReusesExistingSource(): void {
		$existing = $this->addSource(uuid: 's-brp', data: ['slug' => 'brp-haalcentraal']);
		$this->store->method('findRow')->willReturn(
			['uuid' => 'c-brp', 'data' => ['app' => 'dossiq', 'key' => 'brp', 'declaration' => ['sourceTemplate' => 'brp-haalcentraal']]]
		);
		$this->store->method('findSourceBySlug')->willReturn($existing);
		$this->store->expects($this->never())->method('createSource');
		$this->sourceTest->method('run')->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_RESPONSE, code: 200));

		$row = $this->makeService()->linkTemplate('c-brp');

		$this->assertSame(expected: 's-brp', actual: $row['data']['source']);
	}//end testLinkTemplateReusesExistingSource()

	/**
	 * Linking from a template creates an enabled source from the seed when none exists.
	 *
	 * @return void
	 */
	public function testLinkTemplateCreatesFromSeed(): void {
		$this->store->method('findRow')->willReturn(
			['uuid' => 'c-brp', 'data' => ['app' => 'dossiq', 'declaration' => ['sourceTemplate' => 'brp-haalcentraal']]]
		);
		$this->store->method('findSourceBySlug')->willReturn(null);
		$this->catalog->method('findSeedSourcePayload')->with('brp-haalcentraal')->willReturn(['name' => 'BRP', 'slug' => 'brp-haalcentraal']);
		$created = new ObjectEntity();
		$created->setUuid('s-new');
		$this->store->expects($this->once())->method('createSource')
			->with(['name' => 'BRP', 'slug' => 'brp-haalcentraal', 'isEnabled' => true])
			->willReturnCallback(fn (): ObjectEntity => $this->sources['s-new'] = $created);
		$this->sourceTest->method('run')->willReturn($this->testOutcome(outcome: SourceTestService::OUTCOME_RESPONSE, code: 200));

		$row = $this->makeService()->linkTemplate('c-brp');

		$this->assertSame(expected: 's-new', actual: $row['data']['source']);
	}//end testLinkTemplateCreatesFromSeed()

	/**
	 * A connection without a template, or with an unknown one, is refused.
	 *
	 * @return void
	 */
	public function testLinkTemplateRefusals(): void {
		$this->store->method('findRow')->willReturnCallback(
			static function (string $uuid): array {
				$declaration = [];
				if ($uuid === 'with') {
					$declaration = ['sourceTemplate' => 'ghost'];
				}

				return ['uuid' => $uuid, 'data' => ['app' => 'dossiq', 'declaration' => $declaration]];
			}
		);
		$this->store->method('findSourceBySlug')->willReturn(null);
		$this->catalog->method('findSeedSourcePayload')->willReturn(null);
		$service = $this->makeService();

		foreach (['without' => ConnectionLinkException::NO_TEMPLATE, 'with' => ConnectionLinkException::TEMPLATE_NOT_FOUND] as $uuid => $reason) {
			try {
				$service->linkTemplate($uuid);
				$this->fail(message: 'Expected ' . $reason);
			} catch (ConnectionLinkException $e) {
				$this->assertSame(expected: $reason, actual: $e->getReason());
			}
		}
	}//end testLinkTemplateRefusals()
}//end class
