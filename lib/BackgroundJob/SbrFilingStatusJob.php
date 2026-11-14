<?php

/**
 * Integriq SBR Filing Status Job.
 *
 * Every ten minutes: tries again the Digipoort deliveries that failed with
 * retry budget left, and asks the status service about every delivered
 * filing that has not reached a final status. Each status change is reported
 * as a `nl.conduction.sbr.filing.status` CloudEvent by SbrFilingService.
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\Digipoort\SbrFilingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Polls Digipoort for the status of open filings and retries failed deliveries.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
 */
class SbrFilingStatusJob extends TimedJob {
	/**
	 * Ten minutes between sweeps.
	 *
	 * @var integer
	 */
	public const INTERVAL = 600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory     $time    The time factory.
	 * @param SbrFilingService $filings Retries deliveries and polls statuses.
	 * @param LoggerInterface  $logger  Logger for the sweep's outcome.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SbrFilingService $filings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		// One sweep at a time, so no filing is delivered twice by two sweeps.
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Run one sweep.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003
	 */
	public function run(mixed $argument): void {
		try {
			$result = $this->filings->sweep();
			$this->logger->info('SbrFilingStatusJob: sweep complete', $result);
		} catch (Throwable $exception) {
			$this->logger->error('SbrFilingStatusJob: sweep failed: ' . $exception->getMessage(), ['exception' => $exception]);
		}
	}//end run()
}//end class
