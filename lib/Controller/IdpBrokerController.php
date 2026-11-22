<?php

/**
 * Integriq IdpBrokerController.
 *
 * The three endpoints of a government login. The start sends the browser to
 * the identity provider, the callback sends it back to the consuming app with
 * a one-time code, and the exchange lets that app's server trade the code and
 * its own shared secret for the signed subject envelope, once.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Auth\Idp\EnvelopeExchangeServiceInterface;
use OCA\Integriq\Auth\Idp\IdpLoginServiceInterface;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpAssuranceBelowFloorException;
use OCA\Integriq\Exception\IdpDisclosureIncompleteException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Exchanges a one-time code for a subject envelope.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 */
class IdpBrokerController extends Controller {

	/**
	 * Names the level used and the floor on the refusal page.
	 *
	 * @var TrustLevelMapper
	 */
	private readonly TrustLevelMapper $trustMapper;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param EnvelopeExchangeServiceInterface $exchangeService Redeems the code.
	 * @param IdpLoginServiceInterface $loginService Starts and finishes the browser login.
	 * @param IL10N $l10n Translates the error page.
	 * @param LoggerInterface $logger Records why a start or a callback was refused.
	 * @param TrustLevelMapper|null $trustMapper Names the level used and the floor on the refusal page.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EnvelopeExchangeServiceInterface $exchangeService,
		private readonly IdpLoginServiceInterface $loginService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		?TrustLevelMapper $trustMapper = null,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->trustMapper = ($trustMapper ?? new TrustLevelMapper());

	}//end __construct()

	/**
	 * Redeem a one-time code for its subject envelope.
	 *
	 * No Nextcloud session is involved: the consuming app's server calls this
	 * directly and proves who it is with the shared secret in the
	 * `Authorization` header. The response body is the envelope and nothing
	 * else, and a consumer mints its own session from it. The envelope is
	 * never a session: it carries `use: idp-envelope`, which a session
	 * resolver refuses.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): a login endpoint reachable without a
	 * session. A code is 256 bits, so guessing is not the threat; the limit
	 * bounds a consumer that retries a refused exchange in a loop.
	 *
	 * @return JSONResponse `{envelope: <jws>}` on success, 401 on any refusal.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function exchange(): JSONResponse {
		// Read the credential here, at the denial, not inside the service.
		// This endpoint is #[PublicPage]: middleware admits the caller without
		// a session, so this header is the only thing between an anonymous
		// request and a verified citizen identity.
		$authorization = (string)$this->request->getHeader('Authorization');
		$presentedSecret = $authorization;
		if (str_starts_with($authorization, 'Bearer ') === true) {
			$presentedSecret = substr($authorization, strlen('Bearer '));
		}

		$code = (string)($this->request->getParam('code') ?? '');
		$consumer = (string)($this->request->getParam('consumer') ?? '');

		try {
			$envelope = $this->exchangeService->redeemCode(
				code: $code,
				consumer: $consumer,
				presentedSecret: $presentedSecret
			);
		} catch (IdpAssertionException $exception) {
			// One undifferentiated 401 for every refusal. A caller that can
			// tell "unknown code" from "wrong secret" from "already redeemed"
			// can probe for all three.
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['envelope' => $envelope]);

	}//end exchange()

	/**
	 * Start a government login and send the browser to the identity provider.
	 *
	 * A consuming app sends the browser here with the organisation, itself as
	 * consumer, the trust it needs, its return address and its relay state.
	 * Any refusal shows integriq's own error page and redirects nowhere.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): reachable without a session, and each
	 * accepted call stores a state for five minutes, so the limit bounds how
	 * much cache one address can fill.
	 *
	 * A Yivi login also names the attributes to disclose (`attributes`,
	 * comma-separated ids); a list that is empty or names one the
	 * organisation does not allow is refused the same way.
	 *
	 * @param string $provider `digid`, `eherkenning`, `eidas` or `yivi`.
	 *
	 * @return RedirectResponse|TemplateResponse The redirect to the identity provider, or the error page.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function start(string $provider): RedirectResponse|TemplateResponse {
		try {
			$redirectUrl = $this->loginService->start(
				provider: $provider,
				params: [
					'organisation' => (string)($this->request->getParam('organisation') ?? ''),
					'consumer' => (string)($this->request->getParam('consumer') ?? ''),
					'trust' => (string)($this->request->getParam('trust') ?? ''),
					'returnUrl' => (string)($this->request->getParam('returnUrl') ?? ''),
					'relayState' => (string)($this->request->getParam('relayState') ?? ''),
					'attributes' => (string)($this->request->getParam('attributes') ?? ''),
				]
			);
		} catch (IdpAssertionException $exception) {
			$this->logger->warning(
				'Integriq idp-broker: a login start was refused: ' . $exception->getMessage(),
				['provider' => $provider, 'consumer' => (string)($this->request->getParam('consumer') ?? '')]
			);

			return $this->errorPage();
		}

		return new RedirectResponse($redirectUrl);

	}//end start()

	/**
	 * Finish a government login and send the browser back with a one-time code.
	 *
	 * The identity provider posts or redirects here. Once the stored state is
	 * found every outcome goes back to the consumer's registered address; an
	 * answer to no stored state shows integriq's own error page.
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): reachable without a session and the
	 * target of every provider response, so the limit is generous but bounds
	 * a replay loop.
	 *
	 * @param string $provider `digid`, `eherkenning`, `eidas` or `yivi`.
	 *
	 * @return RedirectResponse|TemplateResponse The redirect to the consumer, the floor refusal page, or the error page.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function callback(string $provider): RedirectResponse|TemplateResponse {
		try {
			$redirectUrl = $this->loginService->callback(provider: $provider, callback: $this->request->getParams());
		} catch (IdpAssuranceBelowFloorException $exception) {
			// The one refusal that is explained: the service already logged it.
			return $this->belowFloorPage(refusal: $exception);
		} catch (IdpDisclosureIncompleteException $exception) {
			// Explained too: the person can go back and share what was asked.
			return $this->disclosureIncompletePage(refusal: $exception);
		} catch (IdpAssertionException $exception) {
			$this->logger->warning(
				'Integriq idp-broker: a login response was refused: ' . $exception->getMessage(),
				['provider' => $provider]
			);

			return $this->errorPage();
		}

		return new RedirectResponse($redirectUrl);

	}//end callback()

	/**
	 * Integriq's own page for a login below the assurance floor.
	 *
	 * It names the level the person used, the level this service needs and
	 * how to get it for that provider, and has one action: back to the
	 * consumer with `error=assurance_below_floor` and the relay state.
	 *
	 * @param IdpAssuranceBelowFloorException $refusal The refusal, with the address to go back to.
	 *
	 * @return TemplateResponse The page, with status 403.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	private function belowFloorPage(IdpAssuranceBelowFloorException $refusal): TemplateResponse {
		$provider = $refusal->getProvider();

		return $this->refusalPage(
			text: [
				'title' => $this->l10n->t('This service needs a stronger login'),
				'message' => $this->l10n->t(
					'You logged in with %1$s. This service needs at least %2$s.',
					[
						$this->trustMapper->levelLabel(provider: $provider, level: $refusal->getLevelUsed()),
						$this->trustMapper->floorLabel(provider: $provider),
					]
				),
				'hint' => $this->howToGetTheFloor(provider: $provider),
			],
			actionUrl: $refusal->getReturnUrl()
		);

	}//end belowFloorPage()

	/**
	 * Integriq's own page for a refusal it explains, with one action back to the consumer.
	 *
	 * @param array{title: string, message: string, hint: string} $text      What the page says.
	 * @param string                                              $actionUrl Where its one action leads.
	 *
	 * @return TemplateResponse The page, with status 403.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	private function refusalPage(array $text, string $actionUrl): TemplateResponse {
		$response = new TemplateResponse(
			appName: $this->appName,
			templateName: 'idp/refused',
			params: array_merge($text, ['action' => $this->l10n->t('Go back'), 'actionUrl' => $actionUrl]),
			renderAs: TemplateResponse::RENDER_AS_GUEST
		);
		$response->setStatus(status: Http::STATUS_FORBIDDEN);

		return $response;

	}//end refusalPage()

	/**
	 * Integriq's own page for an attribute login that shared less than it asked.
	 *
	 * Its one action goes back to the consumer with
	 * `error=attributes_not_disclosed` and the relay state.
	 *
	 * @param IdpDisclosureIncompleteException $refusal The refusal, with the address to go back to.
	 *
	 * @return TemplateResponse The page, with status 403.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	private function disclosureIncompletePage(IdpDisclosureIncompleteException $refusal): TemplateResponse {
		return $this->refusalPage(
			text: [
				'title' => $this->l10n->t('You did not share all the requested details'),
				'message' => $this->l10n->t('This service asked for details that were not shared in the Yivi app.'),
				'hint' => $this->l10n->t('Go back and share every detail the service asks for to continue.'),
			],
			actionUrl: $refusal->getReturnUrl()
		);

	}//end disclosureIncompletePage()

	/**
	 * How a person gets a means at the floor, for one provider.
	 *
	 * @param string $provider The provider id.
	 *
	 * @return string The advice.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-substantial-is-the-assurance-floor-req-idpc-006
	 */
	private function howToGetTheFloor(string $provider): string {
		return match ($provider) {
			TrustLevelMapper::PROVIDER_DIGID => $this->l10n->t(
				'Log in with the DigiD app with an identity check, or use another DigiD means at level Substantieel.'
			),
			TrustLevelMapper::PROVIDER_EHERKENNING => $this->l10n->t(
				'Log in with an eHerkenning means at level EH3 or higher. Your eHerkenning supplier can tell you how to get one.'
			),
			default => $this->l10n->t('Log in with a means at eIDAS level substantial or high.'),
		};

	}//end howToGetTheFloor()

	/**
	 * Integriq's own error page for a login that cannot go anywhere safe.
	 *
	 * @return TemplateResponse The page, with status 400.
	 */
	private function errorPage(): TemplateResponse {
		$response = new TemplateResponse(
			appName: $this->appName,
			templateName: 'idp-error',
			params: [
				'title' => $this->l10n->t('You cannot sign in right now'),
				'message' => $this->l10n->t('Go back to the page you came from and try again.'),
				'hint' => $this->l10n->t('Still stuck? Contact the organisation whose page sent you here.'),
			],
			renderAs: TemplateResponse::RENDER_AS_GUEST
		);
		$response->setStatus(status: Http::STATUS_BAD_REQUEST);

		return $response;

	}//end errorPage()

}//end class
