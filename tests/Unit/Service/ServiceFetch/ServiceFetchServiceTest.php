<?php

/**
 * Unit tests for ServiceFetchService — the service fetch command.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ServiceFetch;

use OCA\Integriq\Event\ServiceFetchRequestedEvent;
use OCA\Integriq\EventListener\ServiceFetchRequestedListener;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchCatalogue;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchInputs;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchRedactor;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchRefusal;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Design D3 and D4, with a recorded source answer.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
#[CoversClass(ServiceFetchService::class)]
#[CoversClass(ServiceFetchCatalogue::class)]
#[CoversClass(ServiceFetchRequestedEvent::class)]
#[CoversClass(ServiceFetchRequestedListener::class)]
#[UsesClass(ServiceFetchInputs::class)]
#[UsesClass(ServiceFetchRedactor::class)]
#[UsesClass(ServiceFetchRefusal::class)]
#[UsesClass(SensitiveFieldRegistry::class)]
class ServiceFetchServiceTest extends TestCase {

	/**
	 * The Kadaster ownership check of the spec.
	 */
	private const FETCH = [
		'slug' => 'kadaster-eigenaar',
		'title' => 'Eigenaar volgens het Kadaster',
		'source' => 'source-brk',
		'method' => 'GET',
		'path' => '/eigendom/{bsn}',
		'query' => ['object' => '{nummeraanduiding}'],
		'inputs' => [
			['name' => 'bsn', 'type' => 'string', 'required' => true],
			['name' => 'nummeraanduiding', 'type' => 'string', 'required' => true],
		],
		'outputMapping' => 'mapping-eigenaar',
		'outputs' => [['name' => 'isEigenaar', 'type' => 'boolean', 'required' => true], ['name' => 'sinds', 'type' => 'date']],
		'allowedApps' => ['portaliq'],
		'timeoutMs' => 2500,
		'cacheSeconds' => 0,
		'enabled' => true,
	];

	/**
	 * The inputs portaliq sends.
	 */
	private const INPUTS = ['bsn' => '999993653', 'nummeraanduiding' => '0363200000123456'];

	/**
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService $objects;

	/**
	 * @var CallServiceInterface&MockObject
	 */
	private CallServiceInterface $calls;

	/**
	 * @var MappingServiceInterface&MockObject
	 */
	private MappingServiceInterface $mappings;

	/**
	 * What the cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $cached = [];

	/**
	 * Call log rows saved back.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $savedLogs = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(OrObjectService::class);
		$this->calls = $this->createMock(CallServiceInterface::class);
		$this->mappings = $this->createMock(MappingServiceInterface::class);
		$this->cached = [];
		$this->savedLogs = [];

		$this->objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->savedLogs[] = ['schema' => $schema, 'uuid' => $uuid, 'row' => $object];
				return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$uuid);
			}
		);
	}//end setUp()

	/**
	 * The service, holding these fetches, answering `find` for the source and the stored call log.
	 *
	 * @param list<array<string, mixed>> $fetches The fetches the register holds.
	 *
	 * @return ServiceFetchService
	 */
	private function service(array $fetches = [self::FETCH]): ServiceFetchService {
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use ($fetches): array {
				$slug = ($config['filters']['slug'] ?? null);
				$results = [];
				foreach ($fetches as $fetch) {
					if ($slug === null || $fetch['slug'] === $slug) {
						$results[] = ObjectServiceMockBuilder::objectEntity($this, $fetch, 'fetch-' . $fetch['slug']);
					}
				}

				return ['results' => $results];
			}
		);
		$this->objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null): ObjectEntity {
				if ($schema === 'source' && $id === 'source-brk') {
					return ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://brk.example'], 'source-brk');
				}

				if ($schema === 'call_log') {
					return ObjectServiceMockBuilder::objectEntity(
						$this,
						[
							'source' => 'source-brk',
							'request' => ['url' => 'https://brk.example/eigendom/999993653?object=0363200000123456', 'query' => ['object' => '0363200000123456']],
							'response' => ['statusCode' => 200, 'body' => '{"bsn":"999993653"}'],
						],
						(string)$id
					);
				}

				throw new RuntimeException('not found');
			}
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->cached[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cached[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new ServiceFetchService(
			new ServiceFetchCatalogue($this->objects),
			$this->objects,
			$this->calls,
			$this->mappings,
			$factory,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A call log the engine returns for a status and body.
	 *
	 * @param int    $status The status code.
	 * @param string $body   The body.
	 *
	 * @return ObjectEntity
	 */
	private function answer(int $status, string $body): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['response' => ['statusCode' => $status, 'body' => $body, 'encoding' => 'UTF-8']],
			'log-1'
		);
	}//end answer()

	/**
	 * Run the command through the listener, as the dispatcher would.
	 *
	 * @param ServiceFetchService  $service The service.
	 * @param string               $app     The calling app.
	 * @param array<string, mixed> $inputs  The inputs.
	 * @param string               $fetch   The fetch slug.
	 *
	 * @return ServiceFetchRequestedEvent
	 */
	private function dispatch(ServiceFetchService $service, string $app = 'portaliq', array $inputs = self::INPUTS, string $fetch = 'kadaster-eigenaar'): ServiceFetchRequestedEvent {
		$event = new ServiceFetchRequestedEvent(app: $app, fetch: $fetch, inputs: $inputs);
		(new ServiceFetchRequestedListener($service))->handle($event);

		return $event;
	}//end dispatch()

	/**
	 * The ownership check: the source is called with the rendered path, query
	 * and timeout, the mapping gets `{status, body}`, and only declared outputs
	 * come back, never the body.
	 *
	 * @return void
	 */
	public function testPortaliqChecksOwnershipAndGetsOnlyTheDeclaredOutputs(): void {
		$this->calls->expects($this->once())->method('call')
			->with(
				$this->callback(static fn (ObjectEntity $source): bool => $source->getUuid() === 'source-brk'),
				'/eigendom/999993653',
				'GET',
				['timeout' => 2.5, 'query' => ['object' => '0363200000123456']]
			)
			->willReturn($this->answer(status: 200, body: '{"eigenaar":"999993653","sinds":"2019-04-01"}'));
		$this->mappings->expects($this->once())->method('executeMapping')
			->with('mapping-eigenaar', ['status' => 200, 'body' => ['eigenaar' => '999993653', 'sinds' => '2019-04-01']])
			->willReturn(['isEigenaar' => true, 'sinds' => '2019-04-01', 'raw' => ['eigenaar' => '999993653']]);

		$result = $this->dispatch(service: $this->service())->getResult();

		$this->assertTrue($result['ok']);
		$this->assertSame(['isEigenaar' => true, 'sinds' => '2019-04-01'], $result['outputs']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string)$result['fetchedAt']);
	}//end testPortaliqChecksOwnershipAndGetsOnlyTheDeclaredOutputs()

	/**
	 * The call log line names the app and the fetch and holds no BSN.
	 *
	 * @return void
	 */
	public function testTheCallLogNamesAppAndFetchWithTheBsnRedacted(): void {
		$this->calls->method('call')->willReturn($this->answer(status: 200, body: '{}'));
		$this->mappings->method('executeMapping')->willReturn(['isEigenaar' => false]);

		$this->dispatch(service: $this->service());

		$this->assertCount(1, $this->savedLogs);
		$saved = $this->savedLogs[0];
		$this->assertSame(['call_log', 'log-1'], [$saved['schema'], $saved['uuid']]);
		$this->assertSame(['app' => 'portaliq', 'fetch' => 'kadaster-eigenaar'], $saved['row']['context']);
		$encoded = json_encode($saved['row']);
		$this->assertStringNotContainsString('999993653', (string)$encoded);
		$this->assertStringNotContainsString('0363200000123456', (string)$encoded);
	}//end testTheCallLogNamesAppAndFetchWithTheBsnRedacted()

	/**
	 * Refusals before any outside call: unknown, disabled, not allowed, a
	 * missing required input named.
	 *
	 * @return void
	 */
	public function testRefusalsBeforeTheCallMakeNoOutsideCall(): void {
		$this->calls->expects($this->never())->method('call');
		$service = $this->service(fetches: [self::FETCH, ['slug' => 'uit', 'enabled' => false, 'allowedApps' => ['portaliq']] + self::FETCH]);

		$results = [
			$this->dispatch(service: $service, fetch: 'bestaat-niet')->getResult(),
			$this->dispatch(service: $service, fetch: 'uit')->getResult(),
			$this->dispatch(service: $service, app: 'buildiq')->getResult(),
			$this->dispatch(service: $service, app: '')->getResult(),
			$this->dispatch(service: $service, inputs: ['nummeraanduiding' => '0363200000123456'])->getResult(),
		];

		$this->assertSame(
			[
				['ok' => false, 'refusal' => 'unknown-fetch', 'detail' => null],
				['ok' => false, 'refusal' => 'unknown-fetch', 'detail' => null],
				['ok' => false, 'refusal' => 'not-allowed', 'detail' => null],
				['ok' => false, 'refusal' => 'not-allowed', 'detail' => null],
				['ok' => false, 'refusal' => 'invalid-input', 'detail' => 'bsn'],
			],
			$results
		);
	}//end testRefusalsBeforeTheCallMakeNoOutsideCall()

	/**
	 * A 503, a 429, an open breaker answering without a status, or a thrown
	 * call is `source-unavailable`; a timeout is `timeout`. The mapping never runs.
	 *
	 * @return void
	 */
	public function testAnUnavailableOrSlowSourceIsRefused(): void {
		$this->mappings->expects($this->never())->method('executeMapping');
		$this->calls->method('call')->willReturnOnConsecutiveCalls(
			$this->answer(status: 503, body: ''),
			$this->answer(status: 429, body: ''),
			$this->answer(status: 0, body: ''),
			$this->throwException(new RuntimeException('Connection refused')),
			$this->throwException(new RuntimeException('cURL error 28: Operation timed out after 2500 milliseconds'))
		);
		$service = $this->service();

		$refusals = [];
		for ($i = 0; $i < 5; $i++) {
			$refusals[] = $this->dispatch(service: $service)->getRefusal();
		}

		$this->assertSame(['source-unavailable', 'source-unavailable', 'source-unavailable', 'source-unavailable', 'timeout'], $refusals);
	}//end testAnUnavailableOrSlowSourceIsRefused()

	/**
	 * A 404 is no failure: the mapping decides what "not found" means.
	 *
	 * @return void
	 */
	public function testANotFoundAnswerGoesToTheMapping(): void {
		$this->calls->method('call')->willReturn($this->answer(status: 404, body: 'not found'));
		$this->mappings->expects($this->once())->method('executeMapping')
			->with('mapping-eigenaar', ['status' => 404, 'body' => 'not found'])
			->willReturn(['isEigenaar' => false]);

		$result = $this->dispatch(service: $this->service())->getResult();

		$this->assertSame(['isEigenaar' => false], $result['outputs']);
	}//end testANotFoundAnswerGoesToTheMapping()

	/**
	 * A mapping that throws, or one that leaves a required output out, is
	 * `mapping-failed`; the latter names the output.
	 *
	 * @return void
	 */
	public function testAFailingMappingIsRefused(): void {
		$this->calls->method('call')->willReturn($this->answer(status: 200, body: '{}'));
		$this->mappings->method('executeMapping')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('twig error')),
			['sinds' => '2019-04-01']
		);
		$service = $this->service();

		$this->assertSame(['ok' => false, 'refusal' => 'mapping-failed', 'detail' => null], $this->dispatch(service: $service)->getResult());
		$this->assertSame(['ok' => false, 'refusal' => 'mapping-failed', 'detail' => 'isEigenaar'], $this->dispatch(service: $service)->getResult());
	}//end testAFailingMappingIsRefused()

	/**
	 * With `cacheSeconds` 60 the same inputs call the source once; other
	 * inputs call it again.
	 *
	 * @return void
	 */
	public function testACachingFetchCallsTheSourceOncePerInputs(): void {
		$this->calls->expects($this->exactly(2))->method('call')->willReturn($this->answer(status: 200, body: '{}'));
		$this->mappings->method('executeMapping')->willReturn(['isEigenaar' => true]);
		$service = $this->service(fetches: [['cacheSeconds' => 60] + self::FETCH]);

		$first = $this->dispatch(service: $service)->getResult();
		$second = $this->dispatch(service: $service)->getResult();
		$other = $this->dispatch(service: $service, inputs: ['bsn' => '111222333'] + self::INPUTS)->getResult();

		$this->assertSame($first, $second);
		$this->assertTrue($other['ok']);
	}//end testACachingFetchCallsTheSourceOncePerInputs()

	/**
	 * A POST fetch sends the body mapping's result; a fetch without a readable
	 * source is `source-unavailable` without a call.
	 *
	 * @return void
	 */
	public function testAPostSendsTheMappedBodyAndAMissingSourceIsRefused(): void {
		$post = ['method' => 'POST', 'bodyMapping' => 'mapping-body', 'path' => '/zoek', 'query' => []] + self::FETCH;
		$gone = ['slug' => 'weg', 'source' => 'source-gone'] + self::FETCH;
		$this->mappings->method('executeMapping')->willReturnCallback(
			static fn (string $mapping, array $input): array => ($mapping === 'mapping-body' ? ['persoon' => $input['bsn']] : ['isEigenaar' => true])
		);
		$this->calls->expects($this->once())->method('call')
			->with($this->anything(), '/zoek', 'POST', ['timeout' => 2.5, 'json' => ['persoon' => '999993653']])
			->willReturn($this->answer(status: 200, body: '{}'));
		$service = $this->service(fetches: [$post, $gone]);

		$this->assertTrue($this->dispatch(service: $service)->getResult()['ok']);
		$this->assertSame('source-unavailable', $this->dispatch(service: $service, fetch: 'weg')->getRefusal());
	}//end testAPostSendsTheMappedBodyAndAMissingSourceIsRefused()

	/**
	 * The designer list holds the enabled fetches the app may call, by slug,
	 * without source, path, query or mappings.
	 *
	 * @return void
	 */
	public function testTheDesignerListProjectsTheAllowedFetches(): void {
		$this->service(fetches: [self::FETCH, ['slug' => 'alleen-buildiq', 'allowedApps' => ['buildiq']] + self::FETCH, ['slug' => 'a-uit', 'enabled' => false] + self::FETCH]);

		$listed = (new ServiceFetchCatalogue($this->objects))->listFor(app: 'portaliq');

		$this->assertSame(
			[[
				'slug' => 'kadaster-eigenaar',
				'title' => 'Eigenaar volgens het Kadaster',
				'description' => '',
				'inputs' => self::FETCH['inputs'],
				'outputs' => self::FETCH['outputs'],
			]],
			$listed
		);
	}//end testTheDesignerListProjectsTheAllowedFetches()

	/**
	 * An unhandled event has no result; another event type is ignored.
	 *
	 * @return void
	 */
	public function testAnUnansweredEventHasNoResult(): void {
		$event = new ServiceFetchRequestedEvent(app: 'portaliq', fetch: 'x');
		(new ServiceFetchRequestedListener($this->service()))->handle(new \OCP\EventDispatcher\Event());

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getResult());
		$this->assertSame([], $event->getInputs());
	}//end testAnUnansweredEventHasNoResult()
	/**
	 * "Testen": a disabled fetch, called by no allowed app, still runs for its
	 * administrator, without the cache, and shows the raw answer next to the
	 * declared outputs (REQ-SF-001).
	 *
	 * @return void
	 */
	public function testAnAdministratorsTestRunShowsTheRawAnswerNextToTheOutputs(): void {
		$fetch = (['enabled' => false, 'allowedApps' => [], 'cacheSeconds' => 600] + self::FETCH);
		$this->calls->expects($this->exactly(2))->method('call')
			->with($this->anything(), '/eigendom/999993653', 'GET', ['timeout' => 2.5, 'query' => ['object' => '0363200000123456']])
			->willReturn($this->answer(status: 200, body: '{"eigenaar":"999993653","sinds":"2019-04-01"}'));
		$this->mappings->method('executeMapping')
			->with('mapping-eigenaar', ['status' => 200, 'body' => ['eigenaar' => '999993653', 'sinds' => '2019-04-01']])
			->willReturn(['isEigenaar' => true, 'raw' => 'kept from apps']);
		$service = $this->service(fetches: [$fetch]);

		$result = $service->test(slug: 'kadaster-eigenaar', inputs: self::INPUTS);
		$service->test(slug: 'kadaster-eigenaar', inputs: self::INPUTS);

		$this->assertSame(
			[
				'found' => true,
				'raw' => ['status' => 200, 'body' => ['eigenaar' => '999993653', 'sinds' => '2019-04-01']],
				'outputs' => ['isEigenaar' => true],
				'refusal' => null,
				'detail' => null,
			],
			$result
		);
		$this->assertSame([], $this->cached);
	}//end testAnAdministratorsTestRunShowsTheRawAnswerNextToTheOutputs()

	/**
	 * An unknown fetch is not found; a missing input or a failing mapping is
	 * named, the latter with the raw answer the source gave.
	 *
	 * @return void
	 */
	public function testATestRunNamesItsRefusal(): void {
		$this->assertFalse($this->service()->test(slug: 'nope', inputs: self::INPUTS)['found']);

		$this->calls->expects($this->once())->method('call')->willReturn($this->answer(status: 200, body: '{"eigenaar":null}'));
		$this->mappings->method('executeMapping')->willReturn(['sinds' => '2019-04-01']);

		$missing = $this->service()->test(slug: 'kadaster-eigenaar', inputs: ['bsn' => '999993653']);
		$this->assertSame(ServiceFetchRequestedEvent::INVALID_INPUT, $missing['refusal']);
		$this->assertNull($missing['raw']);

		$unmapped = $this->service()->test(slug: 'kadaster-eigenaar', inputs: self::INPUTS);
		$this->assertSame(ServiceFetchRequestedEvent::MAPPING_FAILED, $unmapped['refusal']);
		$this->assertSame('isEigenaar', $unmapped['detail']);
		$this->assertSame(['status' => 200, 'body' => ['eigenaar' => null]], $unmapped['raw']);
		$this->assertNull($unmapped['outputs']);
	}//end testATestRunNamesItsRefusal()
}//end class
