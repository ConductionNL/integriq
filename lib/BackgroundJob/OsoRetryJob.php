<?php

/**
 * Integriq OSO Retry Job.
 *
 * Background job that re-drives outbound OSO exports that previously
 * failed transport: for every `oso_message` row with `direction: export`
 * and `status: failed` or `pending`, re-attempts dispatch via the
 * currently configured provider. Runs hourly by default (mirrors
 * `RodRetryJob`).
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\OsoService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retries failed OSO export sends.
 *
 * @psalm-api
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
 */
class OsoRetryJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (1 hour).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 3600;

	/**
	 * OsoRetryJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param OsoService $osoService The OSO service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OsoService $osoService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the OSO retry sweep.
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
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-005-per-message-audit-persistence-and-isolated-retry
	 */
	public function run(mixed $argument): void {
		try {
			$retried = $this->osoService->retryFailed();
			$this->logger->info('OsoRetryJob: retry sweep complete', ['retried' => $retried]);
		} catch (Throwable $e) {
			$this->logger->error('OsoRetryJob: retry sweep failed: ' . $e->getMessage(), ['exception' => $e]);
		}

	}//end run()
}//end class
