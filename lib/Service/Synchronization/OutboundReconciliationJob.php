<?php

/**
 * Integriq — nightly repair run of outbound synchronizations.
 *
 * @category Cron
 * @package  OCA\Integriq\Service\Synchronization
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

namespace OCA\Integriq\Service\Synchronization;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Once a day, reconciles every synchronization that declares `targetConfig.reconcile`.
 *
 * It lives beside the service it drives, as FetchFilesJob does. One
 * synchronization failing does not stop the others; its failure is logged
 * and the next one runs.
 *
 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 */
class OutboundReconciliationJob extends TimedJob {

	/**
	 * Seconds between runs: once a day.
	 *
	 * @var int
	 */
	public const RUN_EVERY_SECONDS = 86400;

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
		$this->setInterval(seconds: self::RUN_EVERY_SECONDS);
	}//end __construct()

	/**
	 * Reconcile each opted-in synchronization.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's own
	 *   `TimedJob::run()` signature; this job takes none.
	 */
	protected function run($argument): void {
		try {
			$synchronizations = $this->reconciliation->findReconcilable();
		} catch (Throwable $exception) {
			$this->logger->warning(message: '[OutboundReconciliationJob] could not read synchronizations: ' . $exception->getMessage());
			return;
		}

		foreach ($synchronizations as $synchronization) {
			$name = (string)($synchronization['slug'] ?? $synchronization['id'] ?? '?');
			try {
				$report = $this->reconciliation->reconcile(synchronization: $synchronization);
				$this->logger->info(message: '[OutboundReconciliationJob] ' . $name . ': ' . $report['message']);
			} catch (Throwable $exception) {
				$this->logger->warning(message: '[OutboundReconciliationJob] ' . $name . ' failed: ' . $exception->getMessage());
			}
		}
	}//end run()
}//end class
