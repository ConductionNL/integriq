<?php

/**
 * Integriq AgsTokenRequest.
 *
 * One OAuth 2.0 client-credentials request for an AGS access token: the
 * tool's signed client assertion, the space-separated scopes it asks for, and
 * the deployment it names (null when it names none). Built by
 * LtiAgsService::issueAccessToken() and never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

/**
 * A token request.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
final class AgsTokenRequest {

	/**
	 * The deployment the request names; null or empty when it names none.
	 *
	 * @var string|null
	 */
	public ?string $deploymentUuid = null;

	/**
	 * Constructor.
	 *
	 * @param string $clientAssertion The tool's signed JWT client assertion.
	 * @param string $requestedScope The space-separated scopes asked for.
	 */
	public function __construct(
		public readonly string $clientAssertion,
		public readonly string $requestedScope,
	) {
	}//end __construct()
}//end class
