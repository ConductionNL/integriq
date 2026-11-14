<?php

/**
 * Integriq ConnectAccountPrompt.
 *
 * The answer to a call through a per-user source by someone who has not
 * connected their own account at its provider yet (sources-per-user-oauth,
 * design D3): 401 with a problem document of type `connect-account-required`
 * that names the provider and gives `connectUrl`, the credential broker's
 * connect start for a personal account at that provider, with the URL to
 * return to afterwards. A page or any client can send the user there; once
 * the broker holds the token set, the same call succeeds.
 *
 * The start is a POST, so `connectUrl` is where a client posts, not a link a
 * browser follows on its own; it answers the provider's authorization URL.
 *
 * @category Http
 * @package  OCA\Integriq\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Http;

use OCA\Integriq\Service\PerUserAccountNotConnectedException;
use OCP\AppFramework\Http;
use OCP\IURLGenerator;

/**
 * Builds the connect prompt.
 *
 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
 */
class ConnectAccountPrompt {

	public const TYPE = 'connect-account-required';

	public const TITLE = 'Connect your account first';

	/**
	 * The broker's connect start (OpenRegister credential-oauth2-connect-flow).
	 */
	public const START_ROUTE = 'openregister.credentialOauth2.start';

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Builds the absolute connect URL.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The 401 problem for a user without a connected account.
	 *
	 * @param PerUserAccountNotConnectedException $exception The refusal, naming the provider.
	 * @param string                              $returnUrl Where the user comes back to after connecting.
	 * @param string|null                         $instance  The request id.
	 *
	 * @return ProblemResponse The answer.
	 *
	 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
	 */
	public function respond(PerUserAccountNotConnectedException $exception, string $returnUrl, ?string $instance=null): ProblemResponse {
		$provider = $exception->getProvider();
		$response = new ProblemResponse(
			slug: 'authentication-failed',
			status: Http::STATUS_UNAUTHORIZED,
			detail: $exception->getMessage(),
			instance: $instance,
			extensions: [
				'provider'   => $provider,
				'connectUrl' => $this->connectUrl(provider: $provider, returnUrl: $returnUrl),
			]
		);

		$document = (array)$response->getData();
		$document['type'] = ProblemResponse::TYPE_BASE . self::TYPE;
		$document['title'] = self::TITLE;
		$response->setData($document);

		return $response;
	}//end respond()

	/**
	 * The connect start for a personal account at a provider, with the return URL.
	 *
	 * @param string $provider  The provider slug.
	 * @param string $returnUrl Where to come back to.
	 *
	 * @return string The absolute URL.
	 *
	 * @spec openspec/changes/sources-per-user-oauth/specs/http-call-engine/spec.md#requirement-a-user-without-a-connected-account-is-sent-to-connect-it-req-puoa-002
	 */
	public function connectUrl(string $provider, string $returnUrl): string {
		$parameters = ['provider' => $provider, 'scope' => 'personal'];
		if ($returnUrl !== '') {
			$parameters['returnUrl'] = $returnUrl;
		}

		return $this->urlGenerator->linkToRouteAbsolute(self::START_ROUTE, $parameters);
	}//end connectUrl()
}//end class
