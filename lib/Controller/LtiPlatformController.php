<?php

/**
 * Integriq LtiPlatformController.
 *
 * The platform authorization endpoint of an LTI 1.3 launch.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Answers a tool's authorization redirect with an auto-posting launch form.
 *
 * The tool redirects the learner's browser here after the login initiation
 * a sibling app started (REQ-LTIL-001). The endpoint needs the learner's
 * Nextcloud session, because the first check is that the signed-in user is
 * the user the launch was started for; that is also the per-object guard,
 * since a hint names exactly one user, one placement and one deployment.
 * CSRF is off because the request comes from the tool, cross-site, by
 * design; the signed hint and the tool's own state carry the integrity.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
 */
class LtiPlatformController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The current request.
	 * @param LtiPlatformLoginService $loginService Runs the authorization checks and signs the id_token.
	 * @param IUserSession $userSession The signed-in user.
	 * @param IL10N $l10n Translator for the error page.
	 * @param LoggerInterface $logger Logger for refused authorizations (never logs tokens).
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LtiPlatformLoginService $loginService,
		private readonly IUserSession $userSession,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * The platform authorization endpoint (`GET` and `POST /api/lti/platform/authorize`).
	 *
	 * On success the answer is a page that posts `id_token` and `state` to the
	 * tool's registered redirect URI. On any failure it is an error page naming
	 * the failed check, and nothing is posted.
	 *
	 * @return TemplateResponse The auto-post form, or the error page.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function authorize(): TemplateResponse {
		$sessionUid = $this->userSession->getUser()?->getUID();

		try {
			$launch = $this->loginService->authorizeLaunch(params: $this->request->getParams(), sessionUid: $sessionUid);
		} catch (LtiValidationException $exception) {
			$check = (string)($exception->getDetails()['check'] ?? 'launch');
			$this->logger->info('LtiPlatformController: authorization refused', ['check' => $check]);

			return $this->errorPage(check: $check, status: $exception->getHttpStatus());
		}

		$response = new TemplateResponse(
			appName: $this->appName,
			templateName: 'lti-autopost',
			params: [
				'redirectUri' => $launch['redirectUri'],
				'idToken' => $launch['idToken'],
				'state' => $launch['state'],
				'continueLabel' => $this->l10n->t('Continue to the tool'),
			],
			renderAs: TemplateResponse::RENDER_AS_BLANK
		);

		// Nextcloud's default policy only lets a form post to its own origin.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFormActionDomain(domain: $this->originOf(url: $launch['redirectUri']));
		$response->setContentSecurityPolicy(csp: $policy);

		return $response;
	}//end authorize()

	/**
	 * The error page for a failed check.
	 *
	 * @param string $check The failed check.
	 * @param int $status The HTTP status.
	 *
	 * @return TemplateResponse
	 */
	private function errorPage(string $check, int $status): TemplateResponse {
		$messages = [
			'user' => $this->l10n->t('This launch was started for another user. Sign in as that user, or start the launch again.'),
			'hint' => $this->l10n->t('The launch request is not valid. Start the launch again from the lesson.'),
			'hint-expired' => $this->l10n->t('The launch took too long and has expired. Start the launch again from the lesson.'),
			'client_id' => $this->l10n->t('The tool that answered is not the approved tool of this launch.'),
			'redirect_uri' => $this->l10n->t('The tool asked to return to an address that is not registered for it.'),
			'nonce' => $this->l10n->t('The tool sent no nonce, so the launch cannot be completed.'),
			'state' => $this->l10n->t('The tool sent no state, so the launch cannot be completed.'),
		];

		$response = new TemplateResponse(
			appName: $this->appName,
			templateName: 'lti-error',
			params: [
				'title' => $this->l10n->t('The tool could not be opened'),
				'message' => ($messages[$check] ?? $this->l10n->t('The launch could not be completed.')),
				'checkLabel' => $this->l10n->t('Failed check: %s', [$check]),
				'hint' => $this->l10n->t('If the tool opens inside the page, try opening it in a new tab.'),
			],
			renderAs: TemplateResponse::RENDER_AS_GUEST
		);
		if ($status < 400) {
			$status = Http::STATUS_BAD_REQUEST;
		}

		$response->setStatus(status: $status);

		return $response;
	}//end errorPage()

	/**
	 * The scheme, host and port of a URL, for the form-action policy.
	 *
	 * @param string $url An absolute URL.
	 *
	 * @return string The origin.
	 */
	private function originOf(string $url): string {
		$parts = parse_url($url);
		$origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
		if (isset($parts['port']) === true) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}//end originOf()
}//end class
