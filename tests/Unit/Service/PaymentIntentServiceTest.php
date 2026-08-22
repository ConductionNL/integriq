<?php

/**
 * Unit tests for PaymentIntentService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/live-payment-providers/tasks.md#task-4
 * @spec openspec/changes/live-payment-providers/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for payment creation + the verified-webhook status lifecycle.
 *
 * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md
 */
class PaymentIntentServiceTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var LogPaymentProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logProvider;

	/**
	 * @var MolliePaymentProvider|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mollieProvider;

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var PaymentIntentService
	 */
	private PaymentIntentService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->logProvider = $this->createMock(LogPaymentProvider::class);
		$this->mollieProvider = $this->createMock(MolliePaymentProvider::class);
		$this->eventService = $this->createMock(EventService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new PaymentIntentService(
			$this->objectService,
			$this->logProvider,
			$this->mollieProvider,
			$this->eventService,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * Build a real ObjectEntity for a given data payload (magic getters need the real Entity path).
	 *
	 * @param array $data The object data.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'uuid-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * The valid create-payment payload used by every "happy path" test.
	 *
	 * @return array
	 */
	private function payload(): array {
		return [
			'amount' => ['value' => '10.00', 'currency' => 'EUR'],
			'description' => 'Invoice INV-1',
			'redirectUrl' => 'https://example.com/return',
			'webhookUrl' => 'https://example.com/webhook',
			'method' => 'ideal',
		];

	}//end payload()

	/**
	 * createPayment persists a payment_intent and returns the checkout envelope — REQ-LPP-001.
	 *
	 * @return void
	 */
	public function testCreatePaymentHappyPathPersistsAndReturnsCheckoutEnvelope(): void {
		$source = $this->entity(['slug' => 'payment-sandbox', 'type' => 'payment', 'configuration' => ['provider' => 'log']]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);

		$this->logProvider->method('createPayment')->willReturn(
			[
				'providerPaymentId' => 'MOCK-PAY-1',
				'paymentStatus' => 'open',
				'checkoutUrl' => 'https://sandbox.payment.example/checkout/MOCK-PAY-1',
				'extras' => ['method' => 'ideal'],
			]
		);

		$saved = $this->entity(['providerPaymentId' => 'MOCK-PAY-1'], 'pi-uuid-1');
		$this->objectService->expects($this->once())->method('saveObject')->willReturn($saved);

		$result = $this->service->createPayment(payload: $this->payload());

		$this->assertSame('pi-uuid-1', $result['paymentIntentId']);
		$this->assertSame('MOCK-PAY-1', $result['providerPaymentId']);
		$this->assertSame('open', $result['paymentStatus']);
		$this->assertSame('https://sandbox.payment.example/checkout/MOCK-PAY-1', $result['checkoutUrl']);
		$this->assertTrue($result['dormant']);

	}//end testCreatePaymentHappyPathPersistsAndReturnsCheckoutEnvelope()

	/**
	 * A request missing amount.currency is rejected before any provider/persistence call — REQ-LPP-001.
	 *
	 * @return void
	 */
	public function testCreatePaymentMissingAmountCurrencyThrows(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->logProvider->expects($this->never())->method('createPayment');

		$this->expectException(InvalidArgumentException::class);

		$this->service->createPayment(
			payload: [
				'amount' => ['value' => '10.00'],
				'description' => 'Invoice INV-1',
				'redirectUrl' => 'https://example.com/return',
				'webhookUrl' => 'https://example.com/webhook',
			]
		);

	}//end testCreatePaymentMissingAmountCurrencyThrows()

	/**
	 * A request missing description is rejected — REQ-LPP-001.
	 *
	 * @return void
	 */
	public function testCreatePaymentMissingDescriptionThrows(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service->createPayment(
			payload: [
				'amount' => ['value' => '10.00', 'currency' => 'EUR'],
				'redirectUrl' => 'https://example.com/return',
				'webhookUrl' => 'https://example.com/webhook',
			]
		);

	}//end testCreatePaymentMissingDescriptionThrows()

	/**
	 * createPayment throws a descriptive error when no active payment source exists.
	 *
	 * @return void
	 */
	public function testCreatePaymentThrowsWhenNoSourceConfigured(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->expectException(PaymentProviderException::class);

		$this->service->createPayment(payload: $this->payload());

	}//end testCreatePaymentThrowsWhenNoSourceConfigured()

	/**
	 * A webhook for an unknown providerPaymentId is recorded, not-found, no event — never a crash.
	 *
	 * @return void
	 */
	public function testHandleWebhookUnknownPaymentIdIsNotFoundNoEvent(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->handleWebhook(providerPaymentId: 'tr_unknown');

		$this->assertSame('not-found', $result['result']);

	}//end testHandleWebhookUnknownPaymentIdIsNotFoundNoEvent()

	/**
	 * An empty providerPaymentId (no `id` in the body) is not-found, no event — never a crash.
	 *
	 * @return void
	 */
	public function testHandleWebhookEmptyPaymentIdIsNotFoundNoEvent(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->handleWebhook(providerPaymentId: '');

		$this->assertSame('not-found', $result['result']);

	}//end testHandleWebhookEmptyPaymentIdIsNotFoundNoEvent()

	/**
	 * A re-fetched "paid" status maps to "captured" and emits one status event
	 * shaped for PaymentReconciliationService::reconcile() — REQ-LPP-004.
	 *
	 * @return void
	 */
	public function testHandleWebhookCapturedStatusEmitsShapedEvent(): void {
		$record = $this->entity(
			['sourceSlug' => 'payment-sandbox', 'providerPaymentId' => 'MOCK-PAY-1', 'paymentStatus' => 'open', 'lastOutcome' => null],
			'pi-uuid-1'
		);
		$source = $this->entity(['slug' => 'payment-sandbox', 'type' => 'payment', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($record, $source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				return ['results' => [$record], 'total' => 1];
			}
		);

		$this->logProvider->method('fetchPaymentStatus')->willReturn(
			['providerPaymentId' => 'MOCK-PAY-1', 'paymentStatus' => 'paid']
		);

		$this->objectService->expects($this->once())->method('saveObject')->willReturn($record);

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				PaymentIntentService::EVENT_TYPE_STATUS,
				$this->anything(),
				'MOCK-PAY-1',
				$this->callback(
					static fn (array $data) => $data['paymentIntentId'] === 'MOCK-PAY-1'
						&& $data['outcome'] === 'captured'
						&& $data['settlementReference'] === 'MOCK-PAY-1'
				)
			);

		$result = $this->service->handleWebhook(providerPaymentId: 'MOCK-PAY-1');

		$this->assertSame('applied', $result['result']);
		$this->assertSame('captured', $result['outcome']);

	}//end testHandleWebhookCapturedStatusEmitsShapedEvent()

	/**
	 * An unmapped provider-native status ("open") is a no-op — no state change beyond
	 * the persisted native status, no event — REQ-LPP-004.
	 *
	 * @return void
	 */
	public function testHandleWebhookUnmappedStatusIsNoopNoEvent(): void {
		$record = $this->entity(
			['sourceSlug' => 'payment-sandbox', 'providerPaymentId' => 'MOCK-PAY-2', 'paymentStatus' => 'open', 'lastOutcome' => null],
			'pi-uuid-2'
		);
		$source = $this->entity(['slug' => 'payment-sandbox', 'type' => 'payment', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($record, $source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				return ['results' => [$record], 'total' => 1];
			}
		);

		$this->logProvider->method('fetchPaymentStatus')->willReturn(
			['providerPaymentId' => 'MOCK-PAY-2', 'paymentStatus' => 'open']
		);

		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->handleWebhook(providerPaymentId: 'MOCK-PAY-2');

		$this->assertSame('noop', $result['result']);
		$this->assertNull($result['outcome']);

	}//end testHandleWebhookUnmappedStatusIsNoopNoEvent()

	/**
	 * A replayed webhook whose re-fetched status maps to the SAME outcome already
	 * applied does not emit a second event (idempotency guard) — REQ-LPP-005.
	 *
	 * @return void
	 */
	public function testHandleWebhookReplayForAlreadyCapturedOutcomeDoesNotDoubleEmit(): void {
		$record = $this->entity(
			['sourceSlug' => 'payment-sandbox', 'providerPaymentId' => 'MOCK-PAY-1', 'paymentStatus' => 'paid', 'lastOutcome' => 'captured'],
			'pi-uuid-1'
		);
		$source = $this->entity(['slug' => 'payment-sandbox', 'type' => 'payment', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($record, $source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				return ['results' => [$record], 'total' => 1];
			}
		);

		$this->logProvider->method('fetchPaymentStatus')->willReturn(
			['providerPaymentId' => 'MOCK-PAY-1', 'paymentStatus' => 'paid']
		);

		$this->objectService->expects($this->once())->method('saveObject')->willReturn($record);
		$this->eventService->expects($this->never())->method('emitCloudEvent');

		$result = $this->service->handleWebhook(providerPaymentId: 'MOCK-PAY-1');

		$this->assertSame('noop', $result['result']);
		$this->assertSame('captured', $result['outcome']);

	}//end testHandleWebhookReplayForAlreadyCapturedOutcomeDoesNotDoubleEmit()

	/**
	 * A failed status maps to "failed" and carries the exact default error text
	 * shillinq's own webhook path already produces (wire-level consistency).
	 *
	 * @return void
	 */
	public function testHandleWebhookFailedStatusCarriesDefaultErrorMessage(): void {
		$record = $this->entity(
			['sourceSlug' => 'payment-sandbox', 'providerPaymentId' => 'MOCK-PAY-4', 'paymentStatus' => 'open', 'lastOutcome' => null],
			'pi-uuid-4'
		);
		$source = $this->entity(['slug' => 'payment-sandbox', 'type' => 'payment', 'configuration' => ['provider' => 'log']]);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($record, $source) {
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? null) === 'source') {
					return ['results' => [$source], 'total' => 1];
				}

				return ['results' => [$record], 'total' => 1];
			}
		);

		$this->logProvider->method('fetchPaymentStatus')->willReturn(
			['providerPaymentId' => 'MOCK-PAY-4', 'paymentStatus' => 'failed']
		);

		$this->objectService->method('saveObject')->willReturn($record);

		$this->eventService->expects($this->once())
			->method('emitCloudEvent')
			->with(
				PaymentIntentService::EVENT_TYPE_STATUS,
				$this->anything(),
				'MOCK-PAY-4',
				$this->callback(
					static fn (array $data) => $data['outcome'] === 'failed'
						&& $data['errorMessage'] === 'Payment failed at gateway.'
				)
			);

		$result = $this->service->handleWebhook(providerPaymentId: 'MOCK-PAY-4');

		$this->assertSame('applied', $result['result']);
		$this->assertSame('failed', $result['outcome']);

	}//end testHandleWebhookFailedStatusCarriesDefaultErrorMessage()
}//end class
