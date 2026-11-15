<?php

/**
 * Integriq TraceFrame.
 *
 * What every span of one exported trace shares: the execution trace id, the
 * W3C trace id in hex, the root span id, the root start and how many steps
 * the trace dropped.
 *
 * @category Observability
 * @package  OCA\Integriq\Observability\Otel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Observability\Otel;

/**
 * Value object for the shared ids and start of one exported trace.
 *
 * @spec openspec/changes/observability-opentelemetry-export/specs/execution-trace/spec.md#requirement-a-persisted-trace-is-exported-as-opentelemetry-spans-req-otel-001
 */
final class TraceFrame {

	/**
	 * How many steps the trace counted but did not keep, from its `truncated` marker.
	 *
	 * @var integer
	 */
	public int $droppedSteps = 0;

	/**
	 * Constructor.
	 *
	 * @param string $traceId    The execution trace id.
	 * @param string $traceHex   The W3C trace id in hex.
	 * @param string $rootSpanId The root span id.
	 * @param int    $rootStart  The root start in microseconds.
	 */
	public function __construct(
		public readonly string $traceId,
		public readonly string $traceHex,
		public readonly string $rootSpanId,
		public readonly int $rootStart,
	) {

	}//end __construct()
}//end class
