<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef.
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

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * One task, by uuid, and the identity acting on it; null when the system acts.
 */
final class TaskActorRef {
	/**
	 * @param string $uuid The task uuid.
	 * @param string|null $actor The acting user id, or null for the system.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ?string $actor,
	) {
	}
}
