<?php

/**
 * Integriq OAuthError
 *
 * An OAuth 2.0 error answer (RFC 6749 section 5.2): its code, description and HTTP status.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OAuth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OAuth;

use RuntimeException;

/**
 * A refused token request.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
class OAuthError extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $error       The RFC 6749 error code.
	 * @param string $description What went wrong.
	 * @param int    $status      The HTTP status.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function __construct(
		public readonly string $error,
		public readonly string $description,
		public readonly int $status,
	) {
		parent::__construct(message: $description);
	}//end __construct()

	/**
	 * The answer body.
	 *
	 * @return array{error: string, error_description: string}
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function body(): array {
		return ['error' => $this->error, 'error_description' => $this->description];
	}//end body()
}//end class
