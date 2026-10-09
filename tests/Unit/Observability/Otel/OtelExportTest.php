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
use OCP\IL10N;
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
	 * Settings backed by $this->config.
	 *
	 * @return OtelSettings
	 */
	private function settings(): OtelSettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		$set = function (string $app, string $key, $value): bool {
			$this->config[$key] = $value;
			return true;
		};
		foreach (['getValueBool', 'getValueString', 'getValueFloat'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		foreach (['setValueBool', 'setValueString', 'setValueFloat'] as $setter) {
			$appConfig->method($setter)->willReturnCallback($set);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new OtelSettings($appConfig, $l10n);
	}//end settings()

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
		$jobList->method('add')->willReturnCallback(
			function (string $job, $argument) use (&$requeued): void {
				$requeued[] = $argument['attempt'];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$job = new OtelExportJob($this->createMock(ITimeFactory::class), $traces, new SpanMapper(new TraceParent()), $exporter, $this->settings(), $jobList, $logger);
		foreach ([0, 1, 2, 3] as $attempt) {
			$job->run(['traceId' => 't-1', 'attempt' => $attempt]);
		}

		$this->assertSame([1, 2, 3], $requeued);

	}//end testARefusedBatchStopsAfterFourTriesAndLogsOnce()

	/**
	 * Export switched off after queuing: the job sends nothing.
	 *
	 * @return void
	 */
	public function testTheJobSendsNothingWhenExportIsOff(): void {
		$this->config = ['otel_enabled' => false, 'otel_endpoint' => 'https://otel.example.org'];
		$exporter = $this->createMock(TraceExporterInterface::class);
		$exporter->expects($this->never())->method('export');

		(new OtelExportJob($this->createMock(ITimeFactory::class), $this->createMock(ExecutionTraceService::class), new SpanMapper(new TraceParent()), $exporter, $this->settings(), $this->createMock(IJobList::class), $this->createMock(LoggerInterface::class)))
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
