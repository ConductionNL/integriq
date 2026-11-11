<?php

/**
 * The endpoint authentication rule admits and refuses each credential type as before.
 *
 * Pins EndpointService::processAuthenticationRule() for every type it knows:
 * an admitted call passes its data through unchanged, a refused one answers
 * 401 with the refusal's message and details, a missing header 403, an
 * unknown type 501, and a rule without authentication lets the call through,
 * so the shared refusal answer can move without changing any outcome.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the endpoint authentication rule.
 */
final class EndpointAuthenticationRuleGoldenTest extends TestCase {

	/**
	 * Each type, the bridge method it calls and the arguments it passes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
	 */
	public static function types(): array {
		return [
			'apikey' => ['apikey', 'authorizeApiKey', ['Bearer k', ['k1']]],
			'jwt' => ['jwt', 'authorizeJwt', ['Bearer k']],
			'jwt-zgw' => ['jwt-zgw', 'authorizeJwt', ['Bearer k']],
			'basic' => ['basic', 'authorizeBasic', ['Bearer k', ['u1'], ['g1']]],
			'oauth' => ['oauth', 'authorizeOAuth', ['Bearer k', ['u1'], ['g1']]],
			'nc-session' => ['nc-session', 'authorizeNcSession', [['u1'], ['g1']]],
		];

	}//end types()

	/**
	 * Run the rule over a bridge double.
	 *
	 * @param OpenRegisterCredentialBridge $bridge         The credential checks.
	 * @param array<string, mixed>         $authentication The rule's authentication block, or [] for none.
	 *
	 * @return array|JSONResponse
	 */
	private function runRule(OpenRegisterCredentialBridge $bridge, array $authentication): array|JSONResponse {
		$reflection = new ReflectionClass(EndpointService::class);
		$service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($service, $bridge);

		$configuration = [];
		if ($authentication !== []) {
			$configuration['authentication'] = $authentication;
		}

		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => $configuration]);

		return $reflection->getMethod('processAuthenticationRule')->invoke($service, $rule, ['headers' => ['AUTHORIZATION' => 'Bearer k'], 'body' => 'b']);

	}//end runRule()

	/**
	 * The authentication block of a type.
	 *
	 * @param string $type The type.
	 *
	 * @return array<string, mixed>
	 */
	private function block(string $type): array {
		return ['type' => $type, 'keys' => ['k1'], 'users' => ['u1'], 'groups' => ['g1']];

	}//end block()

	/**
	 * An admitted call passes its data through unchanged.
	 *
	 * @param string            $type   The type.
	 * @param string            $method The bridge method.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return void
	 */
	#[DataProvider('types')]
	public function testAnAdmittedCallPassesThrough(string $type, string $method, array $args): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method($method)->with(...$args);

		$this->assertSame(['headers' => ['AUTHORIZATION' => 'Bearer k'], 'body' => 'b'], $this->runRule(bridge: $bridge, authentication: $this->block(type: $type)));

	}//end testAnAdmittedCallPassesThrough()

	/**
	 * A refused call answers 401 with the refusal's message and details.
	 *
	 * @param string            $type   The type.
	 * @param string            $method The bridge method.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return void
	 */
	#[DataProvider('types')]
	public function testARefusedCallAnswers401(string $type, string $method, array $args): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->once())->method($method)->willThrowException(new AuthenticationException('refused', ['reason' => 'expired']));

		$response = $this->runRule(bridge: $bridge, authentication: $this->block(type: $type));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame([401, ['error' => 'refused', 'details' => ['reason' => 'expired']]], [$response->getStatus(), $response->getData()]);
		$this->assertNotSame([], $args);

	}//end testARefusedCallAnswers401()

	/**
	 * The edges: no authentication, no header, a custom header, an unknown type.
	 *
	 * @return void
	 */
	public function testTheEdges(): void {
		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->expects($this->never())->method('authorizeApiKey');

		$this->assertSame(['headers' => ['AUTHORIZATION' => 'Bearer k'], 'body' => 'b'], $this->runRule(bridge: $bridge, authentication: []));

		$missing = $this->runRule(bridge: $bridge, authentication: ['type' => 'apikey', 'header' => 'X-Key', 'keys' => []]);
		$this->assertSame(
			[403, ['error' => 'forbidden', 'details' => 'you are not allowed to access this endpoint unauththenticated']],
			[$missing->getStatus(), $missing->getData()]
		);

		$unknown = $this->runRule(bridge: $bridge, authentication: ['type' => 'magic']);
		$this->assertSame([501, ['error' => 'The authentication method is not supported']], [$unknown->getStatus(), $unknown->getData()]);

	}//end testTheEdges()
}//end class
