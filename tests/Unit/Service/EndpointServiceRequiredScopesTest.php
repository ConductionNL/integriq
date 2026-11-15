<?php

/**
 * Unit tests for required scopes on an endpoint's authentication rule: a
 * consumer that lacks a scope required for the method gets 403 naming it, the
 * same consumer passes where it holds the scope, and a rule without required
 * scopes behaves as before.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/consumer-management/spec.md#requirement-a-consumer-is-limited-to-the-endpoints-and-actions-its-scopes-allow-req-tokv-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Consumer\ConsumerScopeCheck;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Required scopes after authentication.
 */
#[CoversClass(EndpointService::class)]
#[UsesClass(ConsumerScopeCheck::class)]
class EndpointServiceRequiredScopesTest extends TestCase {

	private OpenRegisterCredentialBridge&MockObject $bridge;

	private EndpointService $service;

	/**
	 * The service with only its credential bridge set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$reflection = new ReflectionClass(EndpointService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('authorizationService')->setValue($this->service, $this->bridge);
	}//end setUp()

	/**
	 * Run the authentication rule.
	 *
	 * @param array<string,mixed> $authentication The rule's authentication configuration.
	 * @param string              $method         The request method.
	 * @param string              $header         The Authorization header.
	 *
	 * @return array<string,mixed>|JSONResponse The result.
	 */
	private function process(array $authentication, string $method, string $header = 'Bearer key-1'): array|JSONResponse {
		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['authentication' => $authentication]]);

		return (new ReflectionClass(EndpointService::class))->getMethod('processAuthenticationRule')
			->invoke($this->service, $rule, ['headers' => ['Authorization' => $header], 'method' => $method]);
	}//end process()

	/**
	 * The bridge resolves a consumer holding the given scopes.
	 *
	 * @param array<int,string> $scopes The consumer's scopes.
	 *
	 * @return void
	 */
	private function consumerHolds(array $scopes): void {
		$consumer = new ObjectEntity();
		$consumer->setObject(['name' => 'zaaksysteem', 'scopes' => $scopes]);
		$this->bridge->method('getResolvedConsumer')->willReturn($consumer);
	}//end consumerHolds()

	/**
	 * A read-only consumer posting where zaken:write is required gets 403 naming it; its GET passes.
	 *
	 * @return void
	 */
	public function testAReadOnlyConsumerCannotWrite(): void {
		$this->consumerHolds(scopes: ['zaken:read']);
		$this->bridge->expects($this->exactly(2))->method('authorizeApiKey');
		$authentication = ['type' => 'apikey', 'keys' => ['key-1'], 'requiredScopes' => ['POST' => ['zaken:write'], 'GET' => ['zaken:read']]];

		$refused = $this->process(authentication: $authentication, method: 'POST');
		$this->assertInstanceOf(JSONResponse::class, $refused);
		$this->assertSame(403, $refused->getStatus());
		$this->assertSame(
			['error' => 'insufficient_scope', 'details' => 'This call requires the scope zaken:write.', 'missingScopes' => ['zaken:write']],
			$refused->getData()
		);

		$this->assertIsArray($this->process(authentication: $authentication, method: 'get'));
	}//end testAReadOnlyConsumerCannotWrite()

	/**
	 * A JWT caller holds its token's scope claim, not the consumer's stored scopes.
	 *
	 * @return void
	 */
	public function testAJwtCallerHoldsItsTokensScopes(): void {
		$this->consumerHolds(scopes: ['zaken:write']);
		$this->bridge->expects($this->exactly(2))->method('authorizeJwt');
		$authentication = ['type' => 'jwt', 'requiredScopes' => ['zaken:write']];
		$token = static fn (array $claims): string => 'Bearer e30.' . rtrim(strtr(base64_encode((string)json_encode($claims)), '+/', '-_'), '=') . '.sig';

		$refused = $this->process(authentication: $authentication, method: 'POST', header: $token(['scope' => 'zaken:read']));
		$this->assertInstanceOf(JSONResponse::class, $refused);
		$this->assertSame(['zaken:write'], $refused->getData()['missingScopes']);

		$this->assertIsArray($this->process(authentication: $authentication, method: 'POST', header: $token(['scope' => 'zaken:read zaken:write'])));
	}//end testAJwtCallerHoldsItsTokensScopes()

	/**
	 * A rule without required scopes never asks for the consumer.
	 *
	 * @return void
	 */
	public function testARuleWithoutRequiredScopesBehavesAsBefore(): void {
		$this->bridge->expects($this->once())->method('authorizeApiKey');
		$this->bridge->expects($this->never())->method('getResolvedConsumer');

		$result = $this->process(authentication: ['type' => 'apikey', 'keys' => ['key-1']], method: 'POST');

		$this->assertSame(['headers' => ['Authorization' => 'Bearer key-1'], 'method' => 'POST'], $result);
	}//end testARuleWithoutRequiredScopesBehavesAsBefore()

	/**
	 * A caller without a consumer holds no scopes and is refused where scopes are required.
	 *
	 * @return void
	 */
	public function testACallerWithoutAConsumerHoldsNoScopes(): void {
		$this->bridge->method('getResolvedConsumer')->willReturn(null);

		$refused = $this->process(authentication: ['type' => 'basic', 'users' => [], 'groups' => [], 'requiredScopes' => ['*' => ['zaken:read']]], method: 'GET', header: 'Basic eDp5');

		$this->assertInstanceOf(JSONResponse::class, $refused);
		$this->assertSame(['zaken:read'], $refused->getData()['missingScopes']);
	}//end testACallerWithoutAConsumerHoldsNoScopes()
}//end class
