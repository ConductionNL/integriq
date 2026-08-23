<?php

/**
 * Unit tests for NotifyNlController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/notifynl-sms-channel/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\NotifyNlController;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the NotifyNL send + status-poll + signed inbound callback endpoints.
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md
 */
class NotifyNlControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var SmsDispatchService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $dispatchService;

	/**
	 * @var WebhookSignatureService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $signatureService;

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
	 * @var NotifyNlController
	 */
	private NotifyNlController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->dispatchService = $this->createMock(SmsDispatchService::class);
		$this->signatureService = $this->createMock(WebhookSignatureService::class);
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
	 * @return NotifyNlController
	 */
	private function buildController(): NotifyNlController {
		return new NotifyNlController(
			'integriq',
			$this->request,
			$this->dispatchService,
			$this->signatureService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end buildController()

	/**
	 * An unauthenticated caller gets 401 without reaching the dispatch service — send().
	 *
	 * @return void
	 */
	public function testSendRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();

		$this->dispatchService->expects($this->never())->method('sendMessage');

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSendRequiresAuthentication()

	/**
	 * A missing `to` field is rejected 400, never reaching the dispatch service.
	 *
	 * @return void
	 */
	public function testSendRejectsMissingRecipientWith400(): void {
		$this->request->method('getParams')->willReturn(['body' => 'hello']);
		$this->dispatchService->expects($this->never())->method('sendMessage');

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testSendRejectsMissingRecipientWith400()

	/**
	 * A well-formed request is dispatched and the created message is returned.
	 *
	 * @return void
	 */
	public function testSendReturnsCreatedMessage(): void {
		$this->request->method('getParams')->willReturn(
			[
				'to' => '+31612345678',
				'body' => 'hello',
				'templateId' => 'tmpl-1',
				'personalisation' => ['name' => 'Jan'],
				'sourceApp' => 'procest',
			]
		);

		$message = new ObjectEntity();
		$message->setObject(['status' => 'queued', 'providerMessageId' => 'MOCK-SMS-1']);
		$message->setUuid('sms-uuid-1');

		$this->dispatchService->expects($this->once())
			->method('sendMessage')
			->with('+31612345678', 'hello', ['templateId' => 'tmpl-1', 'personalisation' => ['name' => 'Jan']], 'procest', null)
			->willReturn($message);

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('queued', $response->getData()['status']);
		$this->assertSame('sms-uuid-1', $response->getData()['id']);

	}//end testSendReturnsCreatedMessage()

	/**
	 * A dispatch-service failure is mapped to a 502 error envelope, never a crash.
	 *
	 * @return void
	 */
	public function testSendMapsProviderExceptionToBadGateway(): void {
		$this->request->method('getParams')->willReturn(['to' => '+31612345678', 'body' => 'hello']);
		$this->dispatchService->method('sendMessage')->willThrowException(new SmsProviderException(message: 'gateway down'));

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testSendMapsProviderExceptionToBadGateway()

	/**
	 * status() rejects an empty id with 400, never reaching the dispatch service.
	 *
	 * @return void
	 */
	public function testStatusRejectsMissingIdWith400(): void {
		$this->dispatchService->expects($this->never())->method('pollStatus');

		$response = $this->controller->status('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testStatusRejectsMissingIdWith400()

	/**
	 * status() returns the polled message on success.
	 *
	 * @return void
	 */
	public function testStatusReturnsPolledMessage(): void {
		$message = new ObjectEntity();
		$message->setObject(['status' => 'delivered']);
		$message->setUuid('sms-uuid-1');

		$this->dispatchService->method('pollStatus')->with('sms-uuid-1')->willReturn($message);

		$response = $this->controller->status('sms-uuid-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('delivered', $response->getData()['status']);

	}//end testStatusReturnsPolledMessage()

	/**
	 * No SMS source configured at all fails the inbound webhook closed (401) — nothing to verify against.
	 *
	 * @return void
	 */
	public function testInboundWithNoSourceConfiguredReturns401(): void {
		$this->dispatchService->method('resolveActiveSource')
			->willThrowException(new SmsProviderException(message: 'no source'));
		$this->signatureService->expects($this->never())->method('verify');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testInboundWithNoSourceConfiguredReturns401()

	/**
	 * An unsigned/tampered callback is rejected 401 before any state change.
	 *
	 * @return void
	 */
	public function testInboundInvalidSignatureReturns401BeforeAnySideEffect(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->dispatchService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(false);

		$this->dispatchService->expects($this->never())->method('handleStatusCallback');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testInboundInvalidSignatureReturns401BeforeAnySideEffect()

	/**
	 * A verified callback is routed to handleStatusCallback with the payload fields.
	 *
	 * @return void
	 */
	public function testInboundVerifiedCallbackIsRouted(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->dispatchService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['providerMessageId' => 'notify-id-1', 'status' => 'delivered', 'detail' => 'Accepted']
		);

		$this->dispatchService->expects($this->once())
			->method('handleStatusCallback')
			->with('notify-id-1', 'delivered', 'Accepted');

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testInboundVerifiedCallbackIsRouted()

	/**
	 * inbound() never crashes (still returns 200) when downstream processing throws.
	 *
	 * @return void
	 */
	public function testInboundNeverCrashesOnProcessingException(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->dispatchService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->request->method('getParams')->willReturn(['providerMessageId' => 'notify-id-1', 'status' => 'delivered']);

		$this->dispatchService->method('handleStatusCallback')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->inbound();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testInboundNeverCrashesOnProcessingException()
}//end class
