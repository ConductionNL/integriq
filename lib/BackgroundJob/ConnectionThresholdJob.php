<?php

/**
 * Integriq ConnectionThresholdJob: count against the alert thresholds every
 * five minutes.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionAlertService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every five minutes, opens and clears connection alerts (ADR-069).
 *
 * The counting lives in {@see ConnectionAlertService}; this job only runs it
 * and makes sure a failure is logged rather than stopping the cron run.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */
class ConnectionThresholdJob extends TimedJob {

	/**
	 * The job interval in seconds.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param ConnectionAlertService $alerts Counts and opens or clears alerts.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ConnectionAlertService $alerts,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Count every threshold once.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's
	 *   own TimedJob::run() signature; this job takes none.
	 *
	 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
	 */
	protected function run($argument): void {
		try {
			$outcome = $this->alerts->evaluate(now: new DateTimeImmutable());
			if ($outcome['opened'] > 0 || $outcome['cleared'] > 0) {
				$this->logger->info(
					'[ConnectionThresholdJob] opened ' . $outcome['opened'] . ' and cleared ' . $outcome['cleared'] . ' connection alert(s).'
				);
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('[ConnectionThresholdJob] counting the thresholds failed: ' . $exception->getMessage());
		}
	}//end run()
}//end class
