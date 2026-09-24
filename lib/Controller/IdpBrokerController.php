<?php

/**
 * Integriq IdpBrokerController.
 *
 * The exchange endpoint. A consuming app presents the one-time code it
 * received through the browser redirect, together with its own shared secret,
 * and receives the signed subject envelope once.
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
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EnvelopeExchangeService $exchangeService,
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

}//end class
