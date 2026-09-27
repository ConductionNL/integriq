<?php

/**
 * Integriq DUO Verzuimloket Retry Job.
 *
 * Background job that re-drives outbound DUO Verzuimloket sends that
 * previously failed transport: for every `verzuim_message` row with
 * `status: failed` or `pending`, re-attempts dispatch via the currently
 * configured provider. Runs hourly by default (mirrors `RodRetryJob`).
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
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\VerzuimloketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retries failed Verzuimloket outbound sends.
 *
 * @psalm-api
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */
class VerzuimloketRetryJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * VerzuimloketRetryJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param VerzuimloketService $verzuimloketService The Verzuimloket service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly VerzuimloketService $verzuimloketService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the Verzuimloket retry sweep.
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
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function run(mixed $argument): void {
		try {
			$retried = $this->verzuimloketService->retryFailed();
			$this->logger->info('VerzuimloketRetryJob: retry sweep complete', ['retried' => $retried]);
		} catch (Throwable $e) {
			$this->logger->error('VerzuimloketRetryJob: retry sweep failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
