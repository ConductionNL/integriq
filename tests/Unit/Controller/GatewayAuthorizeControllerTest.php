<?php

/**
 * Unit tests for the connection authorization route.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-web-server-or-broker-asks-integriq-who-may-connect-req-gqlp-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\GatewayAuthorizeController;
use OCA\Integriq\Service\Gateway\GatewayConnectionAuthorizer;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(GatewayAuthorizeController::class)]
class GatewayAuthorizeControllerTest extends TestCase {

	/**
	 * The controller over a request with $params and $headers, recording what the authorizer was asked.
	 *
	 * @param array<string, string> $params  Request parameters.
	 * @param array<string, string> $headers Request headers.
	 * @param array                 $answer  What the authorizer answers.
	 * @param array|null            $asked   Filled with the authorizer's input.
	 *
	 * @return GatewayAuthorizeController
	 */
	private function controller(array $params, array $headers, array $answer, ?array &$asked): GatewayAuthorizeController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default=null) => ($params[$key] ?? $default));
		$request->method('getHeader')->willReturnCallback(fn (string $name) => ($headers[$name] ?? ''));
		$request->method('getRemoteAddress')->willReturn('192.0.2.1');

		$authorizer = $this->createMock(GatewayConnectionAuthorizer::class);
		$authorizer->method('authorize')->willReturnCallback(
			function (array $ask) use (&$asked, $answer) {
				$asked = $ask;
				return $answer;
			}
		);

		return new GatewayAuthorizeController(appName: 'integriq', request: $request, authorizer: $authorizer);
	}

	public function testNginxHeadersReachTheAuthorizerAndAnAllowedAnswerNamesTheConsumer(): void {
		$asked = null;
		$response = $this->controller(
			params: ['protocol' => 'websocket'],
			headers: [
				'X-Integriq-Gateway-Secret' => ' server-secret ',
				'X-Original-URI' => '/ws/meldingen?x=1',
				'X-Integriq-Credential' => 'good-key',
				'X-Real-IP' => '10.0.0.5',
				'X-Request-ID' => 'req-7',
			],
			answer: ['status' => 200, 'reason' => 'allowed', 'consumer' => 'c-1', 'headers' => ['X-Integriq-Consumer' => 'c-1']],
			asked: $asked
		)->authorize();

		$this->assertSame(
			[
				'serverSecret' => 'server-secret',
				'protocol' => 'websocket',
				'target' => '/ws/meldingen?x=1',
				'credential' => 'good-key',
				'clientAddress' => '10.0.0.5',
				'connectionId' => 'req-7',
			],
			$asked
		);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['allowed' => true, 'reason' => 'allowed', 'consumer' => 'c-1'], $response->getData());
		// Response::getHeaders() reaches for the server container; read what was set instead.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('c-1', $headers['X-Integriq-Consumer']);
	}

	public function testAMosquittoPostIsReadFromItsFields(): void {
		$asked = null;
		$response = $this->controller(
			params: ['protocol' => 'mqtt', 'topic' => 'meldingen/1', 'username' => 'app', 'password' => 'good-key', 'clientid' => 'mqtt-9'],
			headers: ['X-Integriq-Gateway-Secret' => 'server-secret'],
			answer: ['status' => 401, 'reason' => 'no_consumer', 'consumer' => null, 'headers' => []],
			asked: $asked
		)->authorize();

		$this->assertSame('meldingen/1', $asked['target']);
		$this->assertSame('good-key', $asked['credential']);
		$this->assertSame('mqtt-9', $asked['connectionId']);
		$this->assertSame('192.0.2.1', $asked['clientAddress']);
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['allowed' => false, 'reason' => 'no_consumer'], $response->getData());
	}

	public function testTheRouteIsPublicWithoutCsrfBecauseAServerCallsIt(): void {
		$method = new ReflectionMethod(GatewayAuthorizeController::class, 'authorize');

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
	}
}
