<?php

/**
 * Unit tests for W3C trace context in and out of integriq
 * (observability-opentelemetry-export REQ-OTEL-004): an endpoint continues
 * a caller's trace, and an outbound call carries integriq's.
 *
 * Both seams are private helpers on very large services; they touch none
 * of their service's collaborators, so the tests reach them on an instance
 * built without its constructor.
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
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability\Otel;

use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\TraceParent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for traceparent propagation.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-trace-context-travels-in-and-out-as-w3c-traceparent-req-otel-004
 */
class TraceContextPropagationTest extends TestCase {

	/**
	 * Call a private method on an instance built without its constructor.
	 *
	 * @param string $class The service class.
	 * @param string $method The private method.
	 * @param array $args Named arguments.
	 *
	 * @return mixed The method's result.
	 */
	private function callPrivate(string $class, string $method, array $args): mixed {
		$reflection = new ReflectionClass($class);
		$instance = $reflection->newInstanceWithoutConstructor();

		return $reflection->getMethod($method)->invoke($instance, ...$args);
	}//end callPrivate()

	/**
	 * The endpoint trace for a request carrying the given traceparent.
	 *
	 * @param string $header The header value.
	 *
	 * @return ExecutionTraceContext
	 */
	private function endpointTrace(string $header): ExecutionTraceContext {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name) => strtolower($name) === 'traceparent' ? $header : '');
		$endpoint = new ObjectEntity();
		$endpoint->setUuid('ep-1');

		return $this->callPrivate(EndpointService::class, 'mintEndpointTrace', ['endpoint' => $endpoint, 'request' => $request]);
	}//end endpointTrace()

	/**
	 * A caller's trace continues: the W3C trace id comes from the header and
	 * the root span's parent is the header's span id, but the record keeps
	 * an id of Integriq's own.
	 *
	 * @return void
	 */
	public function testACallersTraceContinuesIntoIntegriq(): void {
		$trace = $this->endpointTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

		$this->assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $trace->getOtelTraceId());
		$this->assertNotSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $trace->getTraceId());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $trace->getTraceId());
		$this->assertSame('00f067aa0ba902b7', $trace->getParentSpanId());
		$this->assertSame('endpoint', $trace->getEntryPoint());
		$this->assertSame('http', $trace->getTriggeredBy());

	}//end testACallersTraceContinuesIntoIntegriq()

	/**
	 * An invalid header is ignored and a fresh trace id is minted.
	 *
	 * @return void
	 */
	public function testAnInvalidHeaderMintsAFreshTrace(): void {
		$trace = $this->endpointTrace('00-zzzz-00f067aa0ba902b7-01');

		$this->assertNotSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $trace->getTraceId());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $trace->getTraceId());
		$this->assertNull($trace->getParentSpanId());
		$this->assertNull($trace->getInboundOtelTraceId());
		$this->assertSame($trace->getTraceId(), $trace->getOtelTraceId());

	}//end testAnInvalidHeaderMintsAFreshTrace()

	/**
	 * An outbound call during a trace carries a traceparent with the trace's
	 * id and a span id the call step then records, so the exported span is
	 * the one the partner saw. Without a trace, and with a traceparent the
	 * caller set itself, nothing changes.
	 *
	 * @return void
	 */
	public function testAPartnerSeesIntegriqsTrace(): void {
		$trace = new ExecutionTraceContext(entryPoint: 'endpoint', traceId: '4bf92f35-77b3-4da6-a3ce-929d0e0e4736');
		$prepared = ['config' => ['headers' => ['Accept' => 'application/json']]];

		$withHeader = $this->callPrivate(CallService::class, 'withTraceParent', ['prepared' => $prepared, 'trace' => $trace]);

		$header = $withHeader['config']['headers']['traceparent'];
		$this->assertMatchesRegularExpression('/^00-4bf92f3577b34da6a3ce929d0e0e4736-([0-9a-f]{16})-01$/', $header);
		$this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-' . $withHeader['spanId'] . '-01', $header);
		$this->assertSame('application/json', $withHeader['config']['headers']['Accept']);

		$trace->addStep(type: 'call', name: 'BRP', timing: null, status: 'success', spanId: $withHeader['spanId']);
		$step = $trace->getSteps()[0];
		$this->assertSame($withHeader['spanId'], (new SpanMapper(new TraceParent()))->stepSpanId(step: $step, traceId: $trace->getTraceId()));

		$this->assertSame($prepared, $this->callPrivate(CallService::class, 'withTraceParent', ['prepared' => $prepared, 'trace' => null]));
		$own = ['config' => ['headers' => ['TraceParent' => 'mine']]];
		$this->assertSame($own, $this->callPrivate(CallService::class, 'withTraceParent', ['prepared' => $own, 'trace' => $trace]));

	}//end testAPartnerSeesIntegriqsTrace()

	/**
	 * Two requests carrying the same traceparent are two executions: two
	 * records with two different uuids, neither of them the caller's trace
	 * id, each keeping the caller's id apart. A caller who knows a trace id
	 * can therefore not overwrite that execution's record.
	 *
	 * @return void
	 */
	public function testTheSameTraceparentTwiceWritesTwoRecords(): void {
		$header = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
		$saves = [];
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null) use (&$saves): ObjectEntity {
				$saves[] = ['uuid' => $uuid, 'object' => $object];

				return new ObjectEntity();
			}
		);
		$service = new ExecutionTraceService($objects, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$service->persist(trace: $this->endpointTrace($header), status: 'success');
		$service->persist(trace: $this->endpointTrace($header), status: 'success');

		$this->assertCount(2, $saves);
		$this->assertNotSame($saves[0]['uuid'], $saves[1]['uuid']);
		foreach ($saves as $save) {
			$this->assertNotSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $save['uuid']);
			$this->assertSame($save['uuid'], $save['object']['traceId']);
			$this->assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $save['object']['otelTraceId']);
		}

	}//end testTheSameTraceparentTwiceWritesTwoRecords()

	/**
	 * A continued trace travels on under the caller's W3C id: an outbound
	 * call's traceparent and the exported spans carry it, while the spans
	 * still name the record by its own id. Integriq's own id never reaches
	 * the partner, so the partner learns no record id.
	 *
	 * @return void
	 */
	public function testAContinuedTraceTravelsUnderTheCallersId(): void {
		$trace = $this->endpointTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
		$own = str_replace('-', '', $trace->getTraceId());

		$withHeader = $this->callPrivate(
			CallService::class,
			'withTraceParent',
			['prepared' => ['config' => ['headers' => []]], 'trace' => $trace]
		);
		$this->assertStringStartsWith('00-4bf92f3577b34da6a3ce929d0e0e4736-', $withHeader['config']['headers']['traceparent']);
		$this->assertStringNotContainsString($own, $withHeader['config']['headers']['traceparent']);

		$payload = (new SpanMapper(new TraceParent()))->map(
			trace: [
				'traceId' => $trace->getTraceId(),
				'otelTraceId' => $trace->getOtelTraceId(),
				'entryPoint' => 'endpoint',
				'status' => 'success',
				'startedAt' => '2026-10-09T10:00:00+00:00',
				'finishedAt' => '2026-10-09T10:00:01+00:00',
				'steps' => [],
			],
			serviceName: 'integriq'
		);
		$root = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
		$this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $root['traceId']);

	}//end testAContinuedTraceTravelsUnderTheCallersId()

	/**
	 * An approval suspension keeps the caller's W3C trace: the resumed
	 * context has the record's own id, the caller's trace id and span, and
	 * a snapshot without them (Integriq started the trace) rehydrates
	 * without them.
	 *
	 * @return void
	 */
	public function testAnApprovalResumeKeepsTheCallersTrace(): void {
		$resumed = $this->callPrivate(
			\OCA\Integriq\Service\ApprovalService::class,
			'rehydrateTraceContext',
			['snapshot' => [
				'traceId' => 'b7ad6b71-6928-4c7e-9f5e-2d1c0a3e4f50',
				'otelTraceId' => '4bf92f35-77b3-4da6-a3ce-929d0e0e4736',
				'parentSpanId' => '00f067aa0ba902b7',
			],
			]
		);

		$this->assertSame('b7ad6b71-6928-4c7e-9f5e-2d1c0a3e4f50', $resumed->getTraceId());
		$this->assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $resumed->getOtelTraceId());
		$this->assertSame('00f067aa0ba902b7', $resumed->getParentSpanId());

		$own = $this->callPrivate(
			\OCA\Integriq\Service\ApprovalService::class,
			'rehydrateTraceContext',
			['snapshot' => ['traceId' => 'b7ad6b71-6928-4c7e-9f5e-2d1c0a3e4f50', 'parentSpanId' => 'not-hex']]
		);
		$this->assertNull($own->getInboundOtelTraceId());
		$this->assertNull($own->getParentSpanId());

	}//end testAnApprovalResumeKeepsTheCallersTrace()
}//end class
