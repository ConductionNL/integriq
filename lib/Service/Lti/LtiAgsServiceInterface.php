<?php

/**
 * Contract of the LtiAgsService.
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
 * Public operations of {@see LtiAgsService} that other components call.
 */
interface LtiAgsServiceInterface {
	/**
	 * AGS line-item scope URIs (1EdTech LTI Advantage AGS 2.0).
	 *
	 * @var string
	 */
	public const SCOPE_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
	public const SCOPE_LINEITEM_READONLY = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem.readonly';

	/**
	 * RFC 7523 JWT-bearer client-credentials grant: exchange a signed
	 * `client_assertion` for a deployment-scoped access token.
	 *
	 * A conformant LTI Advantage token request carries no deployment (1EdTech
	 * Security Framework 4.1): the tool is named by the assertion's `iss`/`sub`.
	 * The token is still scoped to exactly one deployment (design.md D8,
	 * REQ-LTI-007): the asserting tool's only deployment, or, for a tool with
	 * several, the one the caller names in the optional `deployment_id`.
	 *
	 * @param string      $clientAssertion The `client_assertion` JWT posted to `POST /api/lti/token`.
	 * @param string      $requestedScope  Space-separated requested scopes.
	 * @param string|null $deploymentUuid  The `lti_deployment` to scope to; optional when the tool has one.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: integer, scope: string}
	 *
	 * @throws LtiValidationException On any assertion/deployment/scope failure (no cross-deployment token — design.md D8).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-launched-tool-can-send-a-grade-back-to-the-placement-req-ltil-003
	 */
	public function issueAccessToken(string $clientAssertion, string $requestedScope, ?string $deploymentUuid = null): array;

	/**
	 * Resolve an issued access token to its bound deployment + granted scopes.
	 *
	 * @param string $accessToken The bearer token value.
	 *
	 * @return array{deploymentUuid: string, toolUuid: string, scopes: string[]}|null Null when unknown/expired.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function resolveAccessToken(string $accessToken): ?array;

	/**
	 * Enforce that a token is valid, carries one of the accepted scopes, and
	 * is bound to the given deployment (route-layer enforcement, REQ-LTI-007
	 * scenario: cross-deployment access rejected 403).
	 *
	 * @param string                    $accessToken    The bearer token value.
	 * @param string                    $deploymentUuid The deployment the calling route is scoped to.
	 * @param string|array<int, string> $requiredScope  The scope the endpoint requires, or the scopes any one of which suffices.
	 *
	 * @return array The resolved token data.
	 *
	 * @throws LtiValidationException 401 on an invalid/unknown token, 403 on a scope/deployment mismatch.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function assertScopedToDeployment(string $accessToken, string $deploymentUuid, string|array $requiredScope): array;

	/**
	 * Handle an inbound AGS score POST: enforce the `score` scope + deployment
	 * binding, then publish the received score as a CloudEvent (never a
	 * direct write to `lti_deployment.gradeSink` — REQ-LTI-007).
	 *
	 * @param string $accessToken The bearer token value.
	 * @param string $deploymentUuid The deployment the score was posted under.
	 * @param string $lineItemId The AGS line item identifier.
	 * @param array $scorePayload The IMS AGS score payload.
	 *
	 * @return array{messagesCreated: integer}
	 *
	 * @throws LtiValidationException On an invalid token/scope/deployment mismatch.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function receiveScore(string $accessToken, string $deploymentUuid, string $lineItemId, array $scorePayload): array;

	/**
	 * Publish a score to a Platform's AGS line item (Tool role).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $lineItemUrl The line item's AGS endpoint (`.../lineitems/{id}`).
	 * @param array $scorePayload The IMS AGS score payload to POST to `{lineItemUrl}/scores`.
	 *
	 * @return array{statusCode: integer}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails (never silently dropped — REQ-LTI-008 scenario 2).
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
	 */
	public function publishScore(string $deploymentUuid, string $lineItemUrl, array $scorePayload): array;

	/**
	 * Read a result from a Platform's AGS line item (Tool role).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $lineItemUrl The line item's AGS endpoint.
	 *
	 * @return array{statusCode: integer, body: mixed}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
	 */
	public function readResult(string $deploymentUuid, string $lineItemUrl): array;

	/**
	 * Generic Tool-role outbound GET pull against a Platform-hosted resource,
	 * reusing the RFC 7523 JWT-bearer client-credentials grant scoped to the
	 * given AGS/NRPS scope. Shared by {@see readResult()} (AGS) and
	 * {@see \OCA\Integriq\Service\Lti\LtiNrpsService::pullRoster()}
	 * (NRPS) so the token-exchange + dispatch shape is not duplicated
	 * (REQ-LTI-008/REQ-LTI-009 both reuse the same outbound mechanism).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $url The absolute Platform-hosted resource URL to GET.
	 * @param string $scope The AGS/NRPS scope to request.
	 *
	 * @return array{statusCode: integer, body: mixed}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function pullResourceForDeployment(string $deploymentUuid, string $url, string $scope): array;
}
