<?php

/**
 * Unit tests for StufZknController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\StufZknController;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\StufZknSyncService;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the StUF-ZKN inbound SOAP endpoint (signature gate, Bv03 reply on success) and the
 * authenticated outbound push endpoint.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md
 */
class StufZknControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var StufZknSyncService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $syncService;

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
	 * @var StufZknController
	 */
	private StufZknController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->syncService = $this->createMock(StufZknSyncService::class);
		$this->gate = $this->createMock(WebhookGate::class);
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
	 * @return StufZknController
	 */
	private function buildController(): StufZknController {
		return new StufZknController(
			'integriq',
			$this->request,
			$this->syncService,
			$this->gate,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()




	/**
	 * An unauthenticated caller gets 401 on the outbound push endpoint without reaching the
	 * sync service.
	 *
	 * @return void
	 */
	public function testOutboundRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->syncService->expects($this->never())->method('sendNotification');

		$response = $this->controller->outbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testOutboundRequiresAuthentication()

	/**
	 * A missing required field (`zaak`/`verwerkingssoort`) is rejected with 400.
	 *
	 * @return void
	 */
	public function testOutboundRequiresZaakAndVerwerkingssoort(): void {
		$this->request->method('getParams')->willReturn(['zaak' => ['identificatie' => 'X']]);

		$this->syncService->expects($this->never())->method('sendNotification');

		$response = $this->controller->outbound();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('missing_fields', $response->getData()['error']);

	}//end testOutboundRequiresZaakAndVerwerkingssoort()

	/**
	 * A valid push request returns the sync service's result verbatim.
	 *
	 * @return void
	 */
	public function testOutboundReturnsResult(): void {
		$this->request->method('getParams')->willReturn(
			['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
		);

		$this->syncService->expects($this->once())
			->method('sendNotification')
			->willReturn(['referentienummer' => 'ZKN-abc', 'ref' => 'ack-1']);

		$response = $this->controller->outbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['referentienummer' => 'ZKN-abc', 'ref' => 'ack-1'], $response->getData());

	}//end testOutboundReturnsResult()

	/**
	 * A StufZknTranslationException (incomplete zaak) maps to 400 `invalid_kennisgeving`.
	 *
	 * @return void
	 */
	public function testOutboundMapsTranslationExceptionTo400(): void {
		$this->request->method('getParams')->willReturn(
			['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
		);

		$this->syncService->method('sendNotification')->willThrowException(
			new StufZknTranslationException(message: 'Required field "omschrijving" is missing or empty.')
		);

		$response = $this->controller->outbound();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_kennisgeving', $response->getData()['error']);

	}//end testOutboundMapsTranslationExceptionTo400()

	/**
	 * When no stuf-zkn source is configured, the endpoint reports a clean 503 `not_configured`.
	 *
	 * @return void
	 */
	public function testOutboundReportsNotConfiguredCleanly(): void {
		$this->request->method('getParams')->willReturn(
			['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
		);

		$this->syncService->method('sendNotification')->willThrowException(
			new StufZknProviderException(message: 'No active StUF-ZKN source is configured (register "openconnector", schema "source", type "stuf-zkn", isEnabled=true). Configure one before using the StUF-ZKN bridge.')
		);

		$response = $this->controller->outbound();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('not_configured', $response->getData()['error']);

	}//end testOutboundReportsNotConfiguredCleanly()

	/**
	 * A generic transport failure (source configured, but transport itself errors) maps to 502.
	 *
	 * @return void
	 */
	public function testOutboundMapsProviderFailureTo502(): void {
		$this->request->method('getParams')->willReturn(
			['zaak' => ['identificatie' => 'ZAAK-1'], 'verwerkingssoort' => 'T']
		);

		$this->syncService->method('sendNotification')->willThrowException(
			new StufZknProviderException(message: 'StUF-ZKN consumer endpoint responded with HTTP 503.')
		);

		$response = $this->controller->outbound();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('stuf_zkn_send_failed', $response->getData()['error']);

	}//end testOutboundMapsProviderFailureTo502()

	/**
	 * outbound() asks for the `stuf-zkn.push` action, and a caller refused it
	 * never reaches the sync service.
	 *
	 * @return void
	 */
	public function testOutboundStopsACallerRefusedThePushAction(): void {
		$this->request->method('getParams')->willReturn(['zaak' => ['id' => 'z-1'], 'verwerkingssoort' => 'T']);
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'stuf-zkn.push')
			->willThrowException(new \OCP\AppFramework\OCS\OCSForbiddenException('no'));
		$this->syncService->expects($this->never())->method('sendNotification');

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$this->controller->outbound();

	}//end testOutboundStopsACallerRefusedThePushAction()

	/**
	 * A missing zaak is refused like a missing verwerkingssoort, and both reach
	 * the sync service once there.
	 *
	 * @return void
	 */
	public function testOutboundNeedsBothFieldsAndPassesThem(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['verwerkingssoort' => 'T'],
			['zaak' => ['id' => 'z-1'], 'verwerkingssoort' => 'W']
		);
		$this->syncService->expects($this->once())->method('sendNotification')
			->with(['id' => 'z-1'], 'W')->willReturn(['ref' => 'r']);

		$this->assertSame(
			['error' => 'missing_fields', 'message' => 'The "zaak" and "verwerkingssoort" fields are required'],
			$this->controller->outbound()->getData()
		);
		$this->assertSame(['ref' => 'r'], $this->controller->outbound()->getData());

	}//end testOutboundNeedsBothFieldsAndPassesThem()

	/**
	 * The failure envelopes carry the exception's message, and only a
	 * provider failure is logged.
	 *
	 * @return void
	 */
	public function testOutboundFailureEnvelopes(): void {
		$this->request->method('getParams')->willReturn(['zaak' => ['id' => 'z-1'], 'verwerkingssoort' => 'T']);
		$this->syncService->method('sendNotification')->willReturnOnConsecutiveCalls(
			$this->throwException(new \OCA\Integriq\Exception\StufZknTranslationException(message: 'bad zaak')),
			$this->throwException(new \OCA\Integriq\Exception\StufZknProviderException(message: 'No active StUF-ZKN source here')),
			$this->throwException(new \OCA\Integriq\Exception\StufZknProviderException(message: 'HTTP 500'))
		);
		$this->logger->expects($this->exactly(2))->method('warning');

		$first = $this->controller->outbound();
		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'invalid_kennisgeving', 'message' => 'bad zaak']], [$first->getStatus(), $first->getData()]);
		$second = $this->controller->outbound();
		$this->assertSame([Http::STATUS_SERVICE_UNAVAILABLE, ['error' => 'not_configured', 'message' => 'No active StUF-ZKN source here']], [$second->getStatus(), $second->getData()]);
		$third = $this->controller->outbound();
		$this->assertSame([Http::STATUS_BAD_GATEWAY, ['error' => 'stuf_zkn_send_failed', 'message' => 'HTTP 500']], [$third->getStatus(), $third->getData()]);

	}//end testOutboundFailureEnvelopes()

	/**
	 * A kennisgeving is answered with the sync service's reply as XML, run as
	 * the identified account; a refused write answers the gate's not-stored
	 * response and a refused identification is answered as is.
	 *
	 * @return void
	 */
	public function testInboundAnswersTheReplyOrTheGatesRefusal(): void {
		$refusal = new \OCP\AppFramework\Http\JSONResponse(['error' => 'bad signature'], Http::STATUS_UNAUTHORIZED);
		$identity = new \OCA\Integriq\Service\Dso\DsoIdentity($this->createMock(IUser::class), 'c-1');
		$this->gate->method('identify')->willReturnOnConsecutiveCalls($refusal, $identity, $identity);
		$this->syncService->method('receiveInbound')->with('')->willReturn('<Bv03/>');
		$this->gate->expects($this->exactly(2))->method('deliver')->willReturnOnConsecutiveCalls(
			$this->returnCallback(fn ($who, callable $operation) => $operation()),
			$this->throwException(new \RuntimeException('write refused'))
		);
		$notStored = new \OCP\AppFramework\Http\JSONResponse(['error' => 'not stored'], Http::STATUS_SERVICE_UNAVAILABLE);
		$this->gate->expects($this->once())->method('notStored')->with('stuf-zkn-webhook', 'write refused')->willReturn($notStored);

		$this->assertSame($refusal, $this->controller->inbound());
		$reply = $this->controller->inbound();
		$this->assertInstanceOf(\OCP\AppFramework\Http\DataDisplayResponse::class, $reply);
		$this->assertSame(['<Bv03/>', Http::STATUS_OK], [$reply->getData(), $reply->getStatus()]);
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($reply);
		$this->assertSame('text/xml; charset=utf-8', $headers['Content-Type']);
		$this->assertSame($notStored, $this->controller->inbound());

	}//end testInboundAnswersTheReplyOrTheGatesRefusal()
}//end class
