<?php

/**
 * Login Attempt
 *
 * The user name and IP address of one login attempt, sanitised for use in a
 * cache key, as SecurityService rate-limits them.
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
 * Who attempted a login, and from where.
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 */
final class LoginAttempt {

	/**
	 * Constructor.
	 *
	 * @param string $username  The sanitised user name.
	 * @param string $ipAddress The sanitised IP address.
	 */
	public function __construct(
		public readonly string $username,
		public readonly string $ipAddress,
	) {

	}//end __construct()

	/**
	 * The attempt as the security log names it.
	 *
	 * @return array{username: string, ip_address: string}
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function context(): array {
		return [
			'username' => $this->username,
			'ip_address' => $this->ipAddress,
		];

	}//end context()
}//end class
