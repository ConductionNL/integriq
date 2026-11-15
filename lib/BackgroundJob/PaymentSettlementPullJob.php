<?php

/**
 * Integriq PaymentSettlementPullJob
 *
 * Daily background job that pulls the provider settlements of every payment
 * source whose provider is not `log` and reports each new one as an
 * `nl.conduction.payment.settlement` CloudEvent (messaging-payment-command-
 * settlements-and-point-of-sale D2), through PaymentSettlementService. A
 * failure is logged; the next run reads the same window again and reports
 * what was missed, nothing twice.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
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
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\Payment\PaymentSettlementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pulls and reports provider settlements once a day.
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
 */
class PaymentSettlementPullJob extends TimedJob {

	/**
	 * Once a day.
	 *
	 * @var integer
	 */
	public const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time        The clock.
	 * @param PaymentSettlementService $settlements The pull.
	 * @param LoggerInterface          $logger      The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PaymentSettlementService $settlements,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		// One pull at a time, so two runs never report the same settlement.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Run the pull.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function run(mixed $argument): void {
		try {
			$this->logger->info('PaymentSettlementPullJob: pull complete', $this->settlements->pullAll());
		} catch (Throwable $e) {
			$this->logger->error('PaymentSettlementPullJob: pull failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
