<?php

/**
 * Unit tests for the span command event (REQ-OTEL-007).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
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

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\SpanExportRequestedEvent;
use OCA\Integriq\Tests\Helpers\ServiceListenerDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The event answers "not exported" until a listener says otherwise.
 */
class SpanExportRequestedEventTest extends TestCase {

	/**
	 * A dispatch nobody handles answers `queued: 0` with the reason `no-listener`.
	 *
	 * @return void
	 */
	public function testAnUnhandledEventAnswersNotExported(): void {
		$spans = [['traceId' => '4bf92f3577b34da6a3ce929d0e0e4736']];
		$event = new SpanExportRequestedEvent(appId: 'opencatalogi', spans: $spans);

		(new ServiceListenerDispatcher(services: []))->dispatchTyped($event);

		$this->assertSame(expected: ['queued' => 0, 'rejected' => 0, 'reasons' => ['no-listener']], actual: $event->getResult());
		$this->assertSame(expected: 'opencatalogi', actual: $event->getAppId());
		$this->assertSame(expected: $spans, actual: $event->getSpans());

	}//end testAnUnhandledEventAnswersNotExported()

	/**
	 * The recorded result keeps each reason once, as a list of strings.
	 *
	 * @return void
	 */
	public function testTheResultKeepsEachReasonOnce(): void {
		$event = new SpanExportRequestedEvent(appId: 'openregister', spans: []);

		$event->setResult(queued: 2, rejected: 1, reasons: ['sampled-out', 'spans[1].name: must be a non-empty string', 'sampled-out']);

		$this->assertSame(
			expected: ['queued' => 2, 'rejected' => 1, 'reasons' => ['sampled-out', 'spans[1].name: must be a non-empty string']],
			actual: $event->getResult()
		);

	}//end testTheResultKeepsEachReasonOnce()
}//end class
