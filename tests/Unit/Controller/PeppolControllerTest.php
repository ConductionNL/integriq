<?php

/**
 * Unit tests for PeppolController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/peppol-access-point-connector/tasks.md#task-3
 * @spec openspec/changes/peppol-access-point-connector/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\PeppolController;
use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Peppol participant lookup + signed inbound receive webhook.
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md
 */
class PeppolControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var PeppolTransmissionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $transmissionService;

	/**
	 * @var WebhookGate|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $gate;

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
	 * @var PeppolController
	 */
	private PeppolController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->transmissionService = $this->createMock(PeppolTransmissionService::class);
		$this->gate = $this->createMock(WebhookGate::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new PeppolController(
			'integriq',
			$this->request,
			$this->transmissionService,
			$this->gate,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * An unauthenticated caller gets 401 without reaching the transmission service.
	 *
	 * @return void
	 */
	public function testParticipantsRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = new PeppolController(
			'integriq',
			$this->request,
			$this->transmissionService,
			$this->gate,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

		$this->transmissionService->expects($this->never())->method('isValidPeppolId');

		$response = $this->controller->participants('0192:1234567890');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testParticipantsRequiresAuthentication()

	/**
	 * A malformed peppolId is rejected 400, never reaching the provider — REQ-001.
	 *
	 * @return void
	 */
	public function testParticipantsRejectsMalformedIdWith400(): void {
		$this->transmissionService->method('isValidPeppolId')->willReturn(false);
		$this->transmissionService->expects($this->never())->method('lookupParticipant');

		$response = $this->controller->participants('not-a-peppol-id');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_peppol_id', $response->getData()['error']);

	}//end testParticipantsRejectsMalformedIdWith400()

	/**
	 * A registered participant returns exists=true with its supported doc types.
	 *
	 * @return void
	 */
	public function testParticipantsReturnsLookupResult(): void {
		$this->transmissionService->method('isValidPeppolId')->willReturn(true);
		$this->transmissionService->method('lookupParticipant')
			->willReturn(['exists' => true, 'supportedDocTypes' => ['ubl-invoice-2.1']]);

		$response = $this->controller->participants('0192:1234567890');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['exists']);

	}//end testParticipantsReturnsLookupResult()

	/**
	 * An unreachable Access Point is mapped to a descriptive error, never a 500 — REQ-001.
	 *
	 * @return void
	 */
	public function testParticipantsMapsProviderExceptionToBadGateway(): void {
		$this->transmissionService->method('isValidPeppolId')->willReturn(true);
		$this->transmissionService->method('lookupParticipant')
			->willThrowException(new PeppolProviderException(message: 'AP unreachable'));

		$response = $this->controller->participants('0192:1234567890');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testParticipantsMapsProviderExceptionToBadGateway()





}//end class
