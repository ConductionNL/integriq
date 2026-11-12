<?php

/**
 * Unit tests for PromotionController (environments-and-promotion).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\PromotionController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\PromotionService;
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
			'integriq',
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

	/**
	 * Preview requests and what the preview endpoint answers and hands on.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:mixed,2:int,3:array<string,mixed>,4:array<int,mixed>|null}>
	 */
	public static function previewRequests(): array {
		$target = ['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance'];
		$missing = ['error' => 'Request must carry "configurationId" and "targetEnvironmentSlug"'];
		return [
			'bindings passed through'   => [$target + ['credentialBindings' => [['ref' => 'a']]], ['ok' => 1], 200, ['ok' => 1], [['ref' => 'a']]],
			'non-list bindings dropped' => [$target + ['credentialBindings' => 'x'], ['ok' => 1], 200, ['ok' => 1], []],
			'unknown target'            => [$target, new InvalidArgumentException('no target'), 400, ['error' => 'no target'], []],
			'remote failure'            => [$target, new RuntimeException('HTTP 500'), 502, ['error' => 'Promotion preview failed: HTTP 500'], []],
			'missing configuration'     => [['targetEnvironmentSlug' => 'acceptance'], null, 400, $missing, null],
			'blank target'              => [['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => ''], null, 400, $missing, null],
			'non-string configuration'  => [['configurationId' => 5, 'targetEnvironmentSlug' => 'acceptance'], null, 400, $missing, null],
		];

	}//end previewRequests()

	/**
	 * The preview endpoint's answer for each request.
	 *
	 * @param array<string,mixed>   $params   The request params.
	 * @param mixed                 $outcome  What the service returns or throws (null: not called).
	 * @param int                   $status   The expected status.
	 * @param array<string,mixed>   $data     The expected body.
	 * @param array<int,mixed>|null $bindings The bindings the service sees (null: not called).
	 *
	 * @return void
	 *
	 * @dataProvider previewRequests
	 */
	public function testPreviewAnswers(array $params, mixed $outcome, int $status, array $data, ?array $bindings): void {
		$mock = $this->promotionService->expects($bindings === null ? $this->never() : $this->once())->method('preview');
		if ($bindings !== null) {
			$mock->with(configurationId: 'cfg-1', targetEnvironmentSlug: 'acceptance', credentialBindings: $bindings);
			$outcome instanceof \Throwable ? $mock->willThrowException($outcome) : $mock->willReturn($outcome);
		}

		$response = $this->makeController($params)->preview();

		$this->assertSame([$status, $data], [$response->getStatus(), $response->getData()]);

	}//end testPreviewAnswers()

	/**
	 * Confirm requests and what the confirm endpoint answers and hands on.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:mixed,2:int,3:array<string,mixed>,4:string|null}>
	 */
	public static function confirmRequests(): array {
		$target = ['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance', 'credentialBindings' => [['ref' => 'a']]];
		$unconfirmed = ['error' => 'Promotion requires explicit confirmation — preview first, then send confirmed: true'];
		return [
			'confirmed as a string'     => [$target + ['confirmed' => 'true'], ['ok' => 1], 200, ['ok' => 1], 'local'],
			'named origin'              => [$target + ['confirmed' => true, 'fromEnvironmentSlug' => 'test'], ['ok' => 1], 200, ['ok' => 1], 'test'],
			'blank origin'              => [$target + ['confirmed' => true, 'fromEnvironmentSlug' => ''], ['ok' => 1], 200, ['ok' => 1], 'local'],
			'non-string origin'         => [$target + ['confirmed' => true, 'fromEnvironmentSlug' => 3], ['ok' => 1], 200, ['ok' => 1], 'local'],
			'remote failure'            => [$target + ['confirmed' => true], new RuntimeException('HTTP 500'), 502, ['error' => 'Promotion failed: HTTP 500'], 'local'],
			'unknown target'            => [$target + ['confirmed' => true], new InvalidArgumentException('no target'), 400, ['error' => 'no target'], 'local'],
			'confirmed as 1'            => [$target + ['confirmed' => '1'], null, 400, $unconfirmed, null],
			'confirmed but no target'   => [['configurationId' => 'cfg-1', 'confirmed' => true], null, 400, ['error' => 'Request must carry "configurationId" and "targetEnvironmentSlug"'], null],
		];

	}//end confirmRequests()

	/**
	 * The confirm endpoint's answer for each request.
	 *
	 * @param array<string,mixed> $params  The request params.
	 * @param mixed               $outcome What the service returns or throws (null: not called).
	 * @param int                 $status  The expected status.
	 * @param array<string,mixed> $data    The expected body.
	 * @param string|null         $origin  The origin slug the service sees (null: not called).
	 *
	 * @return void
	 *
	 * @dataProvider confirmRequests
	 */
	public function testConfirmAnswers(array $params, mixed $outcome, int $status, array $data, ?string $origin): void {
		$mock = $this->promotionService->expects($origin === null ? $this->never() : $this->once())->method('promote');
		if ($origin !== null) {
			$mock->with(
				configurationId: 'cfg-1',
				targetEnvironmentSlug: 'acceptance',
				credentialBindings: [['ref' => 'a']],
				actorUid: 'tester',
				fromEnvironmentSlug: $origin
			);
			$outcome instanceof \Throwable ? $mock->willThrowException($outcome) : $mock->willReturn($outcome);
		}

		$response = $this->makeController($params)->confirm();

		$this->assertSame([$status, $data], [$response->getStatus(), $response->getData()]);

	}//end testConfirmAnswers()

	/**
	 * A refused caller's 403 carries the action service's refusal message.
	 *
	 * @return void
	 */
	public function testARefusedPromoterHearsWhy(): void {
		$controller = $this->makeController(['configurationId' => 'cfg-1', 'targetEnvironmentSlug' => 'acceptance', 'confirmed' => true], isAdmin: false);

		$preview = $controller->preview()->getData();
		$this->assertNotSame('', (string)($preview['error'] ?? ''));
		$this->assertSame($preview, $controller->confirm()->getData());

	}//end testARefusedPromoterHearsWhy()
}//end class
