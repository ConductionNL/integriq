<?php

/**
 * Integriq Pacer Clock
 *
 * The time source and the sleep the source pacer waits with.
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
 * Wall-clock microseconds and a real sleep, kept in one class so a test can
 * replace both with a clock it moves itself.
 *
 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
 */
class PacerClock {

	/**
	 * The current time in microseconds since the epoch.
	 *
	 * @return int Microseconds.
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
	 */
	public function nowMicros(): int {
		return (int)round(microtime(true) * 1000000);
	}//end nowMicros()

	/**
	 * Wait the given number of microseconds.
	 *
	 * @param int $micros How long to wait; nothing happens for zero or less.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-outbound-rate-limit-pacing/specs/http-call-engine/spec.md#requirement-calls-to-a-source-are-spaced-to-stay-under-its-limit-req-rlpc-001
	 */
	public function sleepMicros(int $micros): void {
		if ($micros > 0) {
			usleep($micros);
		}
	}//end sleepMicros()
}//end class
