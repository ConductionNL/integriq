<?php

/**
 * Unit tests for PaymentsController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/live-payment-providers/tasks.md#task-4
 * @spec openspec/changes/live-payment-providers/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\PaymentsController;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for payment creation + the signed inbound receive webhook.
 *
 * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md
 */
class PaymentsControllerTest extends TestCase {

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * @var PaymentIntentService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $paymentIntentService;

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
	 * @var PaymentsController
	 */
	private PaymentsController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->paymentIntentService = $this->createMock(PaymentIntentService::class);
		$this->signatureService = $this->createMock(WebhookSignatureService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->actionAuth = $this->createMock(ActionAuthService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new PaymentsController(
			'integriq',
			$this->request,
			$this->paymentIntentService,
			$this->signatureService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * An unauthenticated caller gets 401 without reaching the payment service — REQ-LPP-001.
	 *
	 * @return void
	 */
	public function testCreateRequiresAuthentication(): void {
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn(null);
		$this->controller = new PaymentsController(
			'integriq',
			$this->request,
			$this->paymentIntentService,
			$this->signatureService,
			$this->userSession,
			$this->actionAuth,
			$this->l,
			$this->logger
		);

		$this->paymentIntentService->expects($this->never())->method('createPayment');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCreateRequiresAuthentication()

	/**
	 * A valid request returns the checkout envelope from the service.
	 *
	 * @return void
	 */
	public function testCreateReturnsCheckoutEnvelope(): void {
		$this->request->method('getParams')->willReturn(
			[
				'amount' => ['value' => '10.00', 'currency' => 'EUR'],
				'description' => 'Invoice INV-1',
				'redirectUrl' => 'https://example.com/return',
				'webhookUrl' => 'https://example.com/webhook',
			]
		);
		$this->paymentIntentService->method('createPayment')->willReturn(
			[
				'paymentIntentId' => 'pi-uuid-1',
				'providerPaymentId' => 'MOCK-PAY-1',
				'paymentStatus' => 'open',
				'checkoutUrl' => 'https://sandbox.payment.example/checkout/MOCK-PAY-1',
				'dormant' => true,
				'extras' => [],
			]
		);

		$response = $this->controller->create();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('MOCK-PAY-1', $response->getData()['providerPaymentId']);

	}//end testCreateReturnsCheckoutEnvelope()

	/**
	 * An invalid request (missing required fields) is mapped to 400, never a 500 — REQ-LPP-001.
	 *
	 * @return void
	 */
	public function testCreateMapsInvalidArgumentExceptionToBadRequest(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->paymentIntentService->method('createPayment')
			->willThrowException(new InvalidArgumentException('amount required'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);

	}//end testCreateMapsInvalidArgumentExceptionToBadRequest()

	/**
	 * An unreachable provider is mapped to a descriptive 502, never a 500 — REQ-LPP-001.
	 *
	 * @return void
	 */
	public function testCreateMapsProviderExceptionToBadGateway(): void {
		$this->request->method('getParams')->willReturn(
			[
				'amount' => ['value' => '10.00', 'currency' => 'EUR'],
				'description' => 'Invoice INV-1',
				'redirectUrl' => 'https://example.com/return',
				'webhookUrl' => 'https://example.com/webhook',
			]
		);
		$this->paymentIntentService->method('createPayment')
			->willThrowException(new PaymentProviderException(message: 'provider unreachable'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testCreateMapsProviderExceptionToBadGateway()

	/**
	 * No payment source configured at all fails the inbound webhook closed (401) — nothing to verify against.
	 *
	 * @return void
	 */
	public function testWebhookWithNoSourceConfiguredReturns401(): void {
		$this->paymentIntentService->method('resolveActiveSource')
			->willThrowException(new PaymentProviderException(message: 'no source'));
		$this->signatureService->expects($this->never())->method('verify');

		$response = $this->controller->webhook();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testWebhookWithNoSourceConfiguredReturns401()

	/**
	 * An unsigned/tampered callback is rejected 401 before any provider call or event — REQ-LPP-003.
	 *
	 * @return void
	 */
	public function testWebhookInvalidSignatureReturns401BeforeAnySideEffect(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->paymentIntentService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(false);

		$this->paymentIntentService->expects($this->never())->method('handleWebhook');

		$response = $this->controller->webhook();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid signature', $response->getData()['error']);

	}//end testWebhookInvalidSignatureReturns401BeforeAnySideEffect()

	/**
	 * A verified webhook only ever forwards the `id` field — a falsely claimed
	 * `status` in the body is never read or forwarded (REQ-LPP-003: the
	 * authoritative status always comes from PaymentProviderInterface::fetchPaymentStatus(),
	 * never the webhook body).
	 *
	 * @return void
	 */
	public function testWebhookVerifiedCallForwardsOnlyIdIgnoringClaimedStatus(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->paymentIntentService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->request->method('getParams')->willReturn(['id' => 'MOCK-PAY-1', 'status' => 'paid']);

		$this->paymentIntentService->expects($this->once())
			->method('handleWebhook')
			->with('MOCK-PAY-1')
			->willReturn(['result' => 'applied', 'outcome' => 'captured']);

		$response = $this->controller->webhook();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testWebhookVerifiedCallForwardsOnlyIdIgnoringClaimedStatus()

	/**
	 * A processing exception after a verified signature never surfaces as a 500 — REQ-LPP-003.
	 *
	 * @return void
	 */
	public function testWebhookNeverCrashesOnProcessingException(): void {
		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['webhookSignature' => ['secret' => 'whsec_test']]]);
		$this->paymentIntentService->method('resolveActiveSource')->willReturn($source);
		$this->signatureService->method('verify')->willReturn(true);
		$this->request->method('getParams')->willReturn(['id' => 'MOCK-PAY-1']);
		$this->paymentIntentService->method('handleWebhook')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->webhook();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['received']);

	}//end testWebhookNeverCrashesOnProcessingException()
}//end class
