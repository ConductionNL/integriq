<?php

/**
 * Integriq GatewayAuthorizeController
 *
 * The connection authorization route a web server or MQTT broker calls.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Gateway\GatewayConnectionAuthorizer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * `GET|POST /api/gateway/authorize`, called by the server, never by the client (design D2).
 *
 * The `auth_request` module of nginx repeats the client's method without a body, so the
 * route takes GET with headers as well as POST with a body; a Mosquitto HTTP
 * authentication plugin posts `username`, `password`, `clientid` and `topic`.
 * The server sends its shared secret in `X-Integriq-Gateway-Secret` and the
 * client's credential in `X-Integriq-Credential` (or, from a broker, as the
 * MQTT password), never in `Authorization`, which Nextcloud would read as a
 * login of its own.
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 */
class GatewayAuthorizeController extends Controller {

	/**
	 * Constructor for GatewayAuthorizeController.
	 *
	 * @param string                      $appName    The app name.
	 * @param IRequest                    $request    The request.
	 * @param GatewayConnectionAuthorizer $authorizer Decides the connection.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly GatewayConnectionAuthorizer $authorizer,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * May this client open this connection: 200 with the consumer, or 401 or 429.
	 *
	 * Public, because the caller is a web server with no Nextcloud session; the
	 * server secret is the authentication, checked before anything else. The
	 * anonymous rate limit is wide on purpose: one server asks for every
	 * client's reconnect, and the per-consumer connection limit is the real one.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 3000, period: 60)]
	public function authorize(): JSONResponse {
		$decision = $this->authorizer->authorize(ask: $this->ask());

		$body = ['allowed' => ($decision['consumer'] !== null), 'reason' => $decision['reason']];
		if ($decision['consumer'] !== null) {
			$body['consumer'] = $decision['consumer'];
		}

		return new JSONResponse($body, $decision['status'], $decision['headers']);

	}//end authorize()

	/**
	 * What the server sent, from the body, the query or its headers.
	 *
	 * @return array{serverSecret: string, protocol: string, target: string, credential: string, clientAddress: string, connectionId: string}
	 */
	private function ask(): array {
		return [
			'serverSecret' => $this->header(name: 'X-Integriq-Gateway-Secret'),
			'protocol' => $this->first(param: ['protocol'], header: 'X-Integriq-Protocol'),
			'target' => $this->first(param: ['route', 'topic'], header: 'X-Original-URI'),
			'credential' => $this->first(param: ['credential', 'password'], header: 'X-Integriq-Credential'),
			'clientAddress' => $this->first(param: ['clientAddress'], header: 'X-Real-IP', fallback: $this->request->getRemoteAddress()),
			'connectionId' => $this->first(param: ['connectionId', 'clientid'], header: 'X-Request-ID'),
		];

	}//end ask()

	/**
	 * The first non-empty string parameter, else the header, else the fallback.
	 *
	 * @param list<string> $param    Parameter names, in order.
	 * @param string       $header   The header.
	 * @param string       $fallback The value when neither is set.
	 *
	 * @return string
	 */
	private function first(array $param, string $header, string $fallback=''): string {
		foreach ($param as $name) {
			$value = $this->request->getParam($name);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		$value = $this->header(name: $header);
		if ($value !== '') {
			return $value;
		}

		return $fallback;

	}//end first()

	/**
	 * A request header, trimmed.
	 *
	 * @param string $name The header.
	 *
	 * @return string
	 */
	private function header(string $name): string {
		return trim((string)$this->request->getHeader($name));

	}//end header()
}//end class
