<?php

/**
 * Integriq ExchangeRateRequested listener.
 *
 * Answers a sibling app's rate question from the stored ECB euro reference
 * rates (sources-ecb-exchange-rates, design D2). The answer goes into the
 * event's result slot; a question that cannot be answered gets a refusal
 * and never an exception.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\ExchangeRateRequestedEvent;
use OCA\Integriq\Service\ExchangeRateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the question through ExchangeRateService and writes the answer.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
 */
class ExchangeRateRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateService $rates  Answers a pair on a date.
	 * @param LoggerInterface     $logger Records a question that could not be answered.
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function __construct(
		private readonly ExchangeRateService $rates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer the question.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ExchangeRateRequestedEvent) === false) {
			return;
		}

		$context = [
			'sourceApp'     => $event->getSourceApp(),
			'baseCurrency'  => $event->getBaseCurrency(),
			'quoteCurrency' => $event->getQuoteCurrency(),
			'asOf'          => $event->getAsOf(),
		];

		try {
			$answer = $this->rates->quote(
				baseCurrency: $event->getBaseCurrency(),
				quoteCurrency: $event->getQuoteCurrency(),
				asOf: $event->getAsOf(),
				maxAgeDays: $event->getMaxAgeDays()
			);
		} catch (Throwable $e) {
			$this->logger->warning('Integriq: an exchange rate question could not be answered', $context + ['error' => $e->getMessage()]);
			$event->setRefusal(
				code: ExchangeRateService::REFUSAL_UNAVAILABLE,
				reason: 'The stored exchange rates could not be read.'
			);
			return;
		}

		if (isset($answer['result']) === true) {
			$event->setResult($answer['result']);
			return;
		}

		$refusal = $answer['refusal'];
		$this->logger->info('Integriq: an exchange rate question was refused (' . $refusal['code'] . ')', $context);
		$event->setRefusal(
			code: (string)$refusal['code'],
			reason: (string)$refusal['reason'],
			newestDate: $refusal['newestDate']
		);
	}//end handle()
}//end class
