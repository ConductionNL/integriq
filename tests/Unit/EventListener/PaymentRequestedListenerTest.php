<?php

/**
 * PaymentRequestedListener unit tests
 *
 * The typed payment command (REQ-LPP-007, REQ-LPP-009) end to end through the
 * real event class, the real PaymentIntentService and the real
 * LogPaymentProvider, with only OpenRegister and the URL generator mocked:
 * the result slot, integriq's own webhook URL, the provenance on the stored
 * payment intent, and every refusal code without an exception reaching the
 * caller.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\PaymentRequestedEvent;
use OCA\Integriq\EventListener\PaymentRequestedListener;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\PaymentIntentService;
use OCA\Integriq\Service\PaymentIntentServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for the payment command listener.
 */
class PaymentRequestedListenerTest extends TestCase {

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var \PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * The Mollie binding mock.
	 *
	 * @var MolliePaymentProvider&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mollie;

	/**
	 * What the service saved, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The subject.
	 *
	 * @var PaymentRequestedListener
	 */
	private PaymentRequestedListener $listener;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('pi-uuid-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);
		$this->mollie = $this->createMock(MolliePaymentProvider::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnMap([
			['integriq.payments.webhook', [], 'https://cloud.example/apps/integriq/api/payments/webhook'],
		]);

		$service = new PaymentIntentService(
			$this->objectService,
			new LogPaymentProvider(),
			$this->mollie,
			$this->createMock(EventService::class),
			$l,
			new NullLogger()
		);
		$this->listener = new PaymentRequestedListener(payments: $service, urlGenerator: $urls, logger: new NullLogger());
	}//end setUp()

	/**
	 * A configured payment source on the given provider.
	 *
	 * @param string $provider The provider.
	 *
	 * @return void
	 */
	private function givenSource(string $provider): void {
		$source = new ObjectEntity();
		$source->setObject(['slug' => $provider . '-live', 'type' => 'payment', 'configuration' => ['provider' => $provider]]);
		$this->objectService->method('findAll')->willReturn(['results' => [$source], 'total' => 1]);
	}//end givenSource()

	/**
	 * The invoice command from the spec scenario.
	 *
	 * @param string|null $method     The method.
	 * @param string|null $terminalId The terminal.
	 * @param string      $amount     The amount.
	 *
	 * @return PaymentRequestedEvent
	 */
	private function command(?string $method=null, ?string $terminalId=null, string $amount='125.00'): PaymentRequestedEvent {
		return new PaymentRequestedEvent(
			sourceApp: 'shillinq',
			subjectRef: 'invoice:2026-0142',
			amountValue: $amount,
			amountCurrency: 'EUR',
			description: 'Factuur 2026-0142',
			redirectUrl: 'https://portal.example/thanks',
			method: $method,
			terminalId: $terminalId,
			metadata: ['administrationId' => 'ADM-1', 'sourceApp' => 'spoofed'],
			correlationId: 'corr-1'
		);
	}//end command()

	/**
	 * A Mollie payment for an invoice: the result slot is filled, Mollie gets
	 * integriq's own webhook URL, and the stored intent names shillinq.
	 *
	 * @return void
	 */
	public function testAMolliePaymentFillsTheResultSlot(): void {
		$this->givenSource('mollie');
		$this->mollie->expects($this->once())->method('createPayment')
			->with(
				['provider' => 'mollie'],
				$this->callback(
					fn (array $payload): bool => $payload['webhookUrl'] === 'https://cloud.example/apps/integriq/api/payments/webhook'
						&& $payload['amount'] === ['value' => '125.00', 'currency' => 'EUR']
						&& $payload['description'] === 'Factuur 2026-0142'
						&& $payload['redirectUrl'] === 'https://portal.example/thanks'
						&& isset($payload['method']) === false
				)
			)
			->willReturn(['providerPaymentId' => 'tr_7UhSN1zuXS', 'paymentStatus' => 'open', 'checkoutUrl' => 'https://www.mollie.com/checkout/tr_7UhSN1zuXS', 'extras' => []]);

		$event = $this->command();
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getRefusal());
		$this->assertSame(
			['paymentIntentId' => 'pi-uuid-1', 'providerPaymentId' => 'tr_7UhSN1zuXS', 'checkoutUrl' => 'https://www.mollie.com/checkout/tr_7UhSN1zuXS', 'paymentStatus' => 'open', 'dormant' => false],
			$event->getResult()
		);
		$this->assertSame(
			['administrationId' => 'ADM-1', 'sourceApp' => 'shillinq', 'subjectRef' => 'invoice:2026-0142', 'correlationId' => 'corr-1'],
			$this->saved[0]['metadata'],
			'Provenance is integriq\'s, never the caller\'s metadata.'
		);
		$this->assertSame('mollie-live', $this->saved[0]['sourceSlug']);
	}//end testAMolliePaymentFillsTheResultSlot()

	/**
	 * The log sandbox creates a dormant payment through the real binding.
	 *
	 * @return void
	 */
	public function testTheLogSandboxAnswersADormantPayment(): void {
		$this->givenSource('log');

		$event = $this->command(method: 'ideal');
		$this->listener->handle($event);

		$result = $event->getResult();
		$this->assertNotNull($result);
		$this->assertTrue($result['dormant']);
		$this->assertStringStartsWith('MOCK-PAY-', $result['providerPaymentId']);
		$this->assertSame('https://sandbox.payment.example/checkout/' . $result['providerPaymentId'], $result['checkoutUrl']);
		$this->assertSame('ideal', $this->saved[0]['method']);
	}//end testTheLogSandboxAnswersADormantPayment()

	/**
	 * No payment source: refusal `no-source`, nothing thrown, nothing stored.
	 *
	 * @return void
	 */
	public function testNoPaymentSourceIsRefusedNoSource(): void {
		$this->objectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$event = $this->command();
		$this->listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getResult());
		$this->assertSame('no-source', $event->getRefusal()['code']);
		$this->assertStringContainsString('No active payment source is configured', $event->getRefusal()['reason']);
		$this->assertSame([], $this->saved);
	}//end testNoPaymentSourceIsRefusedNoSource()

	/**
	 * Point of sale on the log provider: refusal `unsupported`, so the caller
	 * can show the checkout as a QR code instead.
	 *
	 * @return void
	 */
	public function testPointOfSaleOnTheLogProviderIsUnsupported(): void {
		$this->givenSource('log');

		$event = $this->command(method: 'pointofsale', terminalId: 'term_example0001', amount: '23.50');
		$this->listener->handle($event);

		$this->assertNull($event->getResult());
		$this->assertSame('unsupported', $event->getRefusal()['code']);
		$this->assertSame([], $this->saved);
	}//end testPointOfSaleOnTheLogProviderIsUnsupported()

	/**
	 * A point-of-sale payment on Mollie: sent to the terminal, no checkout URL,
	 * and the stored intent keeps the terminal.
	 *
	 * @return void
	 */
	public function testAPointOfSalePaymentGoesToTheTerminal(): void {
		$this->givenSource('mollie');
		$this->mollie->expects($this->once())->method('createPayment')
			->with($this->anything(), $this->callback(fn (array $payload): bool => $payload['method'] === 'pointofsale' && $payload['terminalId'] === 'term_example0001'))
			->willReturn(['providerPaymentId' => 'tr_pos0001', 'paymentStatus' => 'open', 'checkoutUrl' => '', 'extras' => []]);

		$event = $this->command(method: 'pointofsale', terminalId: 'term_example0001', amount: '23.50');
		$this->listener->handle($event);

		$this->assertSame('tr_pos0001', $event->getResult()['providerPaymentId']);
		$this->assertSame('', $event->getResult()['checkoutUrl']);
		$this->assertSame('term_example0001', $this->saved[0]['terminalId']);
	}//end testAPointOfSalePaymentGoesToTheTerminal()

	/**
	 * An invalid request (no amount; point of sale without a terminal) is
	 * refused `invalid` before any provider call.
	 *
	 * @return void
	 */
	public function testAnInvalidRequestIsRefusedInvalid(): void {
		$this->givenSource('mollie');
		$this->mollie->expects($this->never())->method('createPayment');

		$noAmount = $this->command(amount: '');
		$this->listener->handle($noAmount);
		$noTerminal = $this->command(method: 'pointofsale');
		$this->listener->handle($noTerminal);

		$this->assertSame('invalid', $noAmount->getRefusal()['code']);
		$this->assertSame('invalid', $noTerminal->getRefusal()['code']);
		$this->assertStringContainsString('terminalId', $noTerminal->getRefusal()['reason']);
	}//end testAnInvalidRequestIsRefusedInvalid()

	/**
	 * A provider failure is refused `provider-error`, logged, never thrown.
	 *
	 * @return void
	 */
	public function testAProviderFailureIsRefusedProviderError(): void {
		$payments = $this->createMock(PaymentIntentServiceInterface::class);
		$payments->method('createPayment')->willThrowException(new PaymentProviderException('Mollie responded with HTTP 503.'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('integriq: payment request refused', $this->callback(fn (array $c): bool => $c['code'] === 'provider-error' && $c['sourceApp'] === 'shillinq'));
		$listener = new PaymentRequestedListener(payments: $payments, urlGenerator: $this->createMock(IURLGenerator::class), logger: $logger);

		$event = $this->command();
		$listener->handle($event);

		$this->assertSame(['code' => 'provider-error', 'reason' => 'Mollie responded with HTTP 503.'], $event->getRefusal());
	}//end testAProviderFailureIsRefusedProviderError()

	/**
	 * Another event, and a command another listener already answered, are left alone.
	 *
	 * @return void
	 */
	public function testOtherEventsAndAnsweredCommandsAreIgnored(): void {
		$payments = $this->createMock(PaymentIntentServiceInterface::class);
		$payments->expects($this->never())->method('createPayment');
		$listener = new PaymentRequestedListener(payments: $payments, urlGenerator: $this->createMock(IURLGenerator::class), logger: new NullLogger());

		$listener->handle(new Event());
		$answered = $this->command();
		$answered->setHandled(true);
		$listener->handle($answered);

		$this->assertNull($answered->getResult());
		$this->assertNull($answered->getRefusal());
	}//end testOtherEventsAndAnsweredCommandsAreIgnored()

}//end class
