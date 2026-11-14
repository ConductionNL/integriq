<?php

/**
 * The one guard of the Catalogi facade: a ZGW JWT whose client holds catalogi.lezen.
 *
 * The token is verified by the consumer credential bridge (signature, lifetime
 * and consumer, as every `jwt-zgw` endpoint is). Its `client_id` claim then
 * names the client, and the binding's client list says which scopes that
 * client holds and whether it may read concept types. Nothing here reads a
 * document type: a refusal is decided before the register is touched.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalogi;

use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridgeInterface;
use Throwable;

/**
 * Decides whether a request may read the catalogue, and whether it may see concepts.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
 */
class CatalogiTokenService {

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterCredentialBridgeInterface $credentials Verifies the JWT.
	 */
	public function __construct(
		private readonly OpenRegisterCredentialBridgeInterface $credentials,
	) {

	}//end __construct()

	/**
	 * The verdict for one request.
	 *
	 * @param string          $authorization The `Authorization` header.
	 * @param CatalogiBinding $binding       The binding, whose client list holds the scopes.
	 *
	 * @return array{status: int, concepts: bool} 200 when allowed, 401 for a missing or
	 *                                            invalid token, 403 without catalogi.lezen.
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
	 */
	public function verdict(string $authorization, CatalogiBinding $binding): array {
		$authorization = trim($authorization);
		if (str_starts_with($authorization, 'Bearer ') === false) {
			return ['status' => 401, 'concepts' => false];
		}

		try {
			$this->credentials->authorizeJwt(authorization: $authorization);
		} catch (Throwable) {
			return ['status' => 401, 'concepts' => false];
		}

		$client = $binding->clientFor(clientId: $this->clientIdOf(authorization: $authorization));
		if ($client === null || in_array(CatalogiBinding::SCOPE_READ, $client['scopes'], true) === false) {
			return ['status' => 403, 'concepts' => false];
		}

		return ['status' => 200, 'concepts' => $client['concepts']];
	}//end verdict()

	/**
	 * The ZGW client id of a verified token: `client_id`, else `iss`.
	 *
	 * @param string $authorization The `Authorization: Bearer` header.
	 *
	 * @return string The client id, '' when the payload names none.
	 */
	private function clientIdOf(string $authorization): string {
		$parts = explode('.', substr($authorization, strlen('Bearer ')));
		$payload = json_decode((string)base64_decode(strtr(($parts[1] ?? ''), '-_', '+/'), true), true);
		if (is_array($payload) === false) {
			return '';
		}

		return trim((string)($payload['client_id'] ?? ($payload['iss'] ?? '')));
	}//end clientIdOf()

}//end class
