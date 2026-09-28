<?php

/**
 * Integriq Exchange Mapping Requested Listener.
 *
 * Stores another app's own exchange mapping as an integriq mapping row.
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

use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Integriq\Service\Exchange\ExchangeJobService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Upserts a mapping by slug for the app that asked.
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
 *
 * @template-implements IEventListener<Event>
 */
class ExchangeMappingRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ExchangeJobService $jobs   Stores the mapping.
	 * @param LoggerInterface    $logger Logger.
	 */
	public function __construct(
		private readonly ExchangeJobService $jobs,
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
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-002-a-migrated-job-keeps-its-history
	 */
	public function handle(Event $event): void {
		if (($event instanceof ExchangeMappingRequestedEvent) === false) {
			return;
		}

		try {
			$this->jobs->handleMappingRequest(event: $event);
		} catch (Throwable $exception) {
			$this->logger->error('[integriq] an exchange mapping could not be stored: ' . $exception->getMessage());
			$event->refuse(code: 'store-failed', reason: 'The mapping could not be stored: ' . $exception->getMessage());
		}

	}//end handle()
}//end class
