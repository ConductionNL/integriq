<?php

/**
 * Integriq StUF-ZDS Retry Job.
 *
 * One retry of one StUF-ZDS case kennisgeving whose last attempt failed
 * transiently. The ZDS case client schedules it with
 * IJobList::scheduleAfter() at 5 s, 30 s, 2 min and 10 min; the job re-sends
 * the stored envelope with its original referentienummer.
 *
 * Ported from dossiq's StufRetryJob.
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\StufZkn\StufZdsOutboundInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-sends one StUF-ZDS case message.
 *
 * @psalm-api
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
 */
class StufZdsRetryJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   The time factory.
	 * @param StufZdsOutboundInterface $outbound The ZDS case client.
	 * @param LoggerInterface   $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly StufZdsOutboundInterface $outbound,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Retry the message the argument names.
	 *
	 * @param mixed $argument `{messageId: string}`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	protected function run(mixed $argument): void {
		$messageId = '';
		if (is_array($argument) === true) {
			$messageId = (string)($argument['messageId'] ?? '');
		}

		if ($messageId === '') {
			$this->logger->warning('StufZdsRetryJob: no messageId in the argument');
			return;
		}

		try {
			$this->outbound->retry(messageId: $messageId);
		} catch (Throwable $exception) {
			$this->logger->warning('StufZdsRetryJob failed for {id}: {error}', ['id' => $messageId, 'error' => $exception->getMessage()]);
		}
	}//end run()
}//end class
