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

	/**
	 * participants() asks for the `peppol.lookup` action, and a caller refused
	 * it never reaches the lookup.
	 *
	 * @return void
	 */
	public function testParticipantsStopsACallerRefusedTheLookupAction(): void {
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'peppol.lookup')
			->willThrowException(new \OCP\AppFramework\OCS\OCSForbiddenException('no'));
		$this->transmissionService->expects($this->never())->method('lookupParticipant');

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$this->controller->participants('0192:1234567890');

	}//end testParticipantsStopsACallerRefusedTheLookupAction()

	/**
	 * An unknown participant answers the lookup result as is and logs it;
	 * a failed lookup logs a warning and answers the exact 502 envelope.
	 *
	 * @return void
	 */
	public function testParticipantsLogsAnUnknownParticipantAndAFailedLookup(): void {
		$this->transmissionService->method('isValidPeppolId')->willReturn(true);
		$this->transmissionService->method('lookupParticipant')->willReturnOnConsecutiveCalls(
			['exists' => false, 'supportedDocTypes' => []],
			$this->throwException(new PeppolProviderException(message: 'AP unreachable'))
		);
		$this->logger->expects($this->once())->method('info')
			->with('Peppol participant not found', ['peppolId' => '0192:1']);
		$this->logger->expects($this->once())->method('warning')
			->with('[PeppolController] participant lookup failed: AP unreachable', ['peppolId' => '0192:2']);

		$this->assertSame(['exists' => false, 'supportedDocTypes' => []], $this->controller->participants('0192:1')->getData());
		$this->assertSame(
			['error' => 'peppol_unavailable', 'message' => 'AP unreachable'],
			$this->controller->participants('0192:2')->getData()
		);

	}//end testParticipantsLogsAnUnknownParticipantAndAFailedLookup()

	/**
	 * A delivery callback wins over an inbound-document shape and passes its
	 * status (default empty) and detail (as a string).
	 *
	 * @return void
	 */
	public function testInboundRecordsADeliveryCallback(): void {
		$this->identifyAs(params: ['transmissionId' => 7, 'detail' => 3, 'senderPeppolId' => '0192:1']);
		$this->transmissionService->expects($this->once())->method('handleDeliveryCallback')->with('7', '', '3');
		$this->transmissionService->expects($this->never())->method('handleInboundDocument');

		$this->assertSame(['received' => true], $this->controller->inbound()->getData());

	}//end testInboundRecordsADeliveryCallback()

	/**
	 * An inbound document passes its sender, and an empty type and reference
	 * when it carries none.
	 *
	 * @return void
	 */
	public function testInboundRecordsAnInboundDocument(): void {
		$this->identifyAs(params: ['senderPeppolId' => '0192:1']);
		$this->transmissionService->expects($this->once())->method('handleInboundDocument')->with('0192:1', '', '');
		$this->transmissionService->expects($this->never())->method('handleDeliveryCallback');

		$this->assertSame(['received' => true], $this->controller->inbound()->getData());

	}//end testInboundRecordsAnInboundDocument()

	/**
	 * A payload of neither shape is logged with its keys and still answers received.
	 *
	 * @return void
	 */
	public function testInboundLogsAnUnknownShape(): void {
		$this->identifyAs(params: ['status' => 'x']);
		$this->transmissionService->expects($this->never())->method('handleDeliveryCallback');
		$this->transmissionService->expects($this->never())->method('handleInboundDocument');
		$this->logger->expects($this->once())->method('warning')
			->with('[PeppolController] inbound webhook payload matched neither known shape', ['keys' => ['status']]);

		$this->assertSame(['received' => true], $this->controller->inbound()->getData());

	}//end testInboundLogsAnUnknownShape()

	/**
	 * A refused identification is answered as is, and a delivery that throws
	 * answers the gate's not-stored response.
	 *
	 * @return void
	 */
	public function testInboundAnswersTheGatesRefusals(): void {
		$refusal = new JSONResponse(['error' => 'bad signature'], Http::STATUS_UNAUTHORIZED);
		$this->gate->method('identify')->willReturnOnConsecutiveCalls($refusal, new \OCA\Integriq\Service\Dso\DsoIdentity($this->createMock(IUser::class), 'c-1'));
		$this->request->method('getParams')->willReturn(['transmissionId' => 't-1']);
		$this->gate->expects($this->once())->method('deliver')->willThrowException(new \RuntimeException('write refused'));
		$notStored = new JSONResponse(['error' => 'not stored'], Http::STATUS_SERVICE_UNAVAILABLE);
		$this->gate->expects($this->once())->method('notStored')->with('peppol-webhook', 'write refused')->willReturn($notStored);

		$this->assertSame($refusal, $this->controller->inbound());
		$this->assertSame($notStored, $this->controller->inbound());

	}//end testInboundAnswersTheGatesRefusals()

	/**
	 * Let the gate identify the caller, run the delivery and see these params.
	 *
	 * @param array<string,mixed> $params The callback's params.
	 *
	 * @return void
	 */
	private function identifyAs(array $params): void {
		$this->gate->method('identify')->willReturn(new \OCA\Integriq\Service\Dso\DsoIdentity($this->createMock(IUser::class), 'c-1'));
		$this->gate->method('deliver')->willReturnCallback(fn ($identity, callable $operation) => $operation());
		$this->request->method('getParams')->willReturn($params);

	}//end identifyAs()
}//end class
