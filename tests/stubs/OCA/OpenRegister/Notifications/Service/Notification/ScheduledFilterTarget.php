<?php

/**
 * Stub for OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterTarget.
 *
 * Same constructor as the OpenRegister value object, so a test builds and
 * reads it the way production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;

/**
 * One object's flat field map and the scan's logical "now".
 */
final class ScheduledFilterTarget {
	/**
	 * @param array $objectData The object's flat field map.
	 * @param DateTimeImmutable $now The scan's logical "now".
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly DateTimeImmutable $now,
	) {
	}
}
