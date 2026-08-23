<?php

/**
 * Unit tests for the `nc-session` branch of
 * EndpointService::processAuthenticationRule() (ocon#1068).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\AuthenticationException;
use OCA\Integriq\Service\AuthorizationService;
use OCA\Integriq\Service\EndpointService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `processAuthenticationRule()` is private and `EndpointService`'s constructor
 * takes 22 collaborators, so these tests build the instance WITHOUT the
 * constructor and inject only the one collaborator the branch under test uses.
 * That is deliberate: the pre-existing `EndpointServiceTest` is red at baseline
 * precisely because it tracks that constructor, and a fix for #1068 must not
 * inherit that fragility.
 */
class EndpointServiceNcSessionRuleTest extends TestCase {

	/**
	 * @var AuthorizationService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $authorizationService;

	/**
	 * @var EndpointService
	 */
	private EndpointService $service;

	/**
	 * Build an EndpointService with only `authorizationService` populated.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->authorizationService = $this->createMock(AuthorizationService::class);

		$reflection = new ReflectionClass(EndpointService::class);
		$this->service = $reflection->newInstanceWithoutConstructor();

		// No setAccessible() call: it is a no-op since PHP 8.1 and deprecated
		// in 8.5, which the suite reports.
		$reflection->getProperty('authorizationService')->setValue($this->service, $this->authorizationService);

	}//end setUp()

	/**
	 * Invoke the private rule processor.
	 *
	 * @param array $authentication The rule's `configuration.authentication` block.
	 * @param array $data The pipeline data.
	 *
	 * @return array|JSONResponse The rule's verdict.
	 */
	private function process(array $authentication, array $data = []) {
		$rule = new ObjectEntity();
		$rule->setObject(['configuration' => ['authentication' => $authentication]]);

		$method = (new ReflectionClass(EndpointService::class))->getMethod('processAuthenticationRule');

		return $method->invoke($this->service, $rule, $data);
	}//end process()

	/**
	 * Skip when the harness has no `OC\AppFramework\Http`.
	 *
	 * `EndpointService` imports the INTERNAL `OC\AppFramework\Http` for its
	 * status constants, and the unit bootstrap stubs only `OCP\*` plus a
	 * handful of `OC\Hooks` classes — so every pre-existing branch that
	 * returns one of those constants is unreachable in this suite. That is a
	 * harness gap, not a defect in the code under test, and it predates
	 * #1068: the `nc-session` branch itself uses no such constant, which is
	 * why the tests that cover it run unconditionally.
	 *
	 * @return void
	 */
	private function requireInternalHttpClass(): void {
		if (class_exists('OC\AppFramework\Http') === false) {
			$this->markTestSkipped(
				'Requires the internal OC\AppFramework\Http class, which the unit bootstrap does not stub.'
			);
		}

	}//end requireInternalHttpClass()

	/**
	 * The whole point of #1068: a request carrying NO Authorization header is
	 * still evaluated, because `nc-session` is dispatched before the
	 * header-presence guard that used to 403 it unconditionally.
	 *
	 * @return void
	 */
	public function testSessionRuleIsEvaluatedWithoutAnAuthorizationHeader(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizeNcSession')
			->with([], []);

		$result = $this->process(['type' => 'nc-session'], ['headers' => []]);

		$this->assertIsArray($result);

	}//end testSessionRuleIsEvaluatedWithoutAnAuthorizationHeader()

	/**
	 * A refusal from the authorization service becomes a 401, never a pass.
	 *
	 * @return void
	 */
	public function testRefusalBecomesA401(): void {
		$this->authorizationService->method('authorizeNcSession')
			->willThrowException(new AuthenticationException('Not authorized', ['reason' => 'no session']));

		$result = $this->process(['type' => 'nc-session'], ['headers' => []]);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(401, $result->getStatus());

	}//end testRefusalBecomesA401()

	/**
	 * The configured users/groups allow-lists reach the authorization service
	 * unchanged — the same config shape the `basic` rule uses.
	 *
	 * @return void
	 */
	public function testAllowListsAreForwarded(): void {
		$this->authorizationService->expects($this->once())
			->method('authorizeNcSession')
			->with(['alice'], ['admin']);

		$this->process(
			[
				'type' => 'nc-session',
				'users' => ['alice'],
				'groups' => ['admin'],
			],
			['headers' => []]
		);

	}//end testAllowListsAreForwarded()

	/**
	 * A header-bearing type is untouched by the new branch: with no
	 * Authorization header it still short-circuits with the pre-existing 403.
	 *
	 * @return void
	 */
	public function testHeaderTypesKeepTheirExisting403(): void {
		$this->requireInternalHttpClass();

		$this->authorizationService->expects($this->never())->method('authorizeNcSession');

		$result = $this->process(['type' => 'basic'], ['headers' => []]);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(403, $result->getStatus());

	}//end testHeaderTypesKeepTheirExisting403()

	/**
	 * An unknown type is still a 501 — the new branch did not swallow the
	 * default arm of the dispatch.
	 *
	 * @return void
	 */
	public function testUnknownTypeStillReturns501(): void {
		$this->requireInternalHttpClass();

		$result = $this->process(
			['type' => 'not-a-real-type'],
			['headers' => ['Authorization' => 'Bearer x']]
		);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(501, $result->getStatus());

	}//end testUnknownTypeStillReturns501()
}//end class
