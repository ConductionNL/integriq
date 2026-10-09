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
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
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
	 * A caller's trace continues: the trace id comes from the header and the
	 * root span's parent is the header's span id.
	 *
	 * @return void
	 */
	public function testACallersTraceContinuesIntoIntegriq(): void {
		$trace = $this->endpointTrace('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');

		$this->assertSame('4bf92f35-77b3-4da6-a3ce-929d0e0e4736', $trace->getTraceId());
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
}//end class
