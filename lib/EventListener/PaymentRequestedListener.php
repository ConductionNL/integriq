<?php

/**
 * Integriq PaymentRequestedListener
 *
 * Answers the PaymentRequestedEvent command: builds the create-payment
 * envelope from the event, sets integriq's own absolute webhook URL (a
 * caller does not know integriq's public URL, and a wrong one silently loses
 * every status), adds the provenance to the metadata, and creates the
 * payment through PaymentIntentService, the same path as `POST
 * /api/payments`. It needs no Nextcloud session: the service reads and
 * writes in system context.
 *
 * Every outcome is written into the event. A failure becomes a refusal code
 * (`invalid`, `no-source`, `unsupported`, `provider-error`) with a reason;
 * nothing is thrown to the caller.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use InvalidArgumentException;
use OCA\Integriq\Event\PaymentRequestedEvent;
use OCA\Integriq\Exception\PaymentMethodUnsupportedException;
use OCA\Integriq\Exception\PaymentSourceMissingException;
use OCA\Integriq\Service\PaymentIntentServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates a payment for a sibling app's PaymentRequestedEvent.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
 */
class PaymentRequestedListener implements IEventListener {

	/**
	 * The route of integriq's signed payment webhook.
	 *
	 * @var string
	 */
	public const WEBHOOK_ROUTE = 'integriq.payments.webhook';

	/**
	 * Constructor.
	 *
	 * @param PaymentIntentServiceInterface $payments     The payment creation path.
	 * @param IURLGenerator                 $urlGenerator Builds integriq's absolute webhook URL.
	 * @param LoggerInterface               $logger       The logger.
	 */
	public function __construct(
		private readonly PaymentIntentServiceInterface $payments,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer the command.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function handle(Event $event): void {
		if ($event instanceof PaymentRequestedEvent === false || $event->isHandled() === true) {
			return;
		}

		$event->setHandled(true);
		try {
			$created = $this->payments->createPayment(payload: $this->payloadOf(event: $event));
			$event->setResult(
				paymentIntentId: (string)$created['paymentIntentId'],
				providerPaymentId: (string)$created['providerPaymentId'],
				checkoutUrl: (string)$created['checkoutUrl'],
				paymentStatus: (string)$created['paymentStatus'],
				dormant: (bool)$created['dormant']
			);
		} catch (Throwable $e) {
			$code = $this->refusalCodeFor(exception: $e);
			$this->logger->warning(
				'integriq: payment request refused',
				['sourceApp' => $event->getSourceApp(), 'subjectRef' => $event->getSubjectRef(), 'code' => $code, 'error' => $e->getMessage()]
			);
			$event->setRefusal(reason: $e->getMessage(), code: $code);
		}//end try

	}//end handle()

	/**
	 * The create-payment envelope for the event.
	 *
	 * @param PaymentRequestedEvent $event The command.
	 *
	 * @return array<string, mixed> The envelope PaymentIntentService takes.
	 */
	private function payloadOf(PaymentRequestedEvent $event): array {
		$payload = [
			'amount' => ['value' => $event->getAmountValue(), 'currency' => $event->getAmountCurrency()],
			'description' => $event->getDescription(),
			'redirectUrl' => $event->getRedirectUrl(),
			'webhookUrl' => $this->urlGenerator->linkToRouteAbsolute(self::WEBHOOK_ROUTE),
			'metadata' => array_merge(
				$event->getMetadata(),
				[
					'sourceApp' => $event->getSourceApp(),
					'subjectRef' => $event->getSubjectRef(),
					'correlationId' => $event->getCorrelationId(),
				]
			),
		];

		foreach (['method' => $event->getMethod(), 'terminalId' => $event->getTerminalId(), 'sourceSlug' => $event->getSourceSlug()] as $key => $value) {
			if ($value !== null && $value !== '') {
				$payload[$key] = $value;
			}
		}

		return $payload;

	}//end payloadOf()

	/**
	 * The refusal code for a failure.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return string One of PaymentRequestedEvent::REFUSAL_CODES.
	 */
	private function refusalCodeFor(Throwable $exception): string {
		return match (true) {
			$exception instanceof InvalidArgumentException => 'invalid',
			$exception instanceof PaymentSourceMissingException => 'no-source',
			$exception instanceof PaymentMethodUnsupportedException => 'unsupported',
			default => 'provider-error',
		};

	}//end refusalCodeFor()
}//end class
