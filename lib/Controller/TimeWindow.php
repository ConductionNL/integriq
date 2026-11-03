<?php

/**
 * Integriq TimeWindow.
 *
 * An optional [from, to] window that list endpoints filter timestamps by.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * A window with an optional lower and upper bound (ISO 8601). Without either
 * bound every timestamp, even a missing one, falls inside it.
 *
 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-3
 */
final class TimeWindow {

	/**
	 * Constructor.
	 *
	 * @param string|null $from Lower bound, or null/'' for none.
	 * @param string|null $to   Upper bound, or null/'' for none.
	 */
	public function __construct(
		private readonly ?string $from,
		private readonly ?string $to,
	) {
	}//end __construct()

	/**
	 * Whether a timestamp falls within the window.
	 *
	 * @param string|null $timestamp The timestamp to test.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/openconnector-dead-letter-replay/tasks.md#task-3
	 */
	public function contains(?string $timestamp): bool {
		if (self::isBlank(value: $this->from) === true && self::isBlank(value: $this->to) === true) {
			return true;
		}

		if (self::isBlank(value: $timestamp) === true) {
			return false;
		}

		$time = strtotime(datetime: (string)$timestamp);
		if ($time === false) {
			return false;
		}

		return $this->notBefore(time: $time) && $this->notAfter(time: $time);
	}//end contains()

	/**
	 * Whether a time is not before the lower bound (true without one).
	 *
	 * @param integer $time The unix time.
	 *
	 * @return boolean
	 */
	private function notBefore(int $time): bool {
		return self::isBlank(value: $this->from) === true || $time >= strtotime(datetime: (string)$this->from);
	}//end notBefore()

	/**
	 * Whether a time is not after the upper bound (true without one).
	 *
	 * @param integer $time The unix time.
	 *
	 * @return boolean
	 */
	private function notAfter(int $time): bool {
		return self::isBlank(value: $this->to) === true || $time <= strtotime(datetime: (string)$this->to);
	}//end notAfter()

	/**
	 * Whether a value is null or the empty string.
	 *
	 * @param string|null $value The value.
	 *
	 * @return boolean
	 */
	private static function isBlank(?string $value): bool {
		return $value === null || $value === '';
	}//end isBlank()
}//end class
