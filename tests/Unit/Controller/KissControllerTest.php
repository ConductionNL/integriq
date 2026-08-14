<?php

/**
 * Unit tests for KissController.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kiss-kcc-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\KissController;
use OCA\OpenConnector\Exception\KissProviderException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\KissSyncService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the KISS push (createKlantcontact) endpoint.
 *
 * @spec openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-push-endpoint-registering-a-klantcontact-and-linking-a-case
 */
class KissControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var KissSyncService|\PHPUnit\Framework\MockObject\MockObject
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
	 * @var KissController
	 */
	private KissController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->syncService = $this->createMock(KissSyncService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = $this->buildController();

	}//end setUp()

	/**
	 * Build a controller instance wired to the current mocks.
	 *
	 * @return KissController
	 */
	private function buildController(): KissController {
		return new KissController(
			'openconnector',
			$this->request,
			$this->syncService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * An unauthenticated caller gets 401 without reaching the sync service.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->syncService->expects($this->never())->method('pushCustomerContact');

		$response = $this->controller->createCustomerContact();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCreateKlantcontactRequiresAuthentication()

	/**
	 * A missing required field (`onderwerp`/`kanaal`) is rejected with 400 before the sync service is called.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactRequiresOnderwerpAndKanaal(): void {
		$this->request->method('getParams')->willReturn(['onderwerp' => 'Vraag']);

		$this->syncService->expects($this->never())->method('pushCustomerContact');

		$response = $this->controller->createCustomerContact();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_fields', $response->getData()['error']);

	}//end testCreateKlantcontactRequiresOnderwerpAndKanaal()

	/**
	 * A valid push request returns the sync service's result verbatim.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactReturnsResult(): void {
		$this->request->method('getParams')->willReturn(
			[
				'onderwerp' => 'Melding openbare ruimte',
				'channel' => 'telefoon',
				'caseReference' => 'case-uuid-1',
				'sourceApp' => 'procest',
			]
		);

		$this->syncService->expects($this->once())
			->method('pushCustomerContact')
			->willReturn(['id' => 'kiss-id-1', 'localUuid' => 'local-uuid-1']);

		$response = $this->controller->createCustomerContact();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'kiss-id-1', 'localUuid' => 'local-uuid-1'], $response->getData());

	}//end testCreateKlantcontactReturnsResult()

	/**
	 * When no KISS source is configured, the endpoint reports a clean 503 `not_configured` — never a 500 crash.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactReportsNotConfiguredCleanly(): void {
		$this->request->method('getParams')->willReturn(['onderwerp' => 'Vraag', 'channel' => 'telefoon']);

		$this->syncService->method('pushCustomerContact')->willThrowException(
			new KissProviderException(message: 'No active KISS source is configured (register "openconnector", schema "source", type "kiss", isEnabled=true). Configure one before using the KISS bridge.')
		);

		$response = $this->controller->createCustomerContact();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('not_configured', $response->getData()['error']);

	}//end testCreateKlantcontactReportsNotConfiguredCleanly()

	/**
	 * A generic KISS provider failure (source configured, but KISS itself errors) maps to 502.
	 *
	 * @return void
	 */
	public function testCreateKlantcontactMapsProviderFailureTo502(): void {
		$this->request->method('getParams')->willReturn(['onderwerp' => 'Vraag', 'channel' => 'telefoon']);

		$this->syncService->method('pushCustomerContact')->willThrowException(
			new KissProviderException(message: 'KISS responded with HTTP 503.')
		);

		$response = $this->controller->createCustomerContact();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('kiss_push_failed', $response->getData()['error']);

	}//end testCreateKlantcontactMapsProviderFailureTo502()
}//end class
