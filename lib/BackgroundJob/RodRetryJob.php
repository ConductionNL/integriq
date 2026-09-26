<?php

/**
 * Integriq DUO ROD Retry Job.
 *
 * Background job that re-drives outbound DUO ROD sends that previously
 * failed transport: for every `rod_message` row with `status: failed` or
 * `pending`, re-attempts dispatch via the currently configured provider.
 * Runs hourly by default, mirroring `IwmoIjwRetryJob`'s `TimedJob`
 * registration pattern. A sweep with no eligible rows is a clean no-op
 * (`RodService::retryFailed()` never throws out of the sweep loop).
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\RodService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retries failed ROD outbound sends.
 *
 * @psalm-api
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */
class RodRetryJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * RodRetryJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param RodService $rodService The ROD service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly RodService $rodService,
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
	 * Execute the ROD retry sweep.
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
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function run(mixed $argument): void {
		try {
			$retried = $this->rodService->retryFailed();
			$this->logger->info('RodRetryJob: retry sweep complete', ['retried' => $retried]);
		} catch (Throwable $e) {
			$this->logger->error('RodRetryJob: retry sweep failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
