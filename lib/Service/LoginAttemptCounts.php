<?php

/**
 * Login Attempt Counts
 *
 * How many failed login attempts the rate-limit window holds for one user
 * name and for one IP address.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The failed-attempt counters of a user name and an IP address.
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 */
final class LoginAttemptCounts {

	/**
	 * Constructor.
	 *
	 * @param mixed $user      The user name's counter, as the cache holds it.
	 * @param mixed $ipAddress The IP address's counter, as the cache holds it.
	 */
	public function __construct(
		public readonly mixed $user,
		public readonly mixed $ipAddress,
	) {

	}//end __construct()

	/**
	 * The counters as the security log names them.
	 *
	 * @return array{user_attempts: mixed, ip_attempts: mixed}
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function context(): array {
		return [
			'user_attempts' => $this->user,
			'ip_attempts' => $this->ipAddress,
		];

	}//end context()
}//end class
