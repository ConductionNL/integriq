<?php

/**
 * Unit tests for the span command other apps send (REQ-OTEL-007).
 *
 * The real event class is dispatched through a dispatcher that resolves
 * service listeners as Nextcloud's does, wired by `Application::register()`
 * itself, so the tests prove a sender reaches the listener. The event is
 * built from its class name as a string, the way a sibling app builds it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-another-app-hands-integriq-its-spans-through-a-typed-command-req-otel-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Listener;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\BackgroundJob\OtelExportJob;
use OCA\Integriq\BackgroundJob\OtelExportJobScheduler;
use OCA\Integriq\BackgroundJob\TraceExportSchedulerInterface;
use OCA\Integriq\Event\SpanExportRequestedEvent;
use OCA\Integriq\Listener\SpanExportRequestedListener;
use OCA\Integriq\Observability\Otel\OtelExportBreaker;
use OCA\Integriq\Observability\Otel\OtelSettings;
use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\TraceExporterInterface;
use OCA\Integriq\Observability\Otel\TraceParent;
use OCA\Integriq\Service\ExecutionTraceServiceInterface;
use OCA\Integriq\Tests\Helpers\AppContainerInjection;
use OCA\Integriq\Tests\Helpers\ServiceListenerDispatcher;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * The span command, end to end from the dispatch to the exported payload.
 */
class SpanExportRequestedListenerTest extends TestCase {

	use AppContainerInjection;

	/**
	 * The event class a sibling app names as a string.
	 *
	 * @var string
	 */
	private const EVENT = 'OCA\\Integriq\\Event\\SpanExportRequestedEvent';

	/**
	 * The trace id of the opencatalogi request in the spec scenario.
	 *
	 * @var string
	 */
	private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

	/**
	 * Stored app settings, as an in-memory IAppConfig.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * The jobs added to the job list: class and argument.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Export on, sampling at 100%, a collector at otel.example.org.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = [
			'otel_enabled' => true,
			'otel_endpoint' => 'https://otel.example.org:4318',
			'otel_sampling_ratio' => 1.0,
		];
		$this->queued = [];

	}//end setUp()

	/**
	 * An in-memory IAppConfig over $this->config.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		foreach (['getValueBool', 'getValueString', 'getValueFloat', 'getValueInt'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		return $appConfig;

	}//end appConfig()

	/**
	 * The export settings over $this->config.
	 *
	 * @return OtelSettings
	 */
	private function settings(): OtelSettings {
		return new OtelSettings(appConfig: $this->appConfig(), l: $this->createMock(originalClassName: IL10N::class));

	}//end settings()

	/**
	 * The real listener over the real settings, mapper, scheduler and breaker.
	 *
	 * @param TraceExportSchedulerInterface|null $scheduler The scheduler; one over a recording job list when absent.
	 *
	 * @return SpanExportRequestedListener
	 */
	private function listener(?TraceExportSchedulerInterface $scheduler = null): SpanExportRequestedListener {
		if ($scheduler === null) {
			$jobList = $this->createMock(originalClassName: IJobList::class);
			$jobList->method('add')->willReturnCallback(
				function (string $job, $argument): void {
					$this->queued[] = [$job, $argument];
				}
			);
			$scheduler = new OtelExportJobScheduler(jobList: $jobList);
		}

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);

		return new SpanExportRequestedListener(
			settings: $this->settings(),
			mapper: new SpanMapper(traceParent: new TraceParent()),
			scheduler: $scheduler,
			breaker: new OtelExportBreaker(appConfig: $this->appConfig(), cacheFactory: $this->createMock(originalClassName: ICacheFactory::class)),
			time: $time,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end listener()

	/**
	 * A dispatcher wired by `Application::register()`, resolving the listener
	 * class to the given instance.
	 *
	 * @param SpanExportRequestedListener $listener The listener instance.
	 *
	 * @return IEventDispatcher
	 */
	private function dispatcher(SpanExportRequestedListener $listener): IEventDispatcher {
		$dispatcher = new ServiceListenerDispatcher(services: [SpanExportRequestedListener::class => $listener]);

		$container = $this->createMock(originalClassName: $this->appContainerType());
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dispatcher) {
				if ($id === IEventDispatcher::class) {
					return $dispatcher;
				}

				throw new RuntimeException('unexpected container id: ' . $id);
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$this->injectAppContainer(app: $app, container: $container);
		$app->register($this->createMock(originalClassName: IRegistrationContext::class));

		return $dispatcher;

	}//end dispatcher()

	/**
	 * A valid span of the spec scenario.
	 *
	 * @param array<string, mixed> $overrides Keys to change.
	 *
	 * @return array<string, mixed> The span.
	 */
	private function span(array $overrides = []): array {
		return array_merge(
			[
				'traceId' => self::TRACE_ID,
				'spanId' => '00f067aa0ba902b7',
				'name' => 'GET /api/publications',
				'startTimeUnixNano' => 1760000000000000000,
				'endTimeUnixNano' => 1760000000250000000,
				'status' => 'ok',
				'attributes' => ['http.request.method' => 'get', 'http.response.status_code' => 200],
			],
			$overrides
		);

	}//end span()

	/**
	 * Dispatch the event for the given spans and return it.
	 *
	 * @param list<mixed> $spans The spans.
	 * @param string $appId The sending app.
	 * @param SpanExportRequestedListener|null $listener The listener; the real one when absent.
	 *
	 * @return SpanExportRequestedEvent
	 */
	private function dispatch(array $spans, string $appId = 'opencatalogi', ?SpanExportRequestedListener $listener = null): SpanExportRequestedEvent {
		$class = self::EVENT;
		$event = new $class($appId, $spans);
		$this->dispatcher(listener: ($listener ?? $this->listener()))->dispatchTyped($event);

		return $event;

	}//end dispatch()

	/**
	 * A valid span is queued, answered `queued: 1`, and the job exports it
	 * with the trace id and `service.name` of the dispatching app.
	 *
	 * @return void
	 */
	public function testAValidSpanIsQueuedAndAnsweredQueued(): void {
		$event = $this->dispatch(spans: [$this->span()]);

		$this->assertSame(expected: ['queued' => 1, 'rejected' => 0, 'reasons' => []], actual: $event->getResult());
		$this->assertCount(expectedCount: 1, haystack: $this->queued);
		$this->assertSame(expected: OtelExportJob::class, actual: $this->queued[0][0]);
		$this->assertSame(expected: 'opencatalogi', actual: $this->queued[0][1]['appId']);

		$exported = [];
		$exporter = $this->createMock(originalClassName: TraceExporterInterface::class);
		$exporter->expects($this->once())->method('export')->willReturnCallback(
			function (array $payload) use (&$exported): void {
				$exported = $payload;
			}
		);
		$traces = $this->createMock(originalClassName: ExecutionTraceServiceInterface::class);
		$traces->expects($this->never())->method('findForExport');
		$job = new OtelExportJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			traces: $traces,
			mapper: new SpanMapper(traceParent: new TraceParent()),
			exporter: $exporter,
			settings: $this->settings(),
			jobList: $this->createMock(originalClassName: IJobList::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			breaker: new OtelExportBreaker(appConfig: $this->appConfig(), cacheFactory: $this->createMock(originalClassName: ICacheFactory::class))
		);
		$job->run($this->queued[0][1]);

		$resource = $exported['resourceSpans'][0];
		$this->assertSame(expected: [['key' => 'service.name', 'value' => ['stringValue' => 'opencatalogi']]], actual: $resource['resource']['attributes']);
		$span = $resource['scopeSpans'][0]['spans'][0];
		$this->assertSame(expected: self::TRACE_ID, actual: $span['traceId']);
		$this->assertSame(expected: '00f067aa0ba902b7', actual: $span['spanId']);
		$this->assertSame(expected: 'GET /api/publications', actual: $span['name']);
		$this->assertSame(expected: 2, actual: $span['kind'], message: 'A root HTTP span is a server span.');
		$this->assertSame(expected: '1760000000000000000', actual: $span['startTimeUnixNano']);
		$this->assertSame(expected: ['code' => 1], actual: $span['status']);
		$this->assertSame(
			expected: [
				['key' => 'http.request.method', 'value' => ['stringValue' => 'GET']],
				['key' => 'http.response.status_code', 'value' => ['intValue' => '200']],
			],
			actual: $span['attributes']
		);

	}//end testAValidSpanIsQueuedAndAnsweredQueued()

	/**
	 * A 30-character trace id is rejected with a reason naming `traceId`.
	 *
	 * @return void
	 */
	public function testAMalformedSpanIsRejectedWithItsField(): void {
		$event = $this->dispatch(spans: [$this->span(overrides: ['traceId' => substr(self::TRACE_ID, 0, 30)])]);

		$result = $event->getResult();
		$this->assertSame(expected: 0, actual: $result['queued']);
		$this->assertSame(expected: 1, actual: $result['rejected']);
		$this->assertCount(expectedCount: 1, haystack: $result['reasons']);
		$this->assertStringStartsWith(prefix: 'spans[0].traceId:', string: $result['reasons'][0]);
		$this->assertSame(expected: [], actual: $this->queued);

	}//end testAMalformedSpanIsRejectedWithItsField()

	/**
	 * Every malformed field is named, and the good span next to them is queued.
	 *
	 * @return void
	 */
	public function testEachMalformedFieldIsNamed(): void {
		$event = $this->dispatch(
			spans: [
				$this->span(overrides: ['spanId' => '0000000000000000']),
				$this->span(overrides: ['parentSpanId' => 'XYZ']),
				$this->span(overrides: ['name' => '  ']),
				$this->span(overrides: ['startTimeUnixNano' => '1760000000000000000']),
				$this->span(overrides: ['endTimeUnixNano' => 1]),
				$this->span(overrides: ['status' => 'unset']),
				$this->span(overrides: ['attributes' => 'none']),
				'not a span',
				$this->span(overrides: ['parentSpanId' => 'b7ad6b7169203331']),
			]
		);

		$result = $event->getResult();
		$this->assertSame(expected: 1, actual: $result['queued']);
		$this->assertSame(expected: 8, actual: $result['rejected']);
		$fields = array_map(static fn (string $r): string => explode(':', $r)[0], $result['reasons']);
		$this->assertSame(
			expected: [
				'spans[0].spanId',
				'spans[1].parentSpanId',
				'spans[2].name',
				'spans[3].startTimeUnixNano',
				'spans[4].endTimeUnixNano',
				'spans[5].status',
				'spans[6].attributes',
				'spans[7].span',
			],
			actual: $fields
		);
		$this->assertSame(expected: 'b7ad6b7169203331', actual: $this->queued[0][1]['span']['parentSpanId']);

	}//end testEachMalformedFieldIsNamed()

	/**
	 * An attribute outside the allowlist is dropped and the span is queued;
	 * identifiers in the name and the URL are masked.
	 *
	 * @return void
	 */
	public function testADisallowedAttributeIsDroppedAndTheSpanQueued(): void {
		$event = $this->dispatch(
			spans: [
				$this->span(
					overrides: [
						'name' => 'GET /api/objects/999993653?bsn=999993653',
						'attributes' => [
							'http.request.body' => '{"bsn":"999993653"}',
							'url.full' => 'https://svc:s3cret@or.example.org/api/objects/999993653?x=1',
							'http.response.status_code' => '500',
							'http.request.method' => ['GET'],
						],
						'status' => 'error',
					]
				),
			]
		);

		$this->assertSame(expected: ['queued' => 1, 'rejected' => 0, 'reasons' => []], actual: $event->getResult());
		$span = $this->queued[0][1]['span'];
		$this->assertSame(
			expected: ['url.full' => 'https://or.example.org/api/objects/{id}', 'http.response.status_code' => 500],
			actual: $span['attributes']
		);
		$this->assertSame(expected: 'GET /api/objects/{id}', actual: $span['name']);
		$this->assertStringNotContainsString(needle: '999993653', haystack: (string)json_encode($this->queued));
		$this->assertStringNotContainsString(needle: 's3cret', haystack: (string)json_encode($this->queued));

	}//end testADisallowedAttributeIsDroppedAndTheSpanQueued()

	/**
	 * With export switched off nothing is queued and the reason is `export-disabled`.
	 *
	 * @return void
	 */
	public function testExportDisabledAnswersExportDisabled(): void {
		$this->config['otel_enabled'] = false;

		$event = $this->dispatch(spans: [$this->span()]);

		$this->assertSame(expected: ['queued' => 0, 'rejected' => 0, 'reasons' => ['export-disabled']], actual: $event->getResult());
		$this->assertSame(expected: [], actual: $this->queued);

	}//end testExportDisabledAnswersExportDisabled()

	/**
	 * A bad app id, too many spans and paused sends refuse the whole dispatch.
	 *
	 * @return void
	 */
	public function testAWholeDispatchCanBeRefused(): void {
		$event = $this->dispatch(spans: [$this->span(), $this->span()], appId: 'Open Catalogi');
		$this->assertSame(expected: ['queued' => 0, 'rejected' => 2, 'reasons' => ['appId: must be a Nextcloud app id']], actual: $event->getResult());

		$many = array_fill(0, (SpanExportRequestedListener::MAX_SPANS + 1), $this->span());
		$event = $this->dispatch(spans: $many);
		$this->assertSame(expected: 101, actual: $event->getResult()['rejected']);

		$this->config['otel_breaker_open_until'] = 1760000300;
		$event = $this->dispatch(spans: [$this->span()]);
		$this->assertSame(expected: ['queued' => 0, 'rejected' => 0, 'reasons' => ['export-paused']], actual: $event->getResult());

		$this->assertSame(expected: [], actual: $this->queued);

	}//end testAWholeDispatchCanBeRefused()

	/**
	 * At a sampling ratio of zero a successful span is sampled out, a failed
	 * one is still queued.
	 *
	 * @return void
	 */
	public function testASuccessfulSpanCanBeSampledOut(): void {
		$this->config['otel_sampling_ratio'] = 0.0;

		$event = $this->dispatch(spans: [$this->span(), $this->span(overrides: ['status' => 'error', 'spanId' => '1111111111111111'])]);

		$this->assertSame(expected: ['queued' => 1, 'rejected' => 0, 'reasons' => ['sampled-out']], actual: $event->getResult());
		$this->assertSame(expected: 'error', actual: $this->queued[0][1]['span']['status']);

	}//end testASuccessfulSpanCanBeSampledOut()

	/**
	 * A queue that fails answers `queue-failed` and never throws into the sender.
	 *
	 * @return void
	 */
	public function testAFailingQueueAnswersQueueFailed(): void {
		$scheduler = $this->createMock(originalClassName: TraceExportSchedulerInterface::class);
		$scheduler->method('scheduleForeignSpan')->willThrowException(new RuntimeException('database gone'));

		$event = $this->dispatch(spans: [$this->span()], listener: $this->listener(scheduler: $scheduler));

		$this->assertSame(expected: ['queued' => 0, 'rejected' => 0, 'reasons' => ['queue-failed']], actual: $event->getResult());

	}//end testAFailingQueueAnswersQueueFailed()

	/**
	 * The listener ignores an event of another class.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->listener()->handle(new Event());

		$this->assertSame(expected: [], actual: $this->queued);

	}//end testAnotherEventIsIgnored()

	/**
	 * A failed send of a foreign span is retried with the span, not a trace id.
	 *
	 * @return void
	 */
	public function testAFailedForeignSendIsRetriedWithTheSpan(): void {
		$this->dispatch(spans: [$this->span()]);
		$exporter = $this->createMock(originalClassName: TraceExporterInterface::class);
		$exporter->method('export')->willThrowException(new RuntimeException('refused'));
		$retries = [];
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) use (&$retries): void {
				$retries[] = $argument;
			}
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains(string: 'dropped a trace after 4 failed sends'),
			['traceId' => self::TRACE_ID]
		);
		$job = new OtelExportJob(
			time: $time,
			traces: $this->createMock(originalClassName: ExecutionTraceServiceInterface::class),
			mapper: new SpanMapper(traceParent: new TraceParent()),
			exporter: $exporter,
			settings: $this->settings(),
			jobList: $jobList,
			logger: $logger,
			breaker: new OtelExportBreaker(appConfig: $this->appConfig(), cacheFactory: $this->createMock(originalClassName: ICacheFactory::class))
		);

		$job->run($this->queued[0][1]);
		$this->assertCount(expectedCount: 1, haystack: $retries);
		$this->assertSame(expected: ['appId', 'span', 'attempt', 'notBefore'], actual: array_keys($retries[0]));
		$this->assertSame(expected: 1, actual: $retries[0]['attempt']);

		$job->run(['appId' => 'opencatalogi', 'span' => $this->queued[0][1]['span'], 'attempt' => 3]);

	}//end testAFailedForeignSendIsRetriedWithTheSpan()
}//end class
