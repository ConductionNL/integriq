<?php

/**
 * The log list, statistics and export refuse the same way before they read anything.
 *
 * Pins, for LogsController::index(), statistics() and export(): 401 with
 * "Not authenticated" and no action check when nobody is logged in, the exact
 * action each one demands, 403 with the refusal's message, and no read in
 * either case, so the shared gate can move without changing any outcome.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\LogsController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the LogsController gate.
 */
final class LogsControllerGateGoldenTest extends TestCase {

	/**
	 * Each gated route and the action it demands.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function routes(): array {
		return [
			'index' => ['index', 'log.index'],
			'statistics' => ['statistics', 'log.statistics'],
			'export' => ['export', 'log.export'],
		];

	}//end routes()

	/**
	 * Call a route on a controller with this session user and action check.
	 *
	 * @param string                     $route      The route.
	 * @param IUser|null                 $user       The session user.
	 * @param ActionAuthServiceInterface $actionAuth The action check.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function call(string $route, ?IUser $user, ActionAuthServiceInterface $actionAuth): \OCP\AppFramework\Http\JSONResponse {
		$objects = $this->createMock(OrObjectService::class);
		$objects->expects($this->never())->method('findAll');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new LogsController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			orObjectService: $objects,
			l: $l10n,
			userSession: $session,
			actionAuth: $actionAuth
		);

		return $controller->$route();

	}//end call()

	/**
	 * Nobody logged in is answered 401 before any action check.
	 *
	 * @param string $route  The route.
	 * @param string $action The action it demands.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testNobodyIsAnswered401(string $route, string $action): void {
		$actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$actionAuth->expects($this->never())->method('requireAction');

		$response = $this->call(route: $route, user: null, actionAuth: $actionAuth);

		$this->assertSame([401, ['error' => 'Not authenticated']], [$response->getStatus(), $response->getData()]);
		$this->assertNotSame('', $action);

	}//end testNobodyIsAnswered401()

	/**
	 * A refused action is answered 403 with its message, after asking for exactly that action.
	 *
	 * @param string $route  The route.
	 * @param string $action The action it demands.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testARefusedActionIsAnswered403(string $route, string $action): void {
		$user = $this->createMock(IUser::class);
		$actionAuth = $this->createMock(ActionAuthServiceInterface::class);
		$actionAuth->expects($this->once())->method('requireAction')->with($user, $action)->willThrowException(new OCSForbiddenException('not for you'));

		$response = $this->call(route: $route, user: $user, actionAuth: $actionAuth);

		$this->assertSame([403, ['error' => 'not for you']], [$response->getStatus(), $response->getData()]);

	}//end testARefusedActionIsAnswered403()
}//end class
