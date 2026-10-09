<?php

/**
 * Unit tests for the OpenTelemetry span mapper and the W3C traceparent
 * helper (observability-opentelemetry-export REQ-OTEL-001, -003, -004).
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

use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\TraceParent;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SpanMapper and TraceParent.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md
 */
class SpanMapperTest extends TestCase {

	private const TRACE_ID = '4bf92f35-77b3-4da6-a3ce-929d0e0e4736';

	/**
	 * A synchronization trace with a mapping step and two call steps, the
	 * second failing with 503; the mapping input holds a BSN.
	 *
	 * @return array The execution_trace data.
	 */
	private function trace(): array {
		return [
			'traceId' => self::TRACE_ID,
			'entryPoint' => 'sync',
			'entryPointId' => 'sync-1',
			'status' => 'failed',
			'startedAt' => '2026-10-09T10:00:00+00:00',
			'finishedAt' => '2026-10-09T10:00:01+00:00',
			'startedAtUs' => 1791540000000000,
			'finishedAtUs' => 1791540000900000,
			'parentSpanId' => '00f067aa0ba902b7',
			'steps' => [
				[
					'order' => 1, 'type' => 'mapping', 'name' => 'person', 'status' => 'success', 'durationMs' => 5,
					'startedAtUs' => 1791540000100000,
					'input' => ['bsn' => '999993653', 'name' => 'Jan Jansen'], 'output' => ['burgerservicenummer' => '999993653'],
				],
				[
					'order' => 2, 'type' => 'call', 'name' => 'BRP', 'status' => 'success', 'durationMs' => 40,
					'startedAtUs' => 1791540000100200, 'spanId' => 'a1b2c3d4e5f60718',
					'input' => ['method' => 'get', 'url' => 'https://brp.example.org/personen?bsn=999993653', 'headers' => ['Authorization' => 'x']],
					'output' => ['statusCode' => 200, 'body' => '{"bsn":"999993653"}'],
				],
				[
					'order' => 3, 'type' => 'call', 'name' => 'ZRC', 'status' => 'error', 'durationMs' => 12,
					'startedAtUs' => 1791540000300000,
					'input' => ['method' => 'post', 'url' => 'https://zrc.example.org/zaken'],
					'output' => ['statusCode' => 503],
				],
			],
		];
	}//end trace()

	/**
	 * The spans of a mapped trace.
	 *
	 * @param array $trace The trace.
	 *
	 * @return array<int, array> The spans.
	 */
	private function spans(array $trace): array {
		$payload = (new SpanMapper(new TraceParent()))->map(trace: $trace, serviceName: 'integriq');

		return $payload['resourceSpans'][0]['scopeSpans'][0]['spans'];
	}//end spans()

	/**
	 * Attributes of a span as a key => scalar map.
	 *
	 * @param array $span The span.
	 *
	 * @return array<string, string>
	 */
	private function attrs(array $span): array {
		$out = [];
		foreach ($span['attributes'] as $attribute) {
			$out[$attribute['key']] = (string)array_values($attribute['value'])[0];
		}

		return $out;
	}//end attrs()

	/**
	 * One root span named after the entry point and one child per step, all
	 * on the execution's trace id without dashes; the root continues the
	 * caller's span.
	 *
	 * @return void
	 */
	public function testATraceBecomesOneRootAndAChildPerStep(): void {
		$spans = $this->spans($this->trace());

		$this->assertCount(4, $spans);
		$this->assertSame('integriq.sync', $spans[0]['name']);
		$this->assertSame('00f067aa0ba902b7', $spans[0]['parentSpanId']);
		foreach ($spans as $span) {
			$this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['traceId']);
		}

		foreach (array_slice($spans, 1) as $child) {
			$this->assertSame($spans[0]['spanId'], $child['parentSpanId']);
		}

		$this->assertSame(['mapping person', 'call BRP', 'call ZRC'], array_column(array_slice($spans, 1), 'name'));
		$this->assertSame('1791540000100000000', $spans[1]['startTimeUnixNano']);
		$this->assertSame('1791540000105000000', $spans[1]['endTimeUnixNano']);
		$this->assertSame('a1b2c3d4e5f60718', $spans[2]['spanId'], 'a call step keeps the span id its traceparent carried');
		$this->assertSame(2, $spans[0]['status']['code']);

	}//end testATraceBecomesOneRootAndAChildPerStep()

	/**
	 * A failed call step is an error span carrying the response status code.
	 *
	 * @return void
	 */
	public function testAFailedStepIsAFailedSpan(): void {
		$zrc = $this->spans($this->trace())[3];

		$this->assertSame(2, $zrc['status']['code']);
		$this->assertSame('503', $this->attrs($zrc)['http.response.status_code']);
		$this->assertSame('POST', $this->attrs($zrc)['http.request.method']);

	}//end testAFailedStepIsAFailedSpan()

	/**
	 * No step input or output reaches the payload: the BSN, the name, the
	 * Authorization header and the query string are all absent.
	 *
	 * @return void
	 */
	public function testABsnNeverReachesTheCollector(): void {
		$payload = json_encode((new SpanMapper(new TraceParent()))->map(trace: $this->trace(), serviceName: 'integriq'));

		$this->assertStringNotContainsString('999993653', $payload);
		$this->assertStringNotContainsString('Jan Jansen', $payload);
		$this->assertStringNotContainsString('Authorization', $payload);
		$this->assertStringContainsString('https:\/\/brp.example.org\/personen"', $payload);

	}//end testABsnNeverReachesTheCollector()

	/**
	 * A BSN in the path and credentials in the userinfo never reach the
	 * collector: identifier segments become `{id}`, `user:pass@` is dropped,
	 * route words, the host and the port stay.
	 *
	 * @return void
	 */
	public function testABsnInThePathAndUrlCredentialsNeverReachTheCollector(): void {
		$trace = $this->trace();
		$trace['steps'] = [
			[
				'order' => 1, 'type' => 'call', 'name' => 'BRP', 'status' => 'success', 'durationMs' => 40,
				'input' => ['method' => 'get', 'url' => 'https://svc:s3cret@brp.example.org:8443/ingeschrevenpersonen/999993653/kinderen?x=1#y'],
			],
			[
				'order' => 2, 'type' => 'call', 'name' => 'ZRC', 'status' => 'success', 'durationMs' => 40,
				'input' => ['method' => 'get', 'url' => 'https://zrc.example.org/zaken/api/v1/zaken/7f3c2a1e-9b4d-4c6e-8a2f-1d0e5b6c7a89'],
			],
			[
				'order' => 3, 'type' => 'call', 'name' => 'KVK', 'status' => 'success', 'durationMs' => 40,
				'input' => ['method' => 'get', 'url' => 'https://api.kvk.nl/api/v1/basisprofielen/NL%2012345678'],
			],
		];

		$payload = json_encode((new SpanMapper(new TraceParent()))->map(trace: $trace, serviceName: 'integriq'));
		$spans = $this->spans($trace);

		foreach (['999993653', 's3cret', 'svc:', '7f3c2a1e', '12345678', 'x=1'] as $secret) {
			$this->assertStringNotContainsString($secret, $payload, $secret);
		}

		$this->assertSame('https://brp.example.org:8443/ingeschrevenpersonen/{id}/kinderen', $this->attrs($spans[1])['url.full']);
		$this->assertSame('https://zrc.example.org/zaken/api/v1/zaken/{id}', $this->attrs($spans[2])['url.full']);
		$this->assertSame('https://api.kvk.nl/api/v1/basisprofielen/{id}', $this->attrs($spans[3])['url.full']);

	}//end testABsnInThePathAndUrlCredentialsNeverReachTheCollector()

	/**
	 * A formatted BSN, an e-mail address and an opaque letter-only token are
	 * masked too; lowercase route words, however long, stay.
	 *
	 * @return void
	 */
	public function testFormattedIdentifiersAndOpaqueTokensAreMaskedRouteWordsStay(): void {
		$cases = [
			'https://brp.example.org/ingeschrevenpersonen/999.993.653' => 'https://brp.example.org/ingeschrevenpersonen/{id}',
			'https://brp.example.org/ingeschrevenpersonen/999-993-653' => 'https://brp.example.org/ingeschrevenpersonen/{id}',
			'https://brp.example.org/ingeschrevenpersonen/999%20993%20653' => 'https://brp.example.org/ingeschrevenpersonen/{id}',
			'https://crm.example.org/klanten/j.devries@gemeente.nl/contacten' => 'https://crm.example.org/klanten/{id}/contacten',
			'https://hooks.example.org/services/abcdefghijklmnopqrstuvwxyzabcdef' => 'https://hooks.example.org/services/{id}',
			'https://hooks.example.org/services/QwErTyUiOpAsDfGhJkLz' => 'https://hooks.example.org/services/{id}',
			'https://zrc.example.org/zaken/api/v1/zaakinformatieobjecten' => 'https://zrc.example.org/zaken/api/v1/zaakinformatieobjecten',
			'https://brp.example.org/haalcentraal/api/brp/ingeschrevenpersonen/kinderen' => 'https://brp.example.org/haalcentraal/api/brp/ingeschrevenpersonen/kinderen',
		];

		$trace = $this->trace();
		$trace['steps'] = [];
		foreach (array_keys($cases) as $index => $url) {
			$trace['steps'][] = ['order' => ($index + 1), 'type' => 'call', 'name' => 'svc', 'status' => 'success', 'durationMs' => 1, 'input' => ['method' => 'get', 'url' => $url]];
		}

		$spans = $this->spans($trace);
		foreach (array_values($cases) as $index => $expected) {
			$this->assertSame($expected, $this->attrs($spans[($index + 1)])['url.full']);
		}

		$payload = json_encode((new SpanMapper(new TraceParent()))->map(trace: $trace, serviceName: 'integriq'));
		foreach (['993', 'devries', 'gemeente.nl', 'abcdefghijklmnopqrstuvwxyzabcdef', 'QwErTyUiOpAsDfGhJkLz'] as $secret) {
			$this->assertStringNotContainsString($secret, $payload, $secret);
		}

	}//end testFormattedIdentifiersAndOpaqueTokensAreMaskedRouteWordsStay()

	/**
	 * Steps counted but not kept are the root's `integriq.steps.dropped`
	 * attribute, not a span; a trace without micro times falls back to the
	 * whole-second times.
	 *
	 * @return void
	 */
	public function testDroppedStepsAreARootAttributeAndOldTracesStillMap(): void {
		$trace = $this->trace();
		unset($trace['startedAtUs'], $trace['finishedAtUs'], $trace['parentSpanId']);
		$trace['steps'] = [
			['order' => 1, 'type' => 'rule', 'name' => 'r', 'status' => 'success', 'durationMs' => 0, 'startedAt' => '2026-10-09T10:00:00+00:00'],
			['order' => 2, 'type' => 'synchronization', 'name' => 'truncated', 'status' => 'truncated', 'output' => ['droppedSteps' => 742]],
		];

		$spans = $this->spans($trace);

		$this->assertCount(2, $spans);
		$this->assertSame('742', $this->attrs($spans[0])['integriq.steps.dropped']);
		$this->assertArrayNotHasKey('parentSpanId', $spans[0]);
		$this->assertSame('1791540000000000000', $spans[0]['startTimeUnixNano']);

	}//end testDroppedStepsAreARootAttributeAndOldTracesStillMap()

	/**
	 * Two steps within one second keep their order through startedAtUs.
	 *
	 * @return void
	 */
	public function testStepsWithinOneSecondOrderByMicroseconds(): void {
		$trace = new ExecutionTraceContext(entryPoint: 'job');
		$base = 1791540000.25;
		$trace->addStep(type: 'rule', name: 'first', timing: null, status: 'success', startedAtMicrotime: $base, finishedAtMicrotime: $base + 0.001);
		$trace->addStep(type: 'rule', name: 'second', timing: null, status: 'success', startedAtMicrotime: $base + 0.002, finishedAtMicrotime: $base + 0.003);

		$steps = $trace->getSteps();

		$this->assertSame($steps[0]['startedAt'], $steps[1]['startedAt']);
		$this->assertLessThan($steps[1]['startedAtUs'], $steps[0]['startedAtUs']);
		$this->assertSame(1791540000250000, $steps[0]['startedAtUs']);
		$this->assertGreaterThan(0, $trace->getStartedAtUs());

	}//end testStepsWithinOneSecondOrderByMicroseconds()

	/**
	 * A valid traceparent yields the dashed trace id and the caller's span;
	 * anything else is ignored.
	 *
	 * @return void
	 */
	public function testTraceParentAcceptsOnlyTheW3cShape(): void {
		$parent = new TraceParent();

		$this->assertSame(
			['traceId' => self::TRACE_ID, 'parentSpanId' => '00f067aa0ba902b7'],
			$parent->parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')
		);
		foreach ([null, '', 'garbage', '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
			'00-00000000000000000000000000000000-00f067aa0ba902b7-01', '00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01',
			'00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01; drop table'] as $bad) {
			$this->assertNull($parent->parse($bad), (string)$bad);
		}

		$this->assertSame('00-4bf92f3577b34da6a3ce929d0e0e4736-a1b2c3d4e5f60718-01', $parent->format(self::TRACE_ID, 'a1b2c3d4e5f60718'));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $parent->newSpanId());

	}//end testTraceParentAcceptsOnlyTheW3cShape()
}//end class
