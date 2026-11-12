<?php

/**
 * One outbound call after its dispatch, on its way to the call log.
 *
 * Kept beside CallRequest so finishing a call (logging its exchange,
 * persisting the call log, tracing it) takes the call instead of the
 * response, URL, method, configuration and timing one by one. Never leaves
 * CallService.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Call;

/**
 * The prepared call, what its dispatch answered, and the exchange it logs.
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
 */
final class FinishedCall {

	/**
	 * The structured, redacted request and response the call log stores.
	 *
	 * @var array{request?: array<string, mixed>, response?: array<string, mixed>}
	 */
	public array $data = [];

	/**
	 * Constructor.
	 *
	 * @param array $prepared   The prepared call (source, URL, method, configuration, request, policies).
	 * @param array $dispatched The dispatch result: `response`, `timeStart` and `timeEnd`.
	 */
	public function __construct(
		public readonly array $prepared,
		public readonly array $dispatched,
	) {

	}//end __construct()

	/**
	 * When the dispatch started, in microtime.
	 *
	 * @return float The start.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	public function startedAt(): float {
		return (float)$this->dispatched['timeStart'];
	}//end startedAt()

	/**
	 * When the dispatch ended, in microtime.
	 *
	 * @return float The end.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	public function endedAt(): float {
		return (float)$this->dispatched['timeEnd'];
	}//end endedAt()
}//end class
