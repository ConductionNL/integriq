<?php

/**
 * OpenConnector iWMO/iJW Retry Job.
 *
 * Background job that re-drives outbound iWMO/iJW (StUF iStandaarden Wmo
 * 3.0 / Jeugdwet 3.0) sends that previously failed transport: for every
 * `iwmo_ijw_message` row with `status: failed` or `pending`, re-attempts
 * dispatch via the currently configured provider. Runs hourly by default,
 * mirroring `KissPullJob`'s `TimedJob` registration pattern. A bridge with
 * no eligible rows is a clean no-op (`IwmoIjwSyncService::retryFailed()`
 * never throws out of the sweep loop).
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\IwmoIjwSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retries failed iWMO/iJW outbound sends.
 *
 * @psalm-api
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005
 */
class IwmoIjwRetryJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * IwmoIjwRetryJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param IwmoIjwSyncService $syncService The iWMO/iJW sync service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IwmoIjwSyncService $syncService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);

		// Retries are not strictly time-sensitive.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only one sweep at a time to avoid double-retrying the same row.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the iWMO/iJW retry sweep.
	 *
	 * A single failing message must never wedge the cron pipeline — the
	 * service already contains per-message failures, and any sweep-level
	 * exception is caught and logged rather than rethrown.
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
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005
	 */
	public function run(mixed $argument): void {
		try {
			$retried = $this->syncService->retryFailed();
			$this->logger->info(
				'IwmoIjwRetryJob: retry sweep complete',
				['retried' => $retried]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'IwmoIjwRetryJob: retry sweep failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end run()
}//end class
