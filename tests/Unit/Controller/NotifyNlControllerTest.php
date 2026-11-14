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
use OCA\Integriq\Service\NotifyNlEmailService;
use OCA\Integriq\Service\SmsDispatchService;
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
	 * @var NotifyNlEmailService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $emailService;

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
		$this->gate = $this->createMock(WebhookGate::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->emailService = $this->createMock(NotifyNlEmailService::class);

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
			$this->gate,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger,
			$this->emailService
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
			->with('+31612345678', 'hello', ['templateId' => 'tmpl-1', 'personalisation' => ['name' => 'Jan'], 'category' => 'service'], 'procest', null)
			->willReturn($message);

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('queued', $response->getData()['status']);
		$this->assertSame('sms-uuid-1', $response->getData()['id']);

	}//end testSendReturnsCreatedMessage()

	/**
	 * An opt-out refusal answers 409 with the decision code, and the
	 * category in the request reaches the service.
	 *
	 * @return void
	 */
	public function testSendAnswersConflictWhenTheOptOutListRefuses(): void {
		$this->request->method('getParams')->willReturn(['to' => '0612345678', 'body' => 'hello', 'category' => 'reminder']);
		$this->dispatchService->expects($this->once())
			->method('sendMessage')
			->with('0612345678', 'hello', ['category' => 'reminder'], null, null)
			->willThrowException(new SmsProviderException(message: 'This address opted out (instance).', errorCode: 'opted-out'));

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('opted-out', $response->getData()['error']);

	}//end testSendAnswersConflictWhenTheOptOutListRefuses()

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
	 * Every refusal code answers 409 with that code and the refusal's own
	 * message, and no other code does.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function refusalCodes(): array {
		return [
			'opted-out'             => ['opted-out', Http::STATUS_CONFLICT],
			'no-consent'            => ['no-consent', Http::STATUS_CONFLICT],
			'invalid-address'       => ['invalid-address', Http::STATUS_CONFLICT],
			'authority-unavailable' => ['authority-unavailable', Http::STATUS_CONFLICT],
			'another code'          => ['rate-limited', Http::STATUS_BAD_GATEWAY],
		];

	}//end refusalCodes()

	/**
	 * A refusal code answers 409, any other provider failure 502.
	 *
	 * @param string $code   The provider error code.
	 * @param int    $status The expected status.
	 *
	 * @return void
	 *
	 * @dataProvider refusalCodes
	 */
	public function testSendAnswersEachDecisionCode(string $code, int $status): void {
		$this->request->method('getParams')->willReturn(['to' => '0612345678']);
		$this->dispatchService->method('sendMessage')
			->willThrowException(new SmsProviderException(message: 'refused here', errorCode: $code));

		$response = $this->controller->send();

		$this->assertSame($status, $response->getStatus());
		$expected = ['error' => 'sms_send_failed', 'message' => 'refused here'];
		if ($status === Http::STATUS_CONFLICT) {
			$expected['error'] = $code;
		}

		$this->assertSame($expected, $response->getData());

	}//end testSendAnswersEachDecisionCode()

	/**
	 * send() asks for the `sms.send` action, and a caller refused it never
	 * reaches the dispatch service.
	 *
	 * @return void
	 */
	public function testSendStopsACallerRefusedTheSendAction(): void {
		$this->request->method('getParams')->willReturn(['to' => '0612345678', 'body' => 'hello']);
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'sms.send')
			->willThrowException(new \OCP\AppFramework\OCS\OCSForbiddenException('no'));
		$this->dispatchService->expects($this->never())->method('sendMessage');

		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$this->controller->send();

	}//end testSendStopsACallerRefusedTheSendAction()

	/**
	 * The options carry a trimmed category (blank falls back to `service`),
	 * a string case reference only, and personalisation only when it is a list;
	 * the object uri reaches the service as a string.
	 *
	 * @return void
	 */
	public function testSendPassesTheRequestOptions(): void {
		$this->request->method('getParams')->willReturn(
			[
				'to' => '0612345678',
				'templateId' => 7,
				'personalisation' => 'not a list',
				'category' => '  reminder ',
				'caseRef' => 'ZAAK-1',
				'objectUri' => 42,
			]
		);
		$message = new ObjectEntity();
		$message->setObject(['status' => 'queued']);
		$message->setUuid('sms-uuid-2');
		$this->dispatchService->expects($this->once())->method('sendMessage')
			->with('0612345678', '', ['templateId' => 7, 'category' => 'reminder', 'caseRef' => 'ZAAK-1'], null, '42')
			->willReturn($message);

		$this->assertSame(['status' => 'queued', 'id' => 'sms-uuid-2'], $this->controller->send()->getData());

	}//end testSendPassesTheRequestOptions()

	/**
	 * A blank category and a non-string case reference fall back to `service`
	 * and leave the case reference out.
	 *
	 * @return void
	 */
	public function testSendFallsBackToTheServiceCategory(): void {
		$this->request->method('getParams')->willReturn(['to' => '0612345678', 'category' => '   ', 'caseRef' => 5]);
		$message = new ObjectEntity();
		$message->setObject([]);
		$message->setUuid('sms-uuid-3');
		$this->dispatchService->expects($this->once())->method('sendMessage')
			->with('0612345678', '', ['category' => 'service'], null, null)
			->willReturn($message);

		$this->controller->send();

	}//end testSendFallsBackToTheServiceCategory()

	/**
	 * A named source reaches the dispatch service trimmed; a blank or non-string one is left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function testSendPassesTheNamedSource(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['to' => '0612345678', 'sourceSlug' => ' sms-twee '],
			['to' => '0612345678', 'sourceSlug' => '  '],
			['to' => '0612345678', 'sourceSlug' => ['sms-twee']]
		);
		$message = new ObjectEntity();
		$message->setObject([]);
		$message->setUuid('sms-uuid-4');
		$options = [];
		$this->dispatchService->expects($this->exactly(3))->method('sendMessage')->willReturnCallback(
			function (string $to, string $body, array $sent) use (&$options, $message): ObjectEntity {
				$options[] = $sent;
				return $message;
			}
		);

		$this->controller->send();
		$this->controller->send();
		$this->controller->send();

		$this->assertSame(
			[
				['category' => 'service', 'sourceSlug' => 'sms-twee'],
				['category' => 'service'],
				['category' => 'service'],
			],
			$options
		);

	}//end testSendPassesTheNamedSource()

	/**
	 * A source that is not an enabled SMS source answers 400 naming it, not 502.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-a-caller-picks-the-sms-source-and-the-form-picks-the-provider-req-smsp-003
	 */
	public function testSendAnswersBadRequestForASourceThatIsNotAnSmsSource(): void {
		$this->request->method('getParams')->willReturn(['to' => '0612345678', 'sourceSlug' => 'brp-haalcentraal']);
		$this->dispatchService->method('sendMessage')->willThrowException(
			new SmsProviderException(
				message: '"brp-haalcentraal" is not an enabled SMS source.',
				errorCode: SmsDispatchService::ERROR_UNKNOWN_SOURCE
			)
		);

		$response = $this->controller->send();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'unknown-source', 'message' => '"brp-haalcentraal" is not an enabled SMS source.'],
			$response->getData()
		);

	}//end testSendAnswersBadRequestForASourceThatIsNotAnSmsSource()

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
	 * status() answers 401 to a caller without a session and asks for the
	 * `sms.status` action otherwise.
	 *
	 * @return void
	 */
	public function testStatusGatesTheCaller(): void {
		$anonymous = $this->createMock(IUserSession::class);
		$anonymous->method('getUser')->willReturn(null);
		$this->userSession = $anonymous;
		$this->dispatchService->expects($this->never())->method('pollStatus');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->buildController()->status(id: 'sms-uuid-1')->getStatus());

		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->anything(), 'sms.status')
			->willThrowException(new \OCP\AppFramework\OCS\OCSForbiddenException('no'));
		$this->expectException(\OCP\AppFramework\OCS\OCSForbiddenException::class);
		$this->controller->status(id: 'sms-uuid-1');

	}//end testStatusGatesTheCaller()

	/**
	 * A failed status poll answers the 502 envelope with the provider's message.
	 *
	 * @return void
	 */
	public function testStatusMapsAFailedPollToBadGateway(): void {
		$this->dispatchService->method('pollStatus')->willThrowException(new SmsProviderException(message: 'gateway down'));

		$response = $this->controller->status(id: 'sms-uuid-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['error' => 'sms_status_unavailable', 'message' => 'gateway down'], $response->getData());

	}//end testStatusMapsAFailedPollToBadGateway()

	/**
	 * A signed callback hands its message id, status (default empty) and
	 * detail (as a string) to the dispatch service and answers received.
	 *
	 * @return void
	 */
	public function testInboundRecordsTheStatusCallback(): void {
		$this->identifyAs(params: ['providerMessageId' => 99, 'detail' => 5]);
		$this->dispatchService->expects($this->once())->method('handleStatusCallback')->with('99', '', '5');

		$response = $this->controller->inbound();

		$this->assertSame(['received' => true], $response->getData());

	}//end testInboundRecordsTheStatusCallback()

	/**
	 * A callback without a message id is logged with its keys and still
	 * answers received.
	 *
	 * @return void
	 */
	public function testInboundLogsACallbackWithoutAMessageId(): void {
		$this->identifyAs(params: ['status' => 'delivered']);
		$this->dispatchService->expects($this->never())->method('handleStatusCallback');
		$this->logger->expects($this->once())->method('warning')
			->with('[NotifyNlController] inbound webhook payload missing providerMessageId', ['keys' => ['status']]);

		$this->assertSame(['received' => true], $this->controller->inbound()->getData());

	}//end testInboundLogsACallbackWithoutAMessageId()

	/**
	 * A refused identification is answered as is, and nothing is delivered;
	 * a delivery that throws answers the gate's not-stored response.
	 *
	 * @return void
	 */
	public function testInboundAnswersTheGatesRefusals(): void {
		$refusal = new \OCP\AppFramework\Http\JSONResponse(['error' => 'bad signature'], Http::STATUS_UNAUTHORIZED);
		$this->gate->method('identify')->willReturnOnConsecutiveCalls($refusal, new \OCA\Integriq\Service\Dso\DsoIdentity($this->createMock(IUser::class), 'c-1'));
		$this->request->method('getParams')->willReturn(['providerMessageId' => 'nnl-1']);
		$this->gate->expects($this->once())->method('deliver')->willThrowException(new \RuntimeException('write refused'));
		$notStored = new \OCP\AppFramework\Http\JSONResponse(['error' => 'not stored'], Http::STATUS_SERVICE_UNAVAILABLE);
		$this->gate->expects($this->once())->method('notStored')->with('notifynl-webhook', 'write refused')->willReturn($notStored);

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
	/**
	 * A NotifyNL e-mail runs behind the notifynl.email action and hands the request to the e-mail service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function testSendEmailHandsTheRequestToTheEmailService(): void {
		$this->request->method('getParams')->willReturn(
			[
				'to' => 'jan@example.nl',
				'templateId' => 'ontvangstbevestiging',
				'personalisation' => ['naam' => 'Jan'],
				'sourceApp' => 'dossiq',
				'objectUri' => 'obj-1',
				'category' => 'service',
				'sourceSlug' => 'notifynl',
			]
		);
		$this->actionAuth->expects($this->once())->method('requireAction')->with($this->anything(), 'notifynl.email');
		$this->emailService->expects($this->once())->method('sendEmail')
			->with(
				'jan@example.nl',
				'ontvangstbevestiging',
				['naam' => 'Jan'],
				$this->callback(
					static fn (array $options): bool => $options['sourceSlug'] === 'notifynl'
						&& $options['sourceApp'] === 'dossiq'
						&& $options['objectUri'] === 'obj-1'
						&& $options['category'] === 'service'
				)
			)
			->willReturn(['id' => 'row-1', 'reference' => 'notify-1', 'status' => 'sent']);

		$response = $this->controller->sendEmail();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'row-1', 'reference' => 'notify-1', 'status' => 'sent'], $response->getData());

	}//end testSendEmailHandsTheRequestToTheEmailService()

	/**
	 * The codes an e-mail send answers with.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function emailCodes(): array {
		return [
			'an invalid address' => ['invalid-address', Http::STATUS_BAD_REQUEST],
			'no template' => ['missing-template', Http::STATUS_BAD_REQUEST],
			'no NotifyNL source' => ['unknown-source', Http::STATUS_BAD_REQUEST],
			'opted out' => ['opted-out', Http::STATUS_CONFLICT],
			'NotifyNL failed' => ['', Http::STATUS_BAD_GATEWAY],
		];

	}//end emailCodes()

	/**
	 * Each refusal of an e-mail send has its HTTP status.
	 *
	 * @param string $code   The service's error code.
	 * @param int    $status The expected status.
	 *
	 * @return void
	 *
	 * @dataProvider emailCodes
	 */
	public function testSendEmailAnswersEachRefusal(string $code, int $status): void {
		$this->request->method('getParams')->willReturn(['to' => 'jan@', 'personalisation' => 'not a list']);
		$this->emailService->expects($this->once())->method('sendEmail')
			->with('jan@', '', [], $this->anything())
			->willThrowException(new SmsProviderException(message: 'refused', errorCode: $code));

		$response = $this->controller->sendEmail();

		$this->assertSame($status, $response->getStatus());

	}//end testSendEmailAnswersEachRefusal()

	/**
	 * Without a session the e-mail endpoint answers 401 and sends nothing.
	 *
	 * @return void
	 */
	public function testSendEmailRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = $this->buildController();
		$this->emailService->expects($this->never())->method('sendEmail');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->sendEmail()->getStatus());

	}//end testSendEmailRequiresAuthentication()

	/**
	 * A callback whose id is no text message goes to the e-mail log with its reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-notifynl-sends-e-mail-and-the-send-is-logged-per-recipient-req-smsp-005
	 */
	public function testInboundFallsBackToTheEmailLog(): void {
		$this->identifyAs(params: ['providerMessageId' => 'notify-1', 'status' => 'delivered', 'reference' => 'row-1']);
		$this->dispatchService->expects($this->once())->method('handleStatusCallback')->willReturn(null);
		$this->emailService->expects($this->once())->method('applyCallback')->with('row-1', 'notify-1', 'delivered', null)->willReturn(true);

		$this->assertSame(['received' => true], $this->controller->inbound()->getData());

	}//end testInboundFallsBackToTheEmailLog()

	/**
	 * A callback for a text message never reaches the e-mail log.
	 *
	 * @return void
	 */
	public function testInboundForATextMessageLeavesTheEmailLogAlone(): void {
		$this->identifyAs(params: ['providerMessageId' => 'sms-1', 'status' => 'delivered', 'reference' => 'row-1']);
		$this->dispatchService->method('handleStatusCallback')->willReturn(new ObjectEntity());
		$this->emailService->expects($this->never())->method('applyCallback');

		$this->controller->inbound();

	}//end testInboundForATextMessageLeavesTheEmailLogAlone()
}//end class
