<?php

/**
 * Integriq CORS origin policy for the user API.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCP\IConfig;

/**
 * Decides which request Origin the user API grants CORS access.
 *
 * Kept apart from UserController so the controller stays within the
 * class-complexity limit of the PHPMD gate.
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 */
class UserApiOriginPolicy {

	/**
	 * Constructor.
	 *
	 * @param IConfig $config The configuration, for the `user_api_allowed_origins` app setting.
	 */
	public function __construct(
		private readonly IConfig $config,
	) {

	}//end __construct()

	/**
	 * The given Origin when the administrator allow-listed it, null otherwise.
	 *
	 * The user API responses carry `Access-Control-Allow-Credentials: true`, so
	 * the origin they grant can read the signed-in user's profile and drive the
	 * login with the browser's cookies. Reflecting whatever Origin arrived gave
	 * that to every site. Only origins listed (comma or whitespace separated, as
	 * `scheme://host[:port]`) in the app setting `user_api_allowed_origins` are
	 * granted; the list is empty by default, which grants none.
	 *
	 * @param string $origin The request's Origin header.
	 *
	 * @return string|null The origin to grant, or null for no CORS grant.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function grantFor(string $origin): ?string {
		$origin = trim($origin);
		if ($origin === '') {
			return null;
		}

		$configured = $this->config->getAppValue('integriq', 'user_api_allowed_origins', '');
		$allowed = preg_split('/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY);
		if ($allowed === false) {
			return null;
		}

		foreach ($allowed as $candidate) {
			if (strcasecmp(rtrim($candidate, '/'), $origin) === 0) {
				return $origin;
			}
		}

		return null;
	}//end grantFor()
}//end class
