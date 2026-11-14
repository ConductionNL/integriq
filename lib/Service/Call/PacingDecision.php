<?php

/**
 * Integriq Pacing Decision
 *
 * What the source pacer answers for one call: how long to wait, or that the
 * wait is longer than the caller may wait.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Call;

/**
 * The outcome of {@see SourcePacer::acquire()}.
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-a-live-call-waits-briefly-background-work-waits-for-the-window-req-rlpc-002
 */
final class PacingDecision {

	/**
	 * @param int $waitMicros How long the call waits before it is sent (0 when refused).
	 * @param bool $refused True when the wait exceeds what the caller may wait; the call is not sent.
	 * @param int $retryAfterSeconds When refused: the seconds until the call's turn, at least 1.
	 */
	public function __construct(
		public readonly int $waitMicros = 0,
		public readonly bool $refused = false,
		public readonly int $retryAfterSeconds = 0,
	) {
	}//end __construct()

	/**
	 * The wait in whole milliseconds, as the call log records it.
	 *
	 * @return int Milliseconds.
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
	 */
	public function waitMs(): int {
		return intdiv($this->waitMicros + 999, 1000);
	}//end waitMs()
}//end class
