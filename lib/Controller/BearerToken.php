<?php

/**
 * Integriq BearerToken.
 *
 * Reads the bearer token a wallet or an LTI tool presents in the
 * Authorization header: `Bearer ` (exactly that case), then the trimmed
 * token, or none when the scheme differs or nothing follows it.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCP\IRequest;

/**
 * The bearer token of a request.
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
 * @spec openspec/specs/lti-platform/spec.md
 */
final class BearerToken {

	/**
	 * Extract a Bearer access token from the Authorization header.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string|null The token value, or null when absent/malformed.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public static function fromRequest(IRequest $request): ?string {
		$header = $request->getHeader('Authorization');
		if (str_starts_with($header, 'Bearer ') === false) {
			return null;
		}

		$token = trim(substr($header, strlen('Bearer ')));
		if ($token === '') {
			return null;
		}

		return $token;
	}//end fromRequest()
}//end class
