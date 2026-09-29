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

use OCA\Integriq\Auth\Idp\EnvelopeExchangeService;
use OCA\Integriq\Auth\Idp\IdpLoginService;
use OCA\Integriq\Exception\IdpAssertionException;
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
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param EnvelopeExchangeService $exchangeService Redeems the code.
	 * @param IdpLoginService $loginService Starts and finishes the browser login.
	 * @param IL10N $l10n Translates the error page.
	 * @param LoggerInterface $logger Records why a start or a callback was refused.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EnvelopeExchangeService $exchangeService,
		private readonly IdpLoginService $loginService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

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
	 * @param string $provider `digid`, `eherkenning` or `eidas`.
	 *
	 * @return RedirectResponse|TemplateResponse The redirect to the identity provider, or the error page.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-login-starts-at-integriq-with-a-signed-single-use-state-req-idp-001
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
	 * @param string $provider `digid`, `eherkenning` or `eidas`.
	 *
	 * @return RedirectResponse|TemplateResponse The redirect to the consumer, or the error page.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-the-callback-returns-the-browser-with-a-one-time-code-req-idp-002
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function callback(string $provider): RedirectResponse|TemplateResponse {
		try {
			$redirectUrl = $this->loginService->callback(provider: $provider, callback: $this->request->getParams());
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
