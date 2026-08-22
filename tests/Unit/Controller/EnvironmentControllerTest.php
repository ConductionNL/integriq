<?php

/**
 * Unit tests for EnvironmentController (environments-and-promotion).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\EnvironmentController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\EnvironmentService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests environment list/create auth gating and delegation to EnvironmentService.
 */
class EnvironmentControllerTest extends TestCase {
	/**
	 * @var EnvironmentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $environmentService;

	/**
	 * Set up shared fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->environmentService = $this->createMock(EnvironmentService::class);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @param array<string,mixed> $requestParams Values the mocked IRequest returns per param name.
	 * @param bool $isAdmin Whether the session user passes the admin break-glass.
	 * @param bool $authenticated Whether a session user exists at all.
	 *
	 * @return EnvironmentController
	 */
	private function makeController(array $requestParams = [], bool $isAdmin = true, bool $authenticated = true): EnvironmentController {
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
		$request->method('getParams')->willReturn($requestParams);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []) => vsprintf(str_replace('%s', '%s', $text), $params)
		);

		return new EnvironmentController(
			'openconnector',
			$request,
			$this->environmentService,
			$l10n,
			$userSession,
			$actionAuth
		);
	}//end makeController()

	/**
	 * REQ-001: index() returns the service's list.
	 *
	 * @return void
	 */
	public function testIndexReturnsEnvironmentList(): void {
		$environment = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'Acceptance', 'slug' => 'acceptance'],
			'env-uuid'
		);
		$this->environmentService->method('list')->willReturn([$environment]);

		$response = $this->makeController()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame('acceptance', $data['results'][0]['slug']);
	}//end testIndexReturnsEnvironmentList()

	/**
	 * REQ-001 scenario 1: create() delegates to the service and returns 201.
	 *
	 * @return void
	 */
	public function testCreateReturns201OnSuccess(): void {
		$created = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'Acceptance', 'slug' => 'acceptance', 'sourceRef' => 'source-uuid'],
			'env-uuid'
		);
		$this->environmentService->expects($this->once())->method('create')->willReturn($created);

		$controller = $this->makeController(['name' => 'Acceptance', 'slug' => 'acceptance', 'sourceRef' => 'source-uuid']);
		$response = $controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateReturns201OnSuccess()

	/**
	 * REQ-001 scenario 2: an invalid sourceRef surfaces as 400.
	 *
	 * @return void
	 */
	public function testCreateReturns400OnInvalidSourceRef(): void {
		$this->environmentService->method('create')
			->willThrowException(new InvalidArgumentException("Environment sourceRef 'ghost' does not resolve to an existing Source object."));

		$controller = $this->makeController(['name' => 'Acceptance', 'sourceRef' => 'ghost']);
		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateReturns400OnInvalidSourceRef()

	/**
	 * REQ-001: a non-admin without environment.manage is rejected with 403
	 * on both endpoints.
	 *
	 * @return void
	 */
	public function testEnvironmentEndpointsDeniedForUnmappedNonAdmin(): void {
		$this->environmentService->expects($this->never())->method('list');
		$this->environmentService->expects($this->never())->method('create');

		$controller = $this->makeController(isAdmin: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->create()->getStatus());
	}//end testEnvironmentEndpointsDeniedForUnmappedNonAdmin()

	/**
	 * Unauthenticated requests get 401 on both endpoints.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$controller = $this->makeController(authenticated: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->create()->getStatus());
	}//end testUnauthenticatedReturns401()
}//end class
