<?php

/**
 * Integriq BasicAuthCredentials: the user a Basic Authorization header
 * authenticates.
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
 * @spec openspec/specs/user-management-and-login/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Reads a request's Basic Authorization header and checks its password;
 * built by {@see UserController} for the `/me` fallback.
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 */
final class BasicAuthCredentials {
	/**
	 * Constructor.
	 *
	 * @param IRequest     $request The request carrying the header.
	 * @param IUserManager $users   Checks the password.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IUserManager $users,
	) {

	}//end __construct()

	/**
	 * The user a Basic Authorization header authenticates, or null.
	 *
	 * @return IUser|null The user, or null without valid Basic credentials.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function user(): ?IUser {
		$credentials = $this->credentials();
		if ($credentials === null) {
			return null;
		}

		[$username, $password] = explode(':', $credentials, 2);
		$checked = $this->users->checkPassword($username, $password);
		if ($checked === false) {
			return null;
		}

		return $checked;

	}//end user()

	/**
	 * The decoded `user:password` of a Basic Authorization header, or null
	 * when the header is absent, not Basic, not decodable or has no colon.
	 *
	 * @return string|null The credentials.
	 */
	private function credentials(): ?string {
		$payload = $this->payload();
		if ($payload === null) {
			return null;
		}

		$credentials = base64_decode($payload);
		if ($credentials === false || str_contains($credentials, ':') === false) {
			return null;
		}

		return $credentials;

	}//end credentials()

	/**
	 * The encoded part of a Basic Authorization header, or null when the
	 * header is absent or not Basic.
	 *
	 * @return string|null The payload after `Basic `.
	 */
	private function payload(): ?string {
		$authHeader = $this->request->getHeader('Authorization');
		if ($authHeader === '' || str_starts_with($authHeader, 'Basic ') === false) {
			return null;
		}

		return substr($authHeader, 6);

	}//end payload()
}//end class
