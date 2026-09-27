<?php

/**
 * Integriq UWLR/Edu-V Retry Job.
 *
 * Background job that re-drives sends that previously failed transport:
 * for every `uwlr_eduv_message` row with `status: failed`, across every
 * target (uwlr, edu-v, basispoort, entree-content), re-attempts dispatch
 * via the currently configured provider. Runs hourly by default (mirrors
 * `OsoRetryJob`).
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-007-per-target-audit-persistence-and-isolated-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\UwlrEduVService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retries failed UWLR/Edu-V/Basispoort/
 * Entree-content sends.
 *
 * @psalm-api
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-007-per-target-audit-persistence-and-isolated-retry
 */
class UwlrEduVRetryJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * UwlrEduVRetryJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param UwlrEduVService $uwlrEduVService The UWLR/Edu-V service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly UwlrEduVService $uwlrEduVService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the UWLR/Edu-V retry sweep.
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
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-007-per-target-audit-persistence-and-isolated-retry
	 */
	public function run(mixed $argument): void {
		try {
			$retried = $this->uwlrEduVService->retryFailed();
			$this->logger->info('UwlrEduVRetryJob: retry sweep complete', ['retried' => $retried]);
		} catch (Throwable $e) {
			$this->logger->error('UwlrEduVRetryJob: retry sweep failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
