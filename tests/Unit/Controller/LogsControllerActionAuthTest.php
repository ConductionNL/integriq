<?php

/**
 * Unit tests for LogsController's ADR-023 action-authorization guard.
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
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\LogsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Every one of these endpoints was `#[NoAdminRequired]` with an
 * authentication check and no authorization check, over a schema that declares
 * no `authorization` block — so any authenticated account could read and
 * DELETE any other account's synchronization log. Reproduced live on a
 * two-account rig before the fix: `ocidorb GET /api/logs/{id}` → **200** with
 * `ocidora`'s payload, and `ocidorb DELETE /api/logs/{id}` → **200 "Log
 * deleted successfully"**, after which the owner's own read returned 404.
 *
 * Each test below pairs the refusal with the permitted case over the SAME
 * collaborators, so it cannot pass by the controller refusing everybody —
 * which is the failure mode an absence-only assertion cannot see.
 */
class LogsControllerActionAuthTest extends TestCase {

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->orObjectService = $this->createMock(OrObjectService::class);

	}//end setUp()

	/**
	 * Construct the controller with a session user of the given privilege.
	 *
	 * The action-authorization collaborator is the REAL ActionAuthService over
	 * a mocked IAppConfig and IGroupManager. An empty matrix (`{}`) is what a
	 * fresh install has before an admin broadens it, and it resolves every
	 * action to `["admin"]` — so a non-admin is refused by the shipped default,
	 * not by anything this test arranges.
	 *
	 * @param boolean $isAdmin Whether the caller passes the admin break-glass.
	 *
	 * @return LogsController
	 */
	private function controller(bool $isAdmin): LogsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('tester');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{}');

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('getUserGroupIds')->willReturn([]);

		return new LogsController(
			'openconnector',
			$this->createMock(IRequest::class),
			$this->orObjectService,
			$l,
			$userSession,
			new ActionAuthService($appConfig, $groupManager)
		);

	}//end controller()

	/**
	 * An ObjectEntity that stands in for somebody else's log.
	 *
	 * @return ObjectEntity|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function someoneElsesLog() {
		$log = $this->createMock(ObjectEntity::class);
		$log->method('getObject')->willReturn(['id' => 'log-1', 'message' => 'not yours']);
		$log->method('getUuid')->willReturn('uuid-1');
		return $log;
	}//end someoneElsesLog()

	/**
	 * show() refuses an unauthorized caller with 403 and never reads the log.
	 *
	 * @return void
	 */
	public function testShowRefusesAnUnauthorizedCallerWithoutReading(): void {
		$this->orObjectService->expects($this->never())->method('find');

		$response = $this->controller(isAdmin: false)->show('log-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testShowRefusesAnUnauthorizedCallerWithoutReading()

	/**
	 * The control: an authorized caller still gets the log. Without this the
	 * test above would pass over a controller that refuses everybody.
	 *
	 * @return void
	 */
	public function testShowStillServesAnAuthorizedCaller(): void {
		$this->orObjectService->method('find')->willReturn($this->someoneElsesLog());

		$response = $this->controller(isAdmin: true)->show('log-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'log-1', 'message' => 'not yours'], $response->getData());

	}//end testShowStillServesAnAuthorizedCaller()

	/**
	 * destroy() refuses an unauthorized caller with 403 and deletes nothing.
	 *
	 * This is the endpoint that answered 200 to a stranger on the live rig.
	 *
	 * @return void
	 */
	public function testDestroyRefusesAnUnauthorizedCallerAndDeletesNothing(): void {
		$this->orObjectService->expects($this->never())->method('find');
		$this->orObjectService->expects($this->never())->method('deleteObject');

		$response = $this->controller(isAdmin: false)->destroy('log-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testDestroyRefusesAnUnauthorizedCallerAndDeletesNothing()

	/**
	 * The control: an authorized caller still deletes.
	 *
	 * @return void
	 */
	public function testDestroyStillDeletesForAnAuthorizedCaller(): void {
		$this->orObjectService->method('find')->willReturn($this->someoneElsesLog());
		$this->orObjectService->expects($this->once())->method('deleteObject')->with('uuid-1');

		$response = $this->controller(isAdmin: true)->destroy('log-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testDestroyStillDeletesForAnAuthorizedCaller()

	/**
	 * index() refuses an unauthorized caller with 403 and never queries.
	 *
	 * @return void
	 */
	public function testIndexRefusesAnUnauthorizedCallerWithoutQuerying(): void {
		$this->orObjectService->expects($this->never())->method('findAll');

		$response = $this->controller(isAdmin: false)->index();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testIndexRefusesAnUnauthorizedCallerWithoutQuerying()

	/**
	 * The control: an authorized caller still gets the list.
	 *
	 * @return void
	 */
	public function testIndexStillListsForAnAuthorizedCaller(): void {
		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$this->someoneElsesLog()], 'total' => 1]);

		$response = $this->controller(isAdmin: true)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['pagination']['total']);

	}//end testIndexStillListsForAnAuthorizedCaller()

	/**
	 * statistics() refuses with 403, NOT with the 500 its own catch(\Exception)
	 * would have produced had the guard been placed inside that block.
	 *
	 * @return void
	 */
	public function testStatisticsRefusesWith403AndNotWith500(): void {
		$this->orObjectService->expects($this->never())->method('findAll');

		$response = $this->controller(isAdmin: false)->statistics();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertNotSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

	}//end testStatisticsRefusesWith403AndNotWith500()

	/**
	 * The control: an authorized caller still gets statistics.
	 *
	 * @return void
	 */
	public function testStatisticsStillAnswersAnAuthorizedCaller(): void {
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$response = $this->controller(isAdmin: true)->statistics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('levelDistribution', $response->getData());

	}//end testStatisticsStillAnswersAnAuthorizedCaller()

	/**
	 * export() refuses with 403, and does not leak a CSV carrying `userId` and
	 * `sessionId` per row.
	 *
	 * @return void
	 */
	public function testExportRefusesWith403AndNotWith500(): void {
		$this->orObjectService->expects($this->never())->method('findAll');

		$response = $this->controller(isAdmin: false)->export();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertArrayNotHasKey('content', $response->getData());

	}//end testExportRefusesWith403AndNotWith500()

	/**
	 * The control: an authorized caller still gets the CSV.
	 *
	 * @return void
	 */
	public function testExportStillProducesCsvForAnAuthorizedCaller(): void {
		$this->orObjectService->method('findAll')
			->willReturn(['results' => [$this->someoneElsesLog()], 'total' => 1]);

		$response = $this->controller(isAdmin: true)->export();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('UUID,Level,Message', $response->getData()['content']);

	}//end testExportStillProducesCsvForAnAuthorizedCaller()
}//end class
