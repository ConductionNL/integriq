<?php

/**
 * Integriq Exchange Job Requested Listener.
 *
 * Takes another app's request to carry one of its data exchange jobs.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCA\Integriq\Service\Exchange\ExchangeRejectionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The entry point of an exchange job, from the owning app's side.
 *
 * Never throws into the sender: every failure becomes a named refusal on the
 * event, so the owning app can tell "not taken" from "taken".
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 *
 * @template-implements IEventListener<Event>
 */
class ExchangeJobRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ExchangeJobService       $jobs       Creates the job.
	 * @param ExchangeRejectionService $rejections Stores a migrated job's rejections.
	 * @param LoggerInterface          $logger     Logger.
	 */
	public function __construct(
		private readonly ExchangeJobService $jobs,
		private readonly ExchangeRejectionService $rejections,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle one request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public function handle(Event $event): void {
		if (($event instanceof ExchangeJobRequestedEvent) === false) {
			return;
		}

		try {
			$created = $this->jobs->handleRequest(event: $event);
		} catch (Throwable $exception) {
			$this->logger->error('[integriq] an exchange job request could not be taken: ' . $exception->getMessage());
			$event->refuse(code: 'store-failed', reason: 'The exchange job could not be taken: ' . $exception->getMessage());
			return;
		}

		$history = $event->getHistory();
		if ($created === null || $history === null) {
			return;
		}

		$rows = $history['rejections'] ?? [];
		if (is_array($rows) === false) {
			return;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			try {
				$this->rejections->migrate(job: $created, rejection: $row);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[integriq] a migrated rejection of exchange job ' . $created->getUuid() . ' was not stored: '
					. $exception->getMessage()
				);
			}
		}

	}//end handle()
}//end class
