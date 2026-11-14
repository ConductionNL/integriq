<?php

/**
 * Integriq OAuthTokenController
 *
 * Integriq as an OAuth 2.0 authorization server for its consumers
 * (access-oauth-and-token-validation, REQ-TOKV-003): the client credentials
 * token endpoint, the published key set, and the two admin acts behind them,
 * creating the issuer key and setting a consumer's client secret.
 *
 * `token` and `jwks` are `#[PublicPage]` on purpose: the caller is a partner
 * system, not a Nextcloud session. The token endpoint's access check is the
 * client secret in the method body; the key set is public by definition.
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
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\OAuthError;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * The token endpoint, the key set and the admin acts behind them.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
class OAuthTokenController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string           $appName     The app name.
	 * @param IRequest         $request     The request.
	 * @param TokenIssuer      $issuer      Grants tokens and sets client secrets.
	 * @param IssuerKeyService $issuerKey   The issuer key.
	 * @param IUserSession     $userSession The admin who creates the key.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TokenIssuer $issuer,
		private readonly IssuerKeyService $issuerKey,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * POST /api/oauth/token: the client credentials grant.
	 *
	 * Access control: the consumer's client secret, by HTTP Basic or the
	 * client_id and client_secret form fields; no session.
	 *
	 * @return JSONResponse 200 the token; 400, 401 or 503 with an RFC 6749 error; 429 via AnonRateLimit.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function token(): JSONResponse {
		[$clientId, $clientSecret] = $this->clientCredentials();
		try {
			$granted = $this->issuer->issue(
				grantType: (string) $this->request->getParam('grant_type', ''),
				clientId: $clientId,
				clientSecret: $clientSecret,
				scope: $this->optional(name: 'scope'),
				resource: $this->optional(name: 'resource')
			);
		} catch (OAuthError $e) {
			$response = new JSONResponse($e->body(), $e->status);
			if ($e->status === Http::STATUS_UNAUTHORIZED) {
				$response->addHeader('WWW-Authenticate', 'Basic realm="integriq"');
			}

			return $this->noStore(response: $response);
		}

		return $this->noStore(response: new JSONResponse($granted));
	}//end token()

	/**
	 * GET /.well-known/jwks.json: integriq's public signing key set.
	 *
	 * Access control: none; a key set is public by definition.
	 *
	 * @return JSONResponse 200 {keys}
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function jwks(): JSONResponse {
		return new JSONResponse($this->issuerKey->jwks());
	}//end jwks()

	/**
	 * POST /api/oauth/issuer-key: create or replace the issuer key.
	 *
	 * @return JSONResponse 201 the public JWK; 503 when the broker or organisation is missing.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function createIssuerKey(): JSONResponse {
		try {
			$jwk = $this->issuerKey->create(owner: (string) ($this->userSession->getUser()?->getUID() ?? ''));
		} catch (RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($jwk, Http::STATUS_CREATED);
	}//end createIssuerKey()

	/**
	 * POST /api/consumers/{id}/client-secret: set a new client secret and show it once.
	 *
	 * @param string $id The consumer.
	 *
	 * @return JSONResponse 201 {clientId, clientSecret}; 404 when it is not a client credentials consumer.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function clientSecret(string $id): JSONResponse {
		$secret = $this->issuer->setClientSecret(consumerId: $id);
		if ($secret === null) {
			return new JSONResponse(['message' => 'No client credentials consumer with this id'], Http::STATUS_NOT_FOUND);
		}

		return $this->noStore(response: new JSONResponse(['clientId' => $id, 'clientSecret' => $secret], Http::STATUS_CREATED));
	}//end clientSecret()

	/**
	 * The client id and secret: HTTP Basic first, else the form fields.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function clientCredentials(): array {
		$header = (string) $this->request->getHeader('Authorization');
		if (stripos($header, 'Basic ') === 0) {
			$decoded = base64_decode(substr($header, 6), true);
			if (is_string($decoded) === true && str_contains($decoded, ':') === true) {
				[$id, $secret] = explode(':', $decoded, 2);
				return [urldecode($id), urldecode($secret)];
			}
		}

		return [(string) $this->request->getParam('client_id', ''), (string) $this->request->getParam('client_secret', '')];
	}//end clientCredentials()

	/**
	 * A request parameter, null when it is absent or empty.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null
	 */
	private function optional(string $name): ?string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end optional()

	/**
	 * A token answer must not be cached (RFC 6749 section 5.1).
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return JSONResponse
	 */
	private function noStore(JSONResponse $response): JSONResponse {
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Pragma', 'no-cache');

		return $response;
	}//end noStore()
}//end class
