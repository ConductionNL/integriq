<?php

/**
 * Integriq LtiPlatformLoginService.
 *
 * The platform half of an LTI 1.3 launch: the third-party-initiated login
 * a sibling app starts, and the authorization step the tool calls back.
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
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\Exception\LtiValidationException;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Runs a platform launch the way LTI 1.3 defines it (REQ-LTI-006, REQ-LTIL-001/002).
 *
 * 1. {@see initiateLogin()} answers a {@see LtiLaunchRequestedEvent} with a
 *    form to the tool's OIDC login URL carrying `iss`, `login_hint`,
 *    `lti_message_hint`, `target_link_uri`, `client_id` and
 *    `lti_deployment_id`. No id_token is minted at this step.
 * 2. The tool redirects the browser to the platform authorization endpoint
 *    with its own `state` and `nonce`. {@see authorizeLaunch()} checks the
 *    signed-in user against the hint, the hint, the client id, the redirect
 *    URI and the presence of nonce and state, and only then signs an
 *    id_token carrying the tool's nonce, for the controller to post to the
 *    tool's redirect URI together with the tool's state.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */
class LtiPlatformLoginService {

	/**
	 * The message type this launch serves. Deep linking goes through the
	 * same flow later; its settings claim and response handling are not
	 * built yet, so it is refused rather than half-served.
	 *
	 * @var string
	 */
	public const MESSAGE_TYPE_RESOURCE_LINK = 'LtiResourceLinkRequest';

	/**
	 * Event role to LIS membership role (LTI 1.3 roles claim).
	 *
	 * @var array<string, string>
	 */
	public const LIS_ROLES = [
		'Learner' => 'http://purl.imsglobal.org/vocab/lis/v2/membership#Learner',
		'Instructor' => 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor',
	];

	/**
	 * LTI 1.3 claim URIs this launch adds on top of {@see LtiLaunchService::initiatePlatformLaunch()}.
	 *
	 * @var string
	 */
	public const CLAIM_ROLES = 'https://purl.imsglobal.org/spec/lti/claim/roles';
	public const CLAIM_TARGET_LINK_URI = 'https://purl.imsglobal.org/spec/lti/claim/target_link_uri';
	public const CLAIM_CONTEXT = 'https://purl.imsglobal.org/spec/lti/claim/context';
	public const CLAIM_LAUNCH_PRESENTATION = 'https://purl.imsglobal.org/spec/lti/claim/launch_presentation';
	public const CLAIM_AGS_ENDPOINT = 'https://purl.imsglobal.org/spec/lti-ags/claim/endpoint';

	/**
	 * The route of a line item on a deployment ({@see LtiController::agsLineItem()}).
	 * The score route is the same URL plus `/scores` (AGS 2.0).
	 *
	 * @var string
	 */
	public const LINE_ITEM_ROUTE = 'integriq.lti.agsLineItem';

	/**
	 * Constructor.
	 *
	 * @param LtiRegistrationResolverService $resolver Deployment and tool lookups (approval-gated).
	 * @param LtiLaunchService $launchService Signs the id_token with the tool registration's active key.
	 * @param LtiPlatformHint $hint Issues and verifies the signed login hint.
	 * @param IUserSession $userSession The signed-in user.
	 * @param IURLGenerator $urlGenerator Derives this platform's issuer.
	 */
	public function __construct(
		private readonly LtiRegistrationResolverService $resolver,
		private readonly LtiLaunchService $launchService,
		private readonly LtiPlatformHint $hint,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * This platform's issuer: the instance's absolute base URL.
	 *
	 * @return string The `iss` value a tool registers for this platform.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function platformIssuer(): string {
		return rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');
	}//end platformIssuer()

	/**
	 * Answer a launch request with a login initiation form, or refuse it.
	 *
	 * @param LtiLaunchRequestedEvent $event The request; its result slot is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function initiateLogin(LtiLaunchRequestedEvent $event): void {
		$refusal = $this->refusalFor(event: $event);
		if ($refusal !== null) {
			$event->refuse(code: $refusal['code'], reason: $refusal['reason']);
			return;
		}

		$tool = $this->resolveApprovedTool(deploymentUuid: $event->getDeploymentUuid(), event: $event);
		if ($tool === null) {
			return;
		}

		[$deploymentData, $toolData] = $tool;
		$loginUrl = (string)($toolData['oidcLoginUrl'] ?? '');
		$clientId = (string)($toolData['clientId'] ?? '');
		$launchUrl = (string)($toolData['launchUrl'] ?? '');
		if ($loginUrl === '' || $clientId === '' || $launchUrl === '') {
			$event->refuse(
				code: 'tool-incomplete',
				reason: 'The tool registration has no OIDC login URL, client id or launch URL'
			);
			return;
		}

		$hint = $this->hint->issue(
			context: [
				'userId' => $event->getUserId(),
				'placementId' => $event->getPlacementId(),
				'deploymentUuid' => $event->getDeploymentUuid(),
				'messageType' => $event->getMessageType(),
				'role' => $event->getRole(),
				'contextId' => $event->getContextId(),
				'contextTitle' => $event->getContextTitle(),
				'returnUrl' => $event->getReturnUrl(),
			]
		);

		$event->setLoginInitiation(
			loginInitiation: [
				'formActionUrl' => $loginUrl,
				'method' => 'POST',
				'fields' => [
					'iss' => $this->platformIssuer(),
					'login_hint' => $hint,
					'lti_message_hint' => $hint,
					'target_link_uri' => $launchUrl,
					'client_id' => $clientId,
					'lti_deployment_id' => (string)($deploymentData['deploymentId'] ?? ''),
				],
			]
		);
	}//end initiateLogin()

	/**
	 * The refusal for a request that fails before any lookup, or null.
	 *
	 * @param LtiLaunchRequestedEvent $event The request.
	 *
	 * @return array{code: string, reason: string}|null The refusal.
	 */
	private function refusalFor(LtiLaunchRequestedEvent $event): ?array {
		$sessionUid = $this->userSession->getUser()?->getUID();
		if ($sessionUid === null || $sessionUid !== $event->getUserId()) {
			return ['code' => 'user-mismatch', 'reason' => 'The launch is not for the signed-in user'];
		}

		if ($event->getMessageType() !== self::MESSAGE_TYPE_RESOURCE_LINK) {
			return [
				'code' => 'message-type-unsupported',
				'reason' => 'Only ' . self::MESSAGE_TYPE_RESOURCE_LINK . ' launches are served; got ' . $event->getMessageType(),
			];
		}

		if (isset(self::LIS_ROLES[$event->getRole()]) === false) {
			return ['code' => 'role-unknown', 'reason' => 'The role must be Learner or Instructor; got ' . $event->getRole()];
		}

		return null;
	}//end refusalFor()

	/**
	 * Resolve the deployment and its approved tool, refusing on the event when either fails.
	 *
	 * @param string $deploymentUuid The `lti_deployment` uuid.
	 * @param LtiLaunchRequestedEvent $event The request, refused in place on failure.
	 *
	 * @return array{0: array, 1: array}|null The deployment and tool data, or null when refused.
	 */
	private function resolveApprovedTool(string $deploymentUuid, LtiLaunchRequestedEvent $event): ?array {
		try {
			$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		} catch (LtiValidationException $exception) {
			$event->refuse(code: 'deployment-invalid', reason: $exception->getMessage());
			return null;
		}

		$toolUuid = (string)($deployment?->getObject()['ltiToolId'] ?? '');
		if ($deployment === null || $toolUuid === '') {
			$event->refuse(code: 'deployment-unknown', reason: 'No LTI tool deployment ' . $deploymentUuid . ' exists');
			return null;
		}

		$tool = $this->resolver->findRegistrationByUuid(registrationType: 'lti_tool', registrationUuid: $toolUuid);
		if ($tool === null) {
			$status = $this->resolver->findRegistrationStatus(registrationType: 'lti_tool', registrationUuid: $toolUuid);
			if ($status === null) {
				$event->refuse(code: 'tool-unknown', reason: 'The deployment names a tool that is not registered');
				return null;
			}

			$event->refuse(code: 'tool-not-approved', reason: 'The tool registration is ' . $status . ', not approved');
			return null;
		}

		return [$deployment->getObject(), $tool->getObject()];
	}//end resolveApprovedTool()

	/**
	 * Authorize the tool's login redirect and sign the launch id_token.
	 *
	 * Checks, in order (design.md D3): the signed-in user is the hint's user;
	 * the hint's signature and expiry; `client_id` is the approved tool the
	 * hint's deployment names; `redirect_uri` is registered for that tool;
	 * `nonce` and `state` are present. Any failure throws, naming the check,
	 * and nothing is signed.
	 *
	 * @param array<string, mixed> $params The authorization request parameters (GET or POST).
	 * @param string|null $sessionUid The signed-in user's uid, or null.
	 *
	 * @return array{redirectUri: string, idToken: string, state: string} What the controller posts to the tool.
	 *
	 * @throws LtiValidationException Naming the failed check in `details.check`.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
	 */
	public function authorizeLaunch(array $params, ?string $sessionUid): array {
		$hintText = (string)($params['lti_message_hint'] ?? ($params['login_hint'] ?? ''));
		if ($hintText === '') {
			throw self::failure(check: 'hint', message: 'The request carries no login hint', status: 400);
		}

		// Check 1: the hint's user is the browser's user.
		$sessionUid = $this->requireHintUser(hintText: $hintText, sessionUid: $sessionUid);

		// Check 2: signature and expiry.
		$context = $this->hint->verify(hint: $hintText);

		// Check 3: client_id names the approved tool of the hint's deployment.
		$deploymentUuid = (string)($context['deploymentUuid'] ?? '');
		$toolData = $this->approvedToolData(deploymentUuid: $deploymentUuid);
		$clientId = (string)($params['client_id'] ?? '');
		if ($toolData === null || $clientId === '' || $clientId !== (string)($toolData['clientId'] ?? '')) {
			throw self::failure(check: 'client_id', message: 'The client id does not name the approved tool of this launch', status: 400);
		}

		// Check 4: redirect_uri is registered for the tool.
		$redirectUri = (string)($params['redirect_uri'] ?? '');
		if ($this->isRegisteredRedirectUri(toolData: $toolData, redirectUri: $redirectUri) === false) {
			throw self::failure(check: 'redirect_uri', message: 'The redirect URI is not registered for this tool', status: 400);
		}

		// Check 5: nonce and state are present.
		$nonce = self::requireParam(params: $params, name: 'nonce');
		$state = self::requireParam(params: $params, name: 'state');

		$launch = $this->launchService->initiatePlatformLaunch(
			deploymentUuid: $deploymentUuid,
			platformIssuer: $this->platformIssuer(),
			subject: $sessionUid,
			messageType: (string)($context['messageType'] ?? self::MESSAGE_TYPE_RESOURCE_LINK),
			extraClaims: $this->launchClaims(context: $context, toolData: $toolData),
			nonce: $nonce
		);

		return ['redirectUri' => $redirectUri, 'idToken' => $launch['idToken'], 'state' => $state];
	}//end authorizeLaunch()

	/**
	 * Require that the hint was issued for the signed-in user.
	 *
	 * Read before the signature check so a mismatch is named as such;
	 * nothing read from the hint here is trusted until it has been verified.
	 *
	 * @param string $hintText The hint.
	 * @param string|null $sessionUid The signed-in user's uid, or null.
	 *
	 * @return string The signed-in user's uid.
	 *
	 * @throws LtiValidationException Naming the `user` check.
	 */
	private function requireHintUser(string $hintText, ?string $sessionUid): string {
		$claimedUser = (string)($this->hint->peek(hint: $hintText)['userId'] ?? '');
		if ($sessionUid === null || $claimedUser === '' || $claimedUser !== $sessionUid) {
			throw self::failure(check: 'user', message: 'This launch was started for another user', status: 403);
		}

		return $sessionUid;
	}//end requireHintUser()

	/**
	 * Require a non-empty request parameter.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string $name The parameter, also the name of the check.
	 *
	 * @return string The value.
	 *
	 * @throws LtiValidationException Naming the parameter as the failed check.
	 */
	private static function requireParam(array $params, string $name): string {
		$value = (string)($params[$name] ?? '');
		if ($value === '') {
			throw self::failure(check: $name, message: 'The request carries no ' . $name, status: 400);
		}

		return $value;
	}//end requireParam()

	/**
	 * The approved tool a deployment names, or null.
	 *
	 * @param string $deploymentUuid The `lti_deployment` uuid.
	 *
	 * @return array|null The tool's data.
	 */
	private function approvedToolData(string $deploymentUuid): ?array {
		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		$toolUuid = (string)($deployment?->getObject()['ltiToolId'] ?? '');
		if ($toolUuid === '') {
			return null;
		}

		$tool = $this->resolver->findRegistrationByUuid(registrationType: 'lti_tool', registrationUuid: $toolUuid);

		return $tool?->getObject();
	}//end approvedToolData()

	/**
	 * Whether a redirect URI is registered for a tool.
	 *
	 * The tool's `redirectUris` list is authoritative when it has entries;
	 * an empty or absent list allows only the tool's `launchUrl` (design.md
	 * D5), so a tool registered before the list existed keeps working.
	 * Comparison is exact: no prefix, no normalisation.
	 *
	 * @param array $toolData The tool registration's data.
	 * @param string $redirectUri The requested redirect URI.
	 *
	 * @return bool
	 */
	private function isRegisteredRedirectUri(array $toolData, string $redirectUri): bool {
		if ($redirectUri === '') {
			return false;
		}

		$registered = array_values(array_filter((array)($toolData['redirectUris'] ?? []), 'is_string'));
		if ($registered === []) {
			$registered = [(string)($toolData['launchUrl'] ?? '')];
		}

		return in_array($redirectUri, $registered, true);
	}//end isRegisteredRedirectUri()

	/**
	 * The resource link launch claims (LTI 1.3 core) for a verified hint.
	 *
	 * @param array $context The verified hint payload.
	 * @param array $toolData The tool registration's data.
	 *
	 * @return array<string, mixed> Claims merged into the id_token.
	 */
	private function launchClaims(array $context, array $toolData): array {
		$claims = [
			LtiLaunchService::CLAIM_RESOURCE_LINK => ['id' => (string)($context['placementId'] ?? '')],
			self::CLAIM_ROLES => [self::LIS_ROLES[(string)($context['role'] ?? 'Learner')] ?? self::LIS_ROLES['Learner']],
			self::CLAIM_TARGET_LINK_URI => (string)($toolData['launchUrl'] ?? ''),
		];

		if ((string)($context['contextId'] ?? '') !== '') {
			$claims[self::CLAIM_CONTEXT] = ['id' => (string)$context['contextId'], 'title' => (string)($context['contextTitle'] ?? '')];
		}

		if ((string)($context['returnUrl'] ?? '') !== '') {
			$claims[self::CLAIM_LAUNCH_PRESENTATION] = ['return_url' => (string)$context['returnUrl']];
		}

		$agsEndpoint = $this->agsEndpointClaim(context: $context);
		if ($agsEndpoint !== null) {
			$claims[self::CLAIM_AGS_ENDPOINT] = $agsEndpoint;
		}

		return $claims;
	}//end launchClaims()

	/**
	 * The grade service claim (LTI AGS 2.0) for a launch from a placement.
	 *
	 * The placement is the line item: `lineitem` is this deployment's line item
	 * route with the placement id, so a score the tool posts to `lineitem/scores`
	 * reaches the score CloudEvent with the placement as `lineItemId`. `lineitems`
	 * is left out because the platform offers no line item container; the scopes
	 * are the ones a tool needs to read that line item and post scores to it.
	 *
	 * @param array $context The verified hint payload.
	 *
	 * @return array{scope: array<int, string>, lineitem: string}|null The claim, or null without a placement.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-launched-tool-can-send-a-grade-back-to-the-placement-req-ltil-003
	 */
	private function agsEndpointClaim(array $context): ?array {
		$placementId = (string)($context['placementId'] ?? '');
		$deploymentUuid = (string)($context['deploymentUuid'] ?? '');
		if ($placementId === '' || $deploymentUuid === '') {
			return null;
		}

		return [
			'scope' => [LtiAgsService::SCOPE_LINEITEM_READONLY, LtiAgsService::SCOPE_SCORE],
			'lineitem' => $this->urlGenerator->linkToRouteAbsolute(
				self::LINE_ITEM_ROUTE,
				['deployment' => $deploymentUuid, 'lineItemId' => $placementId]
			),
		];
	}//end agsEndpointClaim()

	/**
	 * Build the validation failure for a named check.
	 *
	 * @param string $check The check that failed.
	 * @param string $message What the user reads.
	 * @param int $status The HTTP status.
	 *
	 * @return LtiValidationException
	 */
	private static function failure(string $check, string $message, int $status): LtiValidationException {
		return new LtiValidationException(message: $message, details: ['check' => $check], httpStatus: $status);
	}//end failure()
}//end class
