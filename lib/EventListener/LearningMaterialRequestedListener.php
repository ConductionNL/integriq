<?php

/**
 * Integriq LearningMaterialRequested listener.
 *
 * Answers a sibling app's learning material request (edurep-source): the
 * answer or the structured refusal goes back on the event, and nothing is
 * thrown into the sender.
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
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Adapters\LearningMaterial\LearningMaterialRefusedException;
use OCA\Integriq\Adapters\LearningMaterial\LearningMaterialService;
use OCA\Integriq\Event\LearningMaterialRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers learning material requests.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
 */
class LearningMaterialRequestedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param LearningMaterialService $service Carries out the request.
	 * @param LoggerInterface $logger Logs refusals and failures.
	 */
	public function __construct(
		private readonly LearningMaterialService $service,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer or refuse the request on the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-the-source-is-a-connection-with-health-and-settings
	 */
	public function handle(Event $event): void {
		if (($event instanceof LearningMaterialRequestedEvent) === false) {
			return;
		}

		$audit = ['sourceApp' => $event->getSourceApp(), 'operation' => (string)($event->getRequest()['operation'] ?? '')];
		try {
			$event->answer(answer: $this->service->handle(request: $event->getRequest()));
		} catch (LearningMaterialRefusedException $refusal) {
			$this->logger->info('Integriq: a learning material request was refused', $audit + ['code' => $refusal->refusalCode]);
			$event->refuse(refusal: $refusal->toArray());
		} catch (Throwable $error) {
			$this->logger->warning('Integriq: a learning material request failed', $audit + ['error' => $error->getMessage()]);
			$event->refuse(refusal: ['code' => 'failed', 'reason' => 'De vraag aan de bron is mislukt.']);
		}
	}//end handle()
}//end class
