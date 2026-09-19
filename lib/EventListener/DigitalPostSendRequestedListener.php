<?php

/**
 * Turns a cross-app send request into a tracked letter.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The listener never leaves the event unanswered: a request that blows up in
 * here comes back as a refusal, because a consumer reading an empty result
 * slot cannot tell a crash from a letter still on its way.
 *
 * @template-implements IEventListener<DigitalPostSendRequestedEvent>
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
class DigitalPostSendRequestedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param DigitalPostService $service The digital post service.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly DigitalPostService $service,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one send request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof DigitalPostSendRequestedEvent === false) {
			return;
		}

		try {
			$this->service->handleSendRequest($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'digital-post.request.failed',
				['sourceApp' => $event->getSourceApp(), 'error' => $e->getMessage()]
			);

			$event->setHandled(true);
			$event->setRefusal($e->getMessage(), 'exception');
		}
	}//end handle()
}//end class
