<?php

/**
 * Contract of the LtiNrpsService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Exception\LtiValidationException;

/**
 * Public operations of {@see LtiNrpsService} that other components call.
 */
interface LtiNrpsServiceInterface {
	/**
	 * Serve an authorized NRPS membership request from the deployment's
	 * configured `rosterSource`, synchronously (no CloudEvent — a roster
	 * read has no useful async/retry semantics; the caller is blocked on the
	 * HTTP response).
	 *
	 * @param string $accessToken The bearer token value.
	 * @param string $deploymentUuid The `lti_deployment` the roster is requested for.
	 *
	 * @return array The IMS Names and Role Provisioning Service membership container.
	 *
	 * @throws LtiValidationException 401/403 on an invalid/unscoped token, 400 when `rosterSource` is unconfigured.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function readRoster(string $accessToken, string $deploymentUuid): array;

	/**
	 * Pull a roster from a Platform's NRPS endpoint (Tool role), reusing the
	 * same JWT-bearer client-credentials mechanism as AGS (REQ-LTI-008).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $membershipUrl The Platform's NRPS membership endpoint URL.
	 *
	 * @return array{statusCode: integer, body: mixed}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function pullRoster(string $deploymentUuid, string $membershipUrl): array;
}
