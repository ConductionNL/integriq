<?php

/**
 * Unit tests for CardfeedController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/corporate-card-feed/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\CardfeedController;
use OCA\Integriq\Exception\CardfeedProviderException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\CardfeedSyncService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the corporate-card enroll + discovery endpoint.
 *
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002
 */
class CardfeedControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var CardfeedSyncService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $syncService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $actionAuth;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var CardfeedController
	 */
	private CardfeedController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->syncService = $this->createMock(CardfeedSyncService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new CardfeedController(
			'openconnector',
			$this->request,
			$this->syncService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * A successful enroll returns the account summary and gates on the enroll action — REQ-002.
	 *
	 * @return void
	 */
	public function testEnrollReturnsAccountSummary(): void {
		$this->actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'cardfeed.enroll');

		$this->syncService->method('enrollSource')->willReturn(
			[
				'accountId' => 'acct-1',
				'cardfeedSourceSlug' => 'card-provider-sandbox',
				'cards' => [['cardId' => 'SANDBOX-CARD-1', 'last4' => '4242', 'cardholderName' => 'A', 'currency' => 'EUR']],
				'lifecycleState' => 'active',
			]
		);

		$response = $this->controller->enroll(sourceSlug: 'card-provider-sandbox');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('acct-1', $data['accountId']);
		$this->assertSame('active', $data['lifecycleState']);

	}//end testEnrollReturnsAccountSummary()

	/**
	 * A missing sourceSlug is a 400 and never reaches the service.
	 *
	 * @return void
	 */
	public function testEnrollRejectsMissingSourceSlug(): void {
		$this->syncService->expects($this->never())->method('enrollSource');

		$response = $this->controller->enroll(sourceSlug: '');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testEnrollRejectsMissingSourceSlug()

	/**
	 * A provider failure is a 502 error envelope, never a crash.
	 *
	 * @return void
	 */
	public function testEnrollMapsProviderFailureToBadGateway(): void {
		$this->syncService->method('enrollSource')
			->willThrowException(new CardfeedProviderException(message: 'card provider unreachable'));

		$response = $this->controller->enroll(sourceSlug: 'card-provider-sandbox');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('cardfeed_enroll_failed', $response->getData()['error']);

	}//end testEnrollMapsProviderFailureToBadGateway()

	/**
	 * An anonymous session is a 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testEnrollRequiresAuthentication(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new CardfeedController(
			'openconnector',
			$this->request,
			$this->syncService,
			$session,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

		$this->syncService->expects($this->never())->method('enrollSource');

		$response = $controller->enroll(sourceSlug: 'card-provider-sandbox');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testEnrollRequiresAuthentication()
}//end class
