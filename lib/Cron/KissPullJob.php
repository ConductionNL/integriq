<?php

/**
 * Integriq KISS Pull Job.
 *
 * Background job that runs the scheduled KISS (Klantinteractie
 * Servicesysteem) klantcontacten pull: for every active KISS source it
 * pulls new/changed klantcontacten since the last cursor and persists
 * `kiss_klantcontact` records. Runs hourly by default, mirroring
 * BankfeedSyncJob's TimedJob registration pattern. A KISS bridge with no
 * configured source is a clean no-op (KissSyncService::pullAll() never
 * throws out of the sweep loop).
 *
 * @category Cron
 * @package  OCA\Integriq\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Cron;

use OCA\Integriq\Service\KissSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically pulls KISS klantcontacten.
 *
 * @psalm-api
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
class KissPullJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * KissPullJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param KissSyncService $syncService The KISS sync service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly KissSyncService $syncService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);

		// Klantcontact pulls are not strictly time-sensitive.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only one sweep at a time to avoid double-pulling a cursor window.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the KISS pull sweep.
	 *
	 * A single failing source must never wedge the cron pipeline — the
	 * service already contains per-source and per-record failures, and any
	 * sweep-level exception is caught and logged rather than rethrown.
	 *
	 * @param mixed $argument Task arguments (not used).
	 *
	 * @return void
	 *
	 * @psalm-param   mixed $argument
	 * @phpstan-param mixed $argument
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function run(mixed $argument): void {
		try {
			$processed = $this->syncService->pullAll();
			$this->logger->info(
				'KissPullJob: pull sweep complete',
				['processed' => $processed]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'KissPullJob: pull sweep failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end run()
}//end class
