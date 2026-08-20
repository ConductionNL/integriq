<?php

/**
 * Unit tests for PromotionController (environments-and-promotion).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenConnector\Controller\PromotionController;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\PromotionService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests preview/confirm auth gating, the confirmation guard (REQ-005), and
 * delegation to PromotionService.
 */
class PromotionControllerTest extends TestCase {
	/**
	 * @var PromotionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $promotionService;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->promotionService = $this->createMock(PromotionService::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @param array<string,mixed> $requestParams Values the mocked IRequest returns per param name.
	 * @param bool $isAdmin Whether the session user passes the admin break-glass.
	 * @param bool $authenticated Whether a session user exists at all.
	 *
	 * @return PromotionController
	 */
	private function makeController(array $requestParams = [], bool $isAdmin = true, bool $authenticated = true): PromotionController {
		$user = null;
		if ($authenticated === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('tester');
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$authConfig = $this->createMock(IAppConfig::class);
		$authConfig->method('getValueString')->willReturn('{}');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		$actionAuth = new ActionAuthService($authConfig, $groupManager);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($requestParams[$key] ?? $default)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []) => vsprintf(str_replace('%s', '%s', $text), $params)
		);

		return new PromotionController(
			'openconnector',
			$request,
			$this->promotionService,
			$l10n,
			$userSession,
			$actionAuth
		);
	}//end makeController()

	/**
	 * REQ-003: preview delegates to PromotionService::preview() and returns
	 * its merged classification.
	 *
	 * @return void
	 */
	public function testPreviewDelegatesToPromotionService(): void {
		$preview = ['creates' => [], 'updates' => [], 'credentialRefsNeedingRebind' => []];
		$this->promotionService->expects($this->once())
			->method('preview')
			->with(configurationId: 'cfg-1', targetEnvironmentSlug: 'acceptance', credentialBindings: [])
			->willReturn($preview);

		$controller = $this->makeController(['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance']);
		$response = $controller->preview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($preview, $response->getData());
	}//end testPreviewDelegatesToPromotionService()

	/**
	 * Preview without the required params is a 400 and never calls the service.
	 *
	 * @return void
	 */
	public function testPreviewMissingParamsReturns400(): void {
		$this->promotionService->expects($this->never())->method('preview');

		$response = $this->makeController([])->preview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testPreviewMissingParamsReturns400()

	/**
	 * REQ-005 scenario: confirm without `confirmed: true` is rejected with
	 * 400 and nothing is dispatched.
	 *
	 * @return void
	 */
	public function testConfirmWithoutConfirmationReturns400(): void {
		$this->promotionService->expects($this->never())->method('promote');

		$controller = $this->makeController(['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance']);
		$response = $controller->confirm();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testConfirmWithoutConfirmationReturns400()

	/**
	 * REQ-005 scenario: confirmed:false is also rejected.
	 *
	 * @return void
	 */
	public function testConfirmWithConfirmedFalseReturns400(): void {
		$this->promotionService->expects($this->never())->method('promote');

		$controller = $this->makeController(
			['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance', 'confirmed' => false]
		);
		$response = $controller->confirm();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testConfirmWithConfirmedFalseReturns400()

	/**
	 * A confirmed promotion delegates to PromotionService::promote() with
	 * the acting user's uid and the default "local" origin slug.
	 *
	 * @return void
	 */
	public function testConfirmedPromotionDelegatesWithDefaultFromSlug(): void {
		$result = ['auditId' => 'audit-1', 'callLogId' => 'calllog-1'];
		$this->promotionService->expects($this->once())
			->method('promote')
			->with(
				configurationId: 'cfg-1',
				targetEnvironmentSlug: 'acceptance',
				credentialBindings: [],
				actorUid: 'tester',
				fromEnvironmentSlug: 'local'
			)
			->willReturn($result);

		$controller = $this->makeController(
			['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance', 'confirmed' => true]
		);
		$response = $controller->confirm();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}//end testConfirmedPromotionDelegatesWithDefaultFromSlug()

	/**
	 * A promotion failure (e.g. target 404) surfaces as an actionable error
	 * response rather than an uncaught exception.
	 *
	 * @return void
	 */
	public function testConfirmedPromotionFailureReturnsBadGateway(): void {
		$this->promotionService->method('promote')
			->willThrowException(new RuntimeException('Target environment call failed with HTTP 404.'));

		$controller = $this->makeController(
			['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance', 'confirmed' => true]
		);
		$response = $controller->confirm();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
	}//end testConfirmedPromotionFailureReturnsBadGateway()

	/**
	 * An unresolvable target environment surfaces as 400.
	 *
	 * @return void
	 */
	public function testConfirmedPromotionUnresolvedTargetReturns400(): void {
		$this->promotionService->method('promote')
			->willThrowException(new InvalidArgumentException("Target environment 'ghost' does not exist."));

		$controller = $this->makeController(
			['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'ghost', 'confirmed' => true]
		);
		$response = $controller->confirm();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testConfirmedPromotionUnresolvedTargetReturns400()

	/**
	 * REQ-005: a user without the environment.promote action is rejected
	 * with 403 on BOTH preview and confirm, before any export or remote call.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-a-user-without-the-environmentpromote-action-permission-cannot-promote
	 *
	 * @return void
	 */
	public function testPromoteDeniedForUnmappedNonAdmin(): void {
		$this->promotionService->expects($this->never())->method('preview');
		$this->promotionService->expects($this->never())->method('promote');

		$controller = $this->makeController(
			['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance', 'confirmed' => true],
			isAdmin: false
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->preview()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->confirm()->getStatus());
	}//end testPromoteDeniedForUnmappedNonAdmin()

	/**
	 * Unauthenticated requests get 401 on both endpoints.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$controller = $this->makeController(authenticated: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->preview()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->confirm()->getStatus());
	}//end testUnauthenticatedReturns401()
}//end class
