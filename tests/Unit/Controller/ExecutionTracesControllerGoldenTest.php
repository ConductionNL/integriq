<?php

/**
 * Golden tests for ExecutionTracesController: the gate on every route, the
 * listing's filters and pagination, and the replay's force flag.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ExecutionTracesController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\ExecutionTraceServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the allowed and refused outcomes of the three trace routes.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007
 */
class ExecutionTracesControllerGoldenTest extends TestCase {

	/**
	 * @var ExecutionTraceServiceInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $traces;

	/**
	 * Build the controller.
	 *
	 * @param bool                $user      Whether a user is logged in.
	 * @param string|null         $forbidden An action the gate refuses.
	 * @param array<string,mixed> $params    Request parameters.
	 *
	 * @return ExecutionTracesController
	 */
	private function controller(bool $user = true, ?string $forbidden = null, array $params = []): ExecutionTracesController {
		$who = $this->createMock(IUser::class);
		$who->method('getUID')->willReturn('tester');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $who : null);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$auth = $this->createMock(ActionAuthServiceInterface::class);
		$auth->method('requireAction')->willReturnCallback(
			static function (IUser $caller, string $action) use ($forbidden): void {
				if ($action === $forbidden) {
					throw new OCSForbiddenException('refused ' . $action);
				}
			}
		);
		$this->traces = $this->createMock(ExecutionTraceServiceInterface::class);

		return new ExecutionTracesController('integriq', $request, $this->traces, $l, $session, $auth);

	}//end controller()

	/**
	 * Each route, its call and its action.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function routes(): array {
		return [
			'index' => ['index', 'execution-trace.index'],
			'show' => ['show', 'execution-trace.show'],
			'replay' => ['replay', 'execution-trace.replay'],
		];

	}//end routes()

	/**
	 * Call a route.
	 *
	 * @param ExecutionTracesController $controller The controller.
	 * @param string                    $route      The route.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function call(ExecutionTracesController $controller, string $route) {
		if ($route === 'index') {
			return $controller->index();
		}

		return $controller->$route('t-1');

	}//end call()

	/**
	 * Without a user every route is 401 and the trace service is untouched.
	 *
	 * @param string $route  The route.
	 * @param string $action The action.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testAnonymousIsRefused(string $route, string $action): void {
		$controller = $this->controller(false);
		$this->traces->expects($this->never())->method($this->anything());

		$response = $this->call($controller, $route);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testAnonymousIsRefused()

	/**
	 * A refused action is 403 with the gate's message and the trace service is untouched.
	 *
	 * @param string $route  The route.
	 * @param string $action The action.
	 *
	 * @return void
	 */
	#[DataProvider('routes')]
	public function testRefusedActionIsForbidden(string $route, string $action): void {
		$controller = $this->controller(true, $action);
		$this->traces->expects($this->never())->method($this->anything());

		$response = $this->call($controller, $route);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'refused ' . $action], $response->getData());

	}//end testRefusedActionIsForbidden()

	/**
	 * Each listing request, the filters passed on and the pagination answered.
	 *
	 * @return array<string,array{0:array<int,mixed>,1:array<string,string>,2:int,3:int,4:array<string,int|float>}>
	 */
	public static function listings(): array {
		return [
			'defaults' => [[], [], 45, 2, ['page' => 1.0, 'pages' => 3.0, 'results' => 2, 'total' => 45]],
			'all filters, second page' => [
				[10, 10, 'job', 'failed', '2026-01-01', '2026-02-01'],
				['entryPoint' => 'job', 'status' => 'failed', 'date_from' => '2026-01-01', 'date_to' => '2026-02-01'],
				25,
				1,
				['page' => 2.0, 'pages' => 3.0, 'results' => 1, 'total' => 25],
			],
			'some filters' => [[5, 7, null, 'success', null, '2026-02-01'], ['status' => 'success', 'date_to' => '2026-02-01'], 0, 0, ['page' => 2.0, 'pages' => 0.0, 'results' => 0, 'total' => 0]],
			'limit zero' => [[0, 40], [], 9, 3, ['page' => 1, 'pages' => 1, 'results' => 3, 'total' => 9]],
			'limit null' => [[null, null], [], 9, 1, ['page' => 1, 'pages' => 1, 'results' => 1, 'total' => 9]],
		];

	}//end listings()

	/**
	 * The listing passes the non-null filters and answers the pagination as before.
	 *
	 * @param array<int,mixed>       $args       The index arguments.
	 * @param array<string,string>   $filters    The filters passed on.
	 * @param int                    $total      The total the service reports.
	 * @param int                    $count      How many results it returns.
	 * @param array<string,int|float> $pagination The pagination answered.
	 *
	 * @return void
	 */
	#[DataProvider('listings')]
	public function testListing(array $args, array $filters, int $total, int $count, array $pagination): void {
		$controller = $this->controller();
		$results = array_fill(0, $count, ['id' => 'x']);
		$limit = array_key_exists(0, $args) === true ? $args[0] : 20;
		$offset = array_key_exists(1, $args) === true ? $args[1] : 0;
		$this->traces->expects($this->once())->method('list')->with($filters, $limit, $offset)
			->willReturn(['results' => $results, 'total' => $total]);

		$response = $controller->index(...$args);

		$this->assertSame(['results' => $results, 'pagination' => $pagination], $response->getData());

	}//end testListing()

	/**
	 * The replay passes the caller and the parsed force flag.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool}>
	 */
	public static function forces(): array {
		return [
			'absent' => [[], false],
			'true string' => [['force' => 'true'], true],
			'yes' => [['force' => 'yes'], true],
			'zero' => [['force' => '0'], false],
			'bool' => [['force' => true], true],
		];

	}//end forces()

	/**
	 * A replay runs with the caller's uid and the force flag as parsed before.
	 *
	 * @param array<string,mixed> $params The request parameters.
	 * @param bool                $force  The parsed flag.
	 *
	 * @return void
	 */
	#[DataProvider('forces')]
	public function testReplayForce(array $params, bool $force): void {
		$controller = $this->controller(true, null, $params);
		$replayed = new ObjectEntity();
		$replayed->setObject(['id' => 'r-1']);
		$this->traces->expects($this->once())->method('replay')->with('t-1', 'tester', $force)->willReturn($replayed);

		$this->assertSame(['id' => 'r-1'], $controller->replay('t-1')->getData());

	}//end testReplayForce()
}//end class
