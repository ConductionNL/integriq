<?php

/**
 * Wire-contract tests for SynchronizationsController::deleteLog().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SynchronizationsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * `DELETE /api/synchronizations/logs/{id}` — the wire contract.
 *
 * The endpoint deletes ONE synchronization log. Its contract has three
 * observable arms and each is asserted separately here, because they are the
 * three different things a caller can be told: 200 with the row gone, 404 with
 * nothing touched, and 401 before the action gate is even consulted.
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */
class SynchronizationsControllerDeleteLogTest extends TestCase {

	/**
	 * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var SynchronizationsController
	 */
	private SynchronizationsController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = $this->createMock(OrObjectService::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->actionAuth = $this->createMock(ActionAuthService::class);

		$this->controller = new SynchronizationsController(
			'openconnector',
			$this->createMock(IRequest::class),
			$this->orObjectService,
			$this->createMock(SynchronizationService::class),
			$l,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->userSession,
			$this->actionAuth
		);
	}//end setUp()

	/**
	 * A known log id is deleted and answered with 200.
	 *
	 * The uuid handed to `deleteObject()` is asserted rather than just the
	 * status: a 200 alone is also what a delete of the WRONG row returns.
	 *
	 * @return void
	 */
	public function testDeleteLogRemovesTheResolvedLogAndReturns200(): void {
		$log = ObjectServiceMockBuilder::objectEntity($this, ['message' => 'done'], 'log-uuid-1');
		$this->orObjectService->method('find')->willReturn($log);

		$deleted = null;
		$this->orObjectService->expects($this->once())
			->method('deleteObject')
			->willReturnCallback(
				function (string $uuid) use (&$deleted): bool {
					$deleted = $uuid;
					return true;
				}
			);

		$response = $this->controller->deleteLog(42);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('log-uuid-1', $deleted);
	}//end testDeleteLogRemovesTheResolvedLogAndReturns200()

	/**
	 * An unknown log id answers 404 and deletes nothing.
	 *
	 * `OrObjectService::find()` THROWS on a miss rather than returning null,
	 * so the not-found arm is driven with an exception — a `willReturn(null)`
	 * here would test a shape the service never produces.
	 *
	 * @return void
	 */
	public function testDeleteLogReturns404AndDeletesNothingForAnUnknownId(): void {
		$this->orObjectService->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->orObjectService->expects($this->never())->method('deleteObject');

		$response = $this->controller->deleteLog(999);

		$this->assertSame(404, $response->getStatus());
	}//end testDeleteLogReturns404AndDeletesNothingForAnUnknownId()

	/**
	 * With no session user the endpoint answers 401 before the action gate
	 * is consulted, and never reaches storage.
	 *
	 * @return void
	 */
	public function testDeleteLogReturns401WithoutASessionUser(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->never())->method('requireAction');

		$this->orObjectService->expects($this->never())->method('deleteObject');

		$controller = new SynchronizationsController(
			'openconnector',
			$this->createMock(IRequest::class),
			$this->orObjectService,
			$this->createMock(SynchronizationService::class),
			$l,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$userSession,
			$actionAuth
		);

		$response = $controller->deleteLog(7);

		$this->assertSame(401, $response->getStatus());
	}//end testDeleteLogReturns401WithoutASessionUser()
}//end class
