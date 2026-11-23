<?php

/**
 * Integriq — nightly repair run of outbound synchronizations.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\Synchronization\OutboundReconciliationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Once a day, reconciles every synchronization that declares `targetConfig.reconcile`.
 *
 * One synchronization failing does not stop the others; its failure is logged
 * and the next one runs.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 */
class OutboundReconciliationJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                  $time           The time factory.
	 * @param OutboundReconciliationService $reconciliation The reconciliation service.
	 * @param LoggerInterface               $logger         The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OutboundReconciliationService $reconciliation,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Reconcile each opted-in synchronization.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's own
	 *   `TimedJob::run()` signature; this job takes none.
	 */
	protected function run($argument): void {
		try {
			$synchronizations = $this->reconciliation->findReconcilable();
		} catch (Throwable $exception) {
			$this->logger->warning('[OutboundReconciliationJob] could not read synchronizations: ' . $exception->getMessage());
			return;
		}

		foreach ($synchronizations as $synchronization) {
			try {
				$report = $this->reconciliation->reconcile(synchronization: $synchronization);
				$this->logger->info(
					'[OutboundReconciliationJob] ' . ($synchronization['slug'] ?? $synchronization['id'] ?? '?') . ': ' . $report['message']
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[OutboundReconciliationJob] ' . ($synchronization['slug'] ?? $synchronization['id'] ?? '?') . ' failed: ' . $exception->getMessage()
				);
			}
		}
	}//end run()
}//end class
