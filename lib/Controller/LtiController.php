<?php

/**
 * OpenConnector LtiController.
 *
 * Dedicated protocol controller for LTI 1.3 / LTI Advantage — OIDC
 * third-party-initiated login, launch validation, RFC 7523 service-token
 * issuance, AGS score/line-item, NRPS membership, and JWKS publish.
 *
 * Mirrors DSOController's dedicated-controller shape (not the generic
 * Endpoint pipeline) — see proposal.md's cited precedent.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use DateTime;
use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenConnector\Service\Lti\LtiAgsService;
use OCA\OpenConnector\Service\Lti\LtiKeyService;
use OCA\OpenConnector\Service\Lti\LtiLaunchService;
use OCA\OpenConnector\Service\Lti\LtiNrpsService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the LTI 1.3 / LTI Advantage protocol endpoints.
 *
 * Every route here is called by an external Platform/Tool, never by an NC
 * session — authentication is the protocol itself (signed id_token, RFC 7523
 * client assertion, or a previously-issued access token), which is why every
 * action carries `#[PublicPage]`/`#[NoCSRFRequired]` (hydra-gate-route-auth
 * is satisfied because the protocol check IS the auth body, matching the
 * DSOController precedent).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The current request.
	 * @param LtiLaunchService $launchService OIDC login + launch validation + Deep Linking.
	 * @param LtiAgsService $agsService Service-token issuance + AGS.
	 * @param LtiNrpsService $nrpsService NRPS roster read/pull.
	 * @param LtiKeyService $keyService Signing-key lifecycle + JWKS publish.
	 * @param LoggerInterface $logger Logger for protocol-level rejections.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LtiLaunchService $launchService,
		private readonly LtiAgsService $agsService,
		private readonly LtiNrpsService $nrpsService,
		private readonly LtiKeyService $keyService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Map an {@see LtiValidationException} to its declared HTTP status.
	 *
	 * @param LtiValidationException $exception The exception to render.
	 *
	 * @return JSONResponse
	 */
	private function renderRejection(LtiValidationException $exception): JSONResponse {
		$this->logger->info(
			'LtiController: request rejected',
			['message' => $exception->getMessage(), 'status' => $exception->getHttpStatus()]
		);

		return new JSONResponse(
			data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
			statusCode: $exception->getHttpStatus()
		);

	}//end renderRejection()

	/**
	 * Render the standard 401 for a missing/malformed Authorization header.
	 *
	 * @return JSONResponse
	 */
	private function renderMissingBearerToken(): JSONResponse {
		return $this->renderRejection(
			exception: new LtiValidationException(message: 'Missing bearer token', details: [], httpStatus: 401)
		);

	}//end renderMissingBearerToken()

	/**
	 * Extract a Bearer access token from the Authorization header.
	 *
	 * @return string|null The token value, or null when absent/malformed.
	 */
	private function extractBearerToken(): ?string {
		$header = $this->request->getHeader('Authorization');
		if (str_starts_with($header, 'Bearer ') === false) {
			return null;
		}

		$token = trim(substr($header, strlen('Bearer ')));
		if ($token === '') {
			return null;
		}

		return $token;
	}//end extractBearerToken()

	// =========================================================================
	// REQ-LTI-004 — OIDC third-party-initiated login (Tool role)
	// =========================================================================

	/**
	 * OIDC third-party-initiated login initiation.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082). LTI 1.3 (IMS Global). These URLs are
	 * registered with the platform out-of-band, so they cannot move (ADR-081)
	 * and the platform drives the call rate: a class of students launching at
	 * the start of a lesson is a legitimate burst. Ceilings only — the platform
	 * authenticates with its own signed JWT, so there is no guessable secret to
	 * count failures on.
	 *
	 * @param string $deployment The `lti_deployment` UUID route parameter.
	 *
	 * @return RedirectResponse|JSONResponse 302 to the platform on success; 400 on an unregistered issuer (no redirect, no nonce persisted).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function login(string $deployment): Response {
		$launchUrl = $this->request->getServerProtocol() . '://' . $this->request->getServerHost()
			. '/index.php/apps/openconnector/api/lti/' . $deployment . '/launch';

		try {
			$result = $this->launchService->initiateLogin(
				deploymentUuid: $deployment,
				params: $this->request->getParams(),
				launchUrl: $launchUrl
			);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		$response = new RedirectResponse($result['redirectUrl']);
		// SameSite=None; Secure — the login-initiation request and the
		// launch POST are cross-site by construction (design.md D5).
		$response->addCookie('oc_lti_state_' . $deployment, $result['state'], new DateTime('+10 minutes'), 'None');

		return $response;
	}//end login()

	/**
	 * Launch id_token validation and dispatch to the consuming app.
	 *
	 * @param string $deployment The `lti_deployment` UUID route parameter.
	 *
	 * @return RedirectResponse|JSONResponse 302 to `launchTargetUrl` on success; 400/401 on any validation failure.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function launch(string $deployment): Response {
		$idToken = (string)$this->request->getParam('id_token', '');
		$presentedState = $this->request->getParam('state');
		$cookieState = $this->request->getCookie('oc_lti_state_' . $deployment);

		if ($idToken === '') {
			return $this->renderRejection(
				exception: new LtiValidationException(message: 'Missing id_token', details: [], httpStatus: 400)
			);
		}

		try {
			$result = $this->launchService->validateLaunch(
				idToken: $idToken,
				deploymentUuid: $deployment,
				cookieState: $cookieState,
				presentedState: $presentedState
			);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		$response = new RedirectResponse($result['redirectUrl']);
		$response->invalidateCookie('oc_lti_state_' . $deployment);

		return $response;
	}//end launch()

	// =========================================================================
	// REQ-LTI-007 — service-token issuance (Platform role)
	// =========================================================================

	/**
	 * RFC 7523 JWT-bearer client-credentials token endpoint.
	 *
	 * Accepts `deployment_id` (this instance's `lti_deployment` UUID) as an
	 * additional form parameter beyond the RFC 7523 baseline — required
	 * because design.md D8 mandates the issued token be scoped to exactly
	 * one deployment, and the base RFC provides no deployment-selection
	 * mechanism of its own.
	 *
	 * @return JSONResponse The access token on success; 400/401/403 per the specific failure.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function token(): JSONResponse {
		$grantType = (string)$this->request->getParam('grant_type', '');
		$assertionType = (string)$this->request->getParam('client_assertion_type', '');
		$clientAssertion = (string)$this->request->getParam('client_assertion', '');
		$scope = (string)$this->request->getParam('scope', '');
		$deploymentId = (string)$this->request->getParam('deployment_id', '');

		if ($grantType !== 'client_credentials'
			|| $assertionType !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
			|| $clientAssertion === ''
			|| $deploymentId === ''
		) {
			return $this->renderRejection(
				exception: new LtiValidationException(message: 'Invalid token request', details: [], httpStatus: 400)
			);
		}

		try {
			$token = $this->agsService->issueAccessToken(
				clientAssertion: $clientAssertion,
				requestedScope: $scope,
				deploymentUuid: $deploymentId
			);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		return new JSONResponse(data: $token);
	}//end token()

	// =========================================================================
	// REQ-LTI-007 — AGS inbound (Platform role)
	// =========================================================================

	/**
	 * Inbound AGS score POST.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): AGS score posting — one call per learner
	 * per graded item, so a class being marked is a legitimate burst.
	 *
	 * @param string $deployment The `lti_deployment` UUID route parameter.
	 * @param string $lineItemId The AGS line item identifier.
	 *
	 * @return JSONResponse 200 on success; 401/403/400 per the specific failure.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function agsScore(string $deployment, string $lineItemId): JSONResponse {
		$token = $this->extractBearerToken();
		if ($token === null) {
			return $this->renderMissingBearerToken();
		}

		try {
			$result = $this->agsService->receiveScore(
				accessToken: $token,
				deploymentUuid: $deployment,
				lineItemId: $lineItemId,
				scorePayload: $this->request->getParams()
			);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		return new JSONResponse(data: $result);
	}//end agsScore()

	/**
	 * Inbound AGS line-item scope check (thin — line-item storage/CRUD is
	 * not modelled by this adapter; the consuming app owns line-item data
	 * via its own `gradeSink`). Enforces the `lineitem` scope + deployment
	 * binding and acknowledges the read.
	 *
	 * @param string $deployment The `lti_deployment` UUID route parameter.
	 * @param string $lineItemId The AGS line item identifier.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function agsLineItem(string $deployment, string $lineItemId): JSONResponse {
		$token = $this->extractBearerToken();
		if ($token === null) {
			return $this->renderMissingBearerToken();
		}

		try {
			$this->agsService->assertScopedToDeployment(
				accessToken: $token,
				deploymentUuid: $deployment,
				requiredScope: LtiAgsService::SCOPE_LINEITEM
			);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		return new JSONResponse(data: ['id' => $lineItemId, 'scoreMaximum' => null]);
	}//end agsLineItem()

	// =========================================================================
	// REQ-LTI-009 — NRPS inbound (Platform role)
	// =========================================================================

	/**
	 * Inbound NRPS membership request.
	 *
	 * @param string $deployment The `lti_deployment` UUID route parameter.
	 *
	 * @return JSONResponse The IMS NRPS membership container; 401/403/400 per the specific failure.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function nrpsMembership(string $deployment): JSONResponse {
		$token = $this->extractBearerToken();
		if ($token === null) {
			return $this->renderMissingBearerToken();
		}

		try {
			$result = $this->nrpsService->readRoster(accessToken: $token, deploymentUuid: $deployment);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		return new JSONResponse(data: $result);
	}//end nrpsMembership()

	// =========================================================================
	// REQ-LTI-002 — JWKS publish
	// =========================================================================

	/**
	 * Publish a registration's JWKS document.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): the JWKS is a PUBLISHED key set — platforms
	 * fetch it to verify our signatures and re-fetch on key rotation. Publishing
	 * it is the point, so the ceiling is the loosest here.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return JSONResponse A `{"keys": [...]}` JWKS document (active + previous public keys only).
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 480, period: 60)]
	public function jwks(string $registrationType, string $registrationUuid): JSONResponse {
		try {
			$jwks = $this->keyService->getPublishableJwks(registrationType: $registrationType, registrationUuid: $registrationUuid);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => 'Unknown registration type'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $jwks);
	}//end jwks()

	// =========================================================================
	// Phase 4 — tenant-wide key management (Beheer > Authenticatie)
	// =========================================================================

	/**
	 * Generate the first signing key for a registration. Admin-gated, CSRF-protected.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return JSONResponse The new (redacted) key entry.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
	public function generateKey(string $registrationType, string $registrationUuid): JSONResponse {
		try {
			$entry = $this->keyService->generateKey(registrationType: $registrationType, registrationUuid: $registrationUuid);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $entry);
	}//end generateKey()

	/**
	 * Rotate a registration's signing key. Admin-gated, CSRF-protected.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return JSONResponse The new (redacted) key entry.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
	public function rotateKey(string $registrationType, string $registrationUuid): JSONResponse {
		try {
			$entry = $this->keyService->rotateKey(registrationType: $registrationType, registrationUuid: $registrationUuid);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $entry);
	}//end rotateKey()

	// =========================================================================
	// REQ-LTI-008 — AGS outbound (Tool role)
	// =========================================================================

	/**
	 * Publish a score to a Platform's AGS line item, acting as Tool.
	 *
	 * REQ-LTI-008 requires this direction, and `LtiAgsService::publishScore()`
	 * has implemented it since the LTI work landed — but nothing could ever
	 * call it. Every `lti#*` route was the PLATFORM role (inbound: a Tool
	 * calls us), so the Tool-role outbound half of REQ-LTI-008 was
	 * structurally unreachable: fully implemented, unit-tested by calling the
	 * service directly, and spec'd "done", with zero production callers
	 * (openconnector#1192). This is the seam that makes it reachable, and it
	 * is the same shape REQ-LTI-010 describes — a consuming app drives the
	 * deployment through openconnector's API.
	 *
	 * Admin-gated + CSRF-protected, matching `generateKey`/`rotateKey`: this
	 * is an operator-driven outbound call that spends this instance's signing
	 * key against a remote Platform, not something an external party may
	 * trigger. It is deliberately NOT `#[PublicPage]` like the inbound routes
	 * above.
	 *
	 * @param string $deployment The `lti_deployment` UUID route parameter (must reference an `lti_platform`).
	 *
	 * @return JSONResponse The upstream AGS status, or a rejection.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
	 */
	#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
	public function agsPublishScore(string $deployment): JSONResponse {
		$params = $this->request->getParams();
		$lineItemUrl = (string)($params['lineItemUrl'] ?? '');
		if ($lineItemUrl === '') {
			return new JSONResponse(
				data: ['error' => 'lineItemUrl is required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$scorePayload = ($params['score'] ?? null);
		if (is_array($scorePayload) === false) {
			return new JSONResponse(
				data: ['error' => 'score must be an object'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->agsService->publishScore(
				deploymentUuid: $deployment,
				lineItemUrl: $lineItemUrl,
				scorePayload: $scorePayload
			);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		}

		return new JSONResponse(data: $result);
	}//end agsPublishScore()

	// =========================================================================
	// REQ-LTI-011 — registration trust gate (Beheer > Authenticatie)
	// =========================================================================

	/**
	 * Approve a registration. Admin-gated, CSRF-protected.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return JSONResponse `{registrationType, registrationUuid, status}`.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
	public function approve(string $registrationType, string $registrationUuid): JSONResponse {
		try {
			$result = $this->keyService->approve(registrationType: $registrationType, registrationUuid: $registrationUuid);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end approve()

	/**
	 * Suspend a registration. Admin-gated, CSRF-protected.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return JSONResponse `{registrationType, registrationUuid, status}`.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
	public function suspend(string $registrationType, string $registrationUuid): JSONResponse {
		try {
			$result = $this->keyService->suspend(registrationType: $registrationType, registrationUuid: $registrationUuid);
		} catch (LtiValidationException $exception) {
			return $this->renderRejection(exception: $exception);
		} catch (Throwable $exception) {
			return new JSONResponse(data: ['error' => $exception->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $result);
	}//end suspend()
}//end class
