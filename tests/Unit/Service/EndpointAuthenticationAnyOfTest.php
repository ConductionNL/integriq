<?php

/**
 * Unit tests for `anyOf` on an endpoint's authentication rule.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-consumer-credentials/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The first method that passes lets the call through; with none, the 401 names each reason.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 */
final class EndpointAuthenticationAnyOfTest extends TestCase {

	private const DATA = ['headers' => ['Authorization' => 'Bearer k'], 'body' => 'b'];

	public function testAnApiKeyCallerPassesWithoutTryingTheJwt(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method('authorizeApiKey')->with('Bearer k', ['k1']);
		$bridge->expects($this->never())->method('authorizeJwt');

		$this->assertSame(self::DATA, $this->runRule(bridge: $bridge, anyOf: [['type' => 'apikey', 'keys' => ['k1']], ['type' => 'jwt']]));
	}

	public function testAJwtCallerPassesWhenTheApiKeyIsRefused(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method('authorizeApiKey')->willThrowException(new AuthenticationException('Invalid API key', []));
		$bridge->expects($this->once())->method('authorizeJwt')->with('Bearer k');

		$this->assertSame(self::DATA, $this->runRule(bridge: $bridge, anyOf: [['type' => 'apikey', 'keys' => []], ['type' => 'jwt']]));
	}

	public function testACallerWithNeitherGets401NamingEachReason(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->method('authorizeApiKey')->willThrowException(new AuthenticationException('Invalid API key', ['key' => 'unknown']));
		$bridge->method('authorizeJwt')->willThrowException(new AuthenticationException('Invalid JWT', ['reason' => 'expired']));

		$response = $this->runRule(bridge: $bridge, anyOf: [['type' => 'apikey', 'keys' => []], ['type' => 'jwt'], ['type' => 'basic', 'header' => 'X-Missing', 'users' => [], 'groups' => []], ['type' => 'magic']]);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'unauthorized',
				'details' => [
					'anyOf' => [
						['method' => '0:apikey', 'error' => 'Invalid API key', 'details' => ['key' => 'unknown']],
						['method' => '1:jwt', 'error' => 'Invalid JWT', 'details' => ['reason' => 'expired']],
						['method' => '2:basic', 'error' => 'forbidden', 'details' => 'you are not allowed to access this endpoint unauththenticated'],
						['method' => '3:magic', 'error' => 'The authentication method is not supported', 'details' => null],
					],
				],
			],
			$response->getData()
		);
	}

	public function testANestedOrMalformedEntryNeverPasses(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->never())->method('authorizeApiKey');

		$response = $this->runRule(bridge: $bridge, anyOf: ['apikey', ['anyOf' => [['type' => 'apikey', 'keys' => []]]]]);

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['0:invalid', '1:invalid'], array_column($response->getData()['details']['anyOf'], 'method'));
	}

	public function testAnEmptyAnyOfFallsBackToTheRulesOwnType(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method('authorizeJwt');

		$this->assertSame(self::DATA, $this->runRule(bridge: $bridge, anyOf: [], type: 'jwt'));
	}

	/**
	 * Run the authentication rule.
	 *
	 * @param OpenRegisterCredentialBridge $bridge The bridge double.
	 * @param array                        $anyOf  The anyOf list.
	 * @param string|null                  $type   The rule's own type.
	 *
	 * @return array|JSONResponse
	 */
	private function runRule(OpenRegisterCredentialBridge $bridge, array $anyOf, ?string $type = null): array|JSONResponse {
		$reflection = new ReflectionClass(EndpointService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($service, $bridge);
		$authentication = ['anyOf' => $anyOf];
		if ($type !== null) {
			$authentication['type'] = $type;
		}
		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['authentication' => $authentication]]);

		return $reflection->getMethod('processAuthenticationRule')->invoke($service, $rule, self::DATA);
	}
}
