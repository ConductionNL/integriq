<?php

/**
 * Unit tests for the OpenTelemetry export path: settings and sampling, the
 * OTLP exporter, the export job's retries, and the queue on persist
 * (observability-opentelemetry-export REQ-OTEL-001, -002, -005).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability\Otel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability\Otel;

use InvalidArgumentException;
use OCA\Integriq\BackgroundJob\OtelExportJob;
use OCA\Integriq\Observability\Otel\OtelExportBreaker;
use OCA\Integriq\Observability\Otel\OtelSettings;
use OCA\Integriq\Observability\Otel\OtlpTraceExporter;
use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\TraceExporterInterface;
use OCA\Integriq\Observability\Otel\TraceExportQueue;
use OCA\Integriq\Observability\Otel\TraceParent;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the export path.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 */
class OtelExportTest extends TestCase {

	/**
	 * Stored app settings, as an in-memory IAppConfig.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * The keys written to $this->config, in order.
	 *
	 * @var array<int, string>
	 */
	private array $writes = [];

	/**
	 * The skipped-trace counter in the fake distributed cache.
	 *
	 * @var int
	 */
	private int $skippedInCache = 0;

	/**
	 * Settings backed by $this->config.
	 *
	 * @return OtelSettings
	 */
	private function settings(): OtelSettings {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OtelSettings($this->appConfig(), $l10n);
	}//end settings()

	/**
	 * The export breaker, backed by $this->config and, unless another
	 * factory is given, a distributed cache counting in $this->skippedInCache.
	 *
	 * @param ICacheFactory|null $cacheFactory The cache factory to use.
	 *
	 * @return OtelExportBreaker
	 */
	private function breaker(?ICacheFactory $cacheFactory = null): OtelExportBreaker {
		if ($cacheFactory === null) {
			$cache = $this->createMock(IMemcache::class);
			$cache->method('inc')->willReturnCallback(
				function (string $key): int {
					$this->assertSame('otel_skipped_total', $key);
					return ++$this->skippedInCache;
				}
			);
			$cacheFactory = $this->createMock(ICacheFactory::class);
			$cacheFactory->method('createDistributed')->willReturn($cache);
		}

		return new OtelExportBreaker($this->appConfig(), $cacheFactory);
	}//end breaker()

	/**
	 * An in-memory IAppConfig over $this->config.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		$set = function (string $app, string $key, $value): bool {
			$this->writes[] = $key;
			$this->config[$key] = $value;
			return true;
		};
		foreach (['getValueBool', 'getValueString', 'getValueFloat', 'getValueInt'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		foreach (['setValueBool', 'setValueString', 'setValueFloat', 'setValueInt'] as $setter) {
			$appConfig->method($setter)->willReturnCallback($set);
		}

		return $appConfig;
	}//end appConfig()

	/**
	 * A failed or replayed trace is always sampled; at ratio 0 a successful
	 * trace is not, at ratio 1 it is, and one trace always gets one answer.
	 *
	 * @return void
	 */
	public function testAFailedTraceIsAlwaysSampled(): void {
		$settings = $this->settings();
		$this->config['otel_sampling_ratio'] = 0.0;

		$this->assertTrue($settings->isSampled('t-1', 'failed', false));
		$this->assertTrue($settings->isSampled('t-1', 'success', true));
		$this->assertFalse($settings->isSampled('t-1', 'success', false));

		$this->config['otel_sampling_ratio'] = 1.0;
		$this->assertTrue($settings->isSampled('t-1', 'success', false));

		$this->config['otel_sampling_ratio'] = 0.5;
		$answers = array_map(static fn () => $settings->isSampled('t-fixed', 'success', false), range(1, 5));
		$this->assertCount(1, array_unique($answers));

	}//end testAFailedTraceIsAlwaysSampled()

	/**
	 * An administrator switches export on; http needs the internal mark, a
	 * login in the URL and a ratio out of range are refused.
	 *
	 * @return void
	 */
	public function testAnAdministratorSwitchesExportOn(): void {
		$settings = $this->settings();

		$stored = $settings->save(['enabled' => true, 'endpoint' => 'https://otel.example.org:4318/', 'samplingRatio' => 0.1, 'credentialName' => 'otel-token']);

		$this->assertTrue($stored['enabled']);
		$this->assertSame('https://otel.example.org:4318', $stored['endpoint']);
		$this->assertSame(0.1, $stored['samplingRatio']);
		$this->assertSame('integriq', $stored['serviceName']);
		$this->assertSame('Authorization', $stored['headerName']);
		$this->assertTrue($settings->isEnabled());

		$this->assertSame('http://collector:4318', $settings->save(['enabled' => true, 'endpoint' => 'http://collector:4318', 'allowLocal' => true])['endpoint']);

		foreach ([
			['enabled' => true, 'endpoint' => 'http://collector:4318'],
			['enabled' => true, 'endpoint' => 'https://user:pw@otel.example.org'],
			['enabled' => true, 'endpoint' => ''],
			['enabled' => false, 'endpoint' => 'https://otel.example.org', 'samplingRatio' => 1.5],
		] as $refused) {
			try {
				$settings->save($refused);
				$this->fail('accepted ' . json_encode($refused));
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

	}//end testAnAdministratorSwitchesExportOn()

	/**
	 * One POST of JSON to `/v1/traces`, with the collector credential
	 * resolved from its reference into the configured header.
	 *
	 * @return void
	 */
	public function testAMappedTraceIsPostedOnceToV1Traces(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org:4318', 'otel_credential_name' => 'otel-token', 'otel_header_name' => 'X-Api-Key'];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$client = $this->createMock(IClient::class);
		$posted = [];
		$client->expects($this->once())->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$posted, $response) {
				$posted = [$url, $options];
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$credentials = $this->createMock(BrokeredCallService::class);
		$credentials->expects($this->once())->method('resolveCredentialRef')->with(['credentialName' => 'otel-token'])->willReturn('s3cret');

		$payload = (new SpanMapper(new TraceParent()))->map(trace: ['traceId' => '4bf92f35-77b3-4da6-a3ce-929d0e0e4736', 'entryPoint' => 'endpoint', 'steps' => []], serviceName: 'integriq');
		(new OtlpTraceExporter($clients, $this->settings(), $credentials))->export(payload: $payload);

		$this->assertSame('https://otel.example.org:4318/v1/traces', $posted[0]);
		$this->assertSame('application/json', $posted[1]['headers']['Content-Type']);
		$this->assertSame('s3cret', $posted[1]['headers']['X-Api-Key']);
		$this->assertSame($payload, json_decode($posted[1]['body'], true));
		$this->assertArrayNotHasKey('nextcloud', $posted[1]);

	}//end testAMappedTraceIsPostedOnceToV1Traces()

	/**
	 * A collector answering 503 is a failed send.
	 *
	 * @return void
	 */
	public function testARefusingCollectorIsAFailedSend(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org'];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(503);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$this->expectException(RuntimeException::class);
		(new OtlpTraceExporter($clients, $this->settings(), $this->createMock(BrokeredCallService::class)))->export(payload: []);

	}//end testARefusingCollectorIsAFailedSend()

	/**
	 * A refused batch is retried three times; the fourth failure drops it
	 * with one log line.
	 *
	 * @return void
	 */
	public function testARefusedBatchStopsAfterFourTriesAndLogsOnce(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org'];
		$traces = $this->createMock(ExecutionTraceService::class);
		$traces->method('findForExport')->willReturn(['traceId' => 't-1', 'entryPoint' => 'job', 'steps' => []]);
		$exporter = $this->createMock(TraceExporterInterface::class);
		$exporter->expects($this->exactly(4))->method('export')->willThrowException(new RuntimeException('refused'));
		$jobList = $this->createMock(IJobList::class);
		$requeued = [];
		$jobList->expects($this->never())->method('add');
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) use (&$requeued): void {
				$requeued[] = $argument['attempt'];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('after 4 failed sends'));

		$job = new OtelExportJob($this->createMock(ITimeFactory::class), $traces, new SpanMapper(new TraceParent()), $exporter, $this->settings(), $jobList, $logger, $this->breaker());
		foreach ([0, 1, 2, 3] as $attempt) {
			$job->run(['traceId' => 't-1', 'attempt' => $attempt]);
		}

		$this->assertSame([1, 2, 3], $requeued);

	}//end testARefusedBatchStopsAfterFourTriesAndLogsOnce()

	/**
	 * A clock frozen at the given unix time.
	 *
	 * @param int $now The time.
	 *
	 * @return ITimeFactory
	 */
	private function clock(int $now): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		return $time;
	}//end clock()

	/**
	 * A failed send is retried later, not at once: five, ten and twenty
	 * minutes after the failure, each scheduled for its run time so the job
	 * row is not runnable before then. A retry that is not due yet is
	 * scheduled again for its run time, unchanged and without a send.
	 *
	 * @return void
	 */
	public function testARetryWaitsLongerEachTime(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org'];
		$traces = $this->createMock(ExecutionTraceService::class);
		$traces->method('findForExport')->willReturn(['traceId' => 't-1', 'entryPoint' => 'job', 'steps' => []]);
		$exporter = $this->createMock(TraceExporterInterface::class);
		$exporter->expects($this->exactly(3))->method('export')->willThrowException(new RuntimeException('down'));
		$jobList = $this->createMock(IJobList::class);
		$requeued = [];
		$jobList->expects($this->never())->method('add');
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) use (&$requeued): void {
				$requeued[] = ['job' => $job, 'runAfter' => $runAfter, 'argument' => $argument];
			}
		);

		$job = new OtelExportJob($this->clock(1000), $traces, new SpanMapper(new TraceParent()), $exporter, $this->settings(), $jobList, $this->createMock(LoggerInterface::class), $this->breaker());
		foreach ([0, 1, 2] as $attempt) {
			$job->run(['traceId' => 't-1', 'attempt' => $attempt]);
		}

		$this->assertSame([1300, 1600, 2200], array_column($requeued, 'runAfter'), 'the job row itself waits until the retry is due');
		$this->assertSame([1300, 1600, 2200], array_column(array_column($requeued, 'argument'), 'notBefore'));
		$this->assertSame([OtelExportJob::class], array_unique(array_column($requeued, 'job')));

		$requeued = [];
		$job->run(['traceId' => 't-1', 'attempt' => 1, 'notBefore' => 1001]);
		$this->assertSame([['job' => OtelExportJob::class, 'runAfter' => 1001, 'argument' => ['traceId' => 't-1', 'attempt' => 1, 'notBefore' => 1001]]], $requeued);

	}//end testARetryWaitsLongerEachTime()

	/**
	 * Five failed sends in a row pause sends for five minutes: the next
	 * trace is not sent but waits for the pause to end without using up an
	 * attempt, and nothing new is queued. After the cooldown sends resume,
	 * and a success ends the run of failures.
	 *
	 * @return void
	 */
	public function testACollectorOutageOpensTheBreaker(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org', 'otel_sampling_ratio' => 1.0];
		$traces = $this->createMock(ExecutionTraceService::class);
		$traces->method('findForExport')->willReturn(['traceId' => 't-1', 'entryPoint' => 'job', 'steps' => []]);
		$sends = 0;
		$failing = true;
		$exporter = $this->createMock(TraceExporterInterface::class);
		$exporter->method('export')->willReturnCallback(
			function () use (&$sends, &$failing): void {
				$sends++;
				if ($failing === true) {
					throw new RuntimeException('down');
				}
			}
		);
		$jobList = $this->createMock(IJobList::class);
		$requeued = [];
		$jobList->method('scheduleAfter')->willReturnCallback(
			function (string $job, int $runAfter, $argument) use (&$requeued): void {
				$requeued[] = ['runAfter' => $runAfter] + $argument;
			}
		);
		$settings = $this->settings();
		$breaker = $this->breaker();
		$mapper = new SpanMapper(new TraceParent());
		$logger = $this->createMock(LoggerInterface::class);

		$job = new OtelExportJob($this->clock(1000), $traces, $mapper, $exporter, $settings, $jobList, $logger, $breaker);
		foreach (range(1, 5) as $trace) {
			$job->run(['traceId' => 't-' . $trace, 'attempt' => 0]);
		}

		$this->assertSame(5, $sends);
		$this->assertTrue($breaker->isOpen(now: 1000));

		$requeued = [];
		$job->run(['traceId' => 't-6', 'attempt' => 0]);
		$this->assertSame(5, $sends, 'no send while the breaker is open');
		$pauseEnd = (1000 + OtelExportBreaker::COOLDOWN_SECONDS);
		$this->assertSame([['runAfter' => $pauseEnd, 'traceId' => 't-6', 'attempt' => 0, 'notBefore' => $pauseEnd]], $requeued, 'a paused send waits for the pause to end and keeps its attempt');

		$requeued = [];
		$job->run(['traceId' => 't-6', 'attempt' => 2]);
		$this->assertSame(2, $requeued[0]['attempt'], 'a paused retry keeps its attempt too');

		$failing = false;
		$later = new OtelExportJob($this->clock(1000 + OtelExportBreaker::COOLDOWN_SECONDS), $traces, $mapper, $exporter, $settings, $jobList, $logger, $breaker);
		$later->run(['traceId' => 't-7', 'attempt' => 0]);
		$this->assertSame(6, $sends);
		$this->assertSame(0, $this->config['otel_breaker_failures']);

	}//end testACollectorOutageOpensTheBreaker()

	/**
	 * A sampled trace skipped while sends are paused is counted with an
	 * atomic increment in the distributed cache, never with an app-config
	 * write per trace; only the first one in each pause is logged, with the
	 * running total of skipped traces.
	 *
	 * @return void
	 */
	public function testTracesSkippedDuringAPauseAreCountedAndLoggedOncePerPause(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org', 'otel_sampling_ratio' => 0.0, 'otel_breaker_open_until' => 1300];
		$jobList = $this->createMock(IJobList::class);
		$jobList->expects($this->never())->method('add');
		$logger = $this->createMock(LoggerInterface::class);
		$warnings = [];
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$warnings): void {
				$warnings[] = $context['skippedTotal'];
			}
		);
		$queue = new TraceExportQueue($this->settings(), $jobList, $logger, $this->breaker(), $this->clock(1000));
		$this->writes = [];

		$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'failed'));
		$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'failed'));
		$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'failed'));
		$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'success'), 'not sampled');

		$this->assertSame(3, $this->skippedInCache, 'every sampled trace skipped in the pause is counted in the cache');
		$this->assertSame(['otel_skipped_warned_until'], $this->writes, 'one app-config write per pause, none per trace');
		$this->assertSame([1], $warnings, 'one warning per pause, not per trace');

		$this->config['otel_breaker_open_until'] = 1600;
		$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'failed'));
		$this->assertSame(4, $this->skippedInCache);
		$this->assertSame([1, 4], $warnings, 'a new pause logs again, with the count so far');

	}//end testTracesSkippedDuringAPauseAreCountedAndLoggedOncePerPause()

	/**
	 * Without a distributed memory cache, or when it fails, a skipped trace
	 * still never throws into the traced work, and the pause is still
	 * logged once.
	 *
	 * @return void
	 */
	public function testASkippedTraceWithoutADistributedCacheStillLogsOnce(): void {
		$plain = $this->createMock(ICacheFactory::class);
		$plain->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$failing = $this->createMock(ICacheFactory::class);
		$failing->method('createDistributed')->willThrowException(new RuntimeException('no cache'));

		foreach ([$plain, $failing] as $cacheFactory) {
			$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org', 'otel_breaker_open_until' => 1300];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('warning')->with($this->anything(), $this->callback(static fn (array $context) => $context['skippedTotal'] === 0));
			$queue = new TraceExportQueue($this->settings(), $this->createMock(IJobList::class), $logger, $this->breaker($cacheFactory), $this->clock(1000));

			$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'failed'));
			$this->assertFalse($queue->queue(trace: new ExecutionTraceContext(entryPoint: 'job'), status: 'failed'));
		}

	}//end testASkippedTraceWithoutADistributedCacheStillLogsOnce()

	/**
	 * Export switched off after queuing: the job sends nothing.
	 *
	 * @return void
	 */
	public function testTheJobSendsNothingWhenExportIsOff(): void {
		$this->config = ['otel_enabled' => false, 'otel_endpoint' => 'https://otel.example.org'];
		$exporter = $this->createMock(TraceExporterInterface::class);
		$exporter->expects($this->never())->method('export');

		(new OtelExportJob($this->createMock(ITimeFactory::class), $this->createMock(ExecutionTraceService::class), new SpanMapper(new TraceParent()), $exporter, $this->settings(), $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class), $this->breaker()))
			->run(['traceId' => 't-1', 'attempt' => 0]);

	}//end testTheJobSendsNothingWhenExportIsOff()

	/**
	 * persist() makes no HTTP call: it queues a finished, sampled trace, and
	 * does not queue a running one or any when export is off. The payload
	 * it writes is accepted by the real execution_trace schema.
	 *
	 * @return void
	 */
	public function testPersistQueuesAndNeverSends(): void {
		$this->config = ['otel_enabled' => true, 'otel_endpoint' => 'https://otel.example.org', 'otel_sampling_ratio' => 1.0];
		$saved = [];
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved) {
				$saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setObject($object);
				return $entity;
			}
		);
		$jobList = $this->createMock(IJobList::class);
		$queued = [];
		$jobList->method('add')->willReturnCallback(
			function (string $job, $argument) use (&$queued): void {
				$queued[] = [$job, $argument];
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->expects($this->never())->method('newClient');

		$service = new ExecutionTraceService($objects, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), new TraceExportQueue($this->settings(), $jobList, $this->createMock(LoggerInterface::class)));

		$trace = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'ep-1', traceId: '4bf92f35-77b3-4da6-a3ce-929d0e0e4736');
		$trace->setParentSpanId('00f067aa0ba902b7');
		$trace->addStep(type: 'rule', name: 'r1', timing: 'before', status: 'success');
		$service->persist(trace: $trace, status: 'running');
		$service->persist(trace: $trace, status: 'success');

		$this->assertSame([[OtelExportJob::class, ['traceId' => '4bf92f35-77b3-4da6-a3ce-929d0e0e4736', 'attempt' => 0]]], $queued);
		$this->assertSame('00f067aa0ba902b7', $saved[1]['parentSpanId']);
		$this->assertIsInt($saved[1]['startedAtUs']);
		$this->assertGreaterThanOrEqual($saved[1]['startedAtUs'], $saved[1]['finishedAtUs']);
		$this->assertSame([], RegisterSchemaValidator::errors('execution_trace', array_filter($saved[1], static fn ($v) => $v !== null)));

		$this->config['otel_enabled'] = false;
		$service->persist(trace: $trace, status: 'failed');
		$this->assertCount(1, $queued);

	}//end testPersistQueuesAndNeverSends()
}//end class
