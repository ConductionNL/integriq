<?php

/**
 * Integriq StufZdsCaseRequested Listener.
 *
 * Sends the StUF-ZDS case message a sibling app asks for through
 * {@see \OCA\Integriq\Service\StufZkn\StufZdsCaseClient} and writes the
 * result slot. A request the client refuses before sending (unknown source,
 * log mode, missing zaaktype, unregistered template, unknown operation) is
 * recorded as a refusal, never as a sent message.
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
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\StufZdsCaseRequestedEvent;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClient;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes a StufZdsCaseRequestedEvent to the ZDS case client.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */
class StufZdsCaseRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param StufZdsCaseClient $client The ZDS case client.
	 * @param LoggerInterface   $logger The logger.
	 */
	public function __construct(
		private readonly StufZdsCaseClient $client,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle the request.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function handle(Event $event): void {
		if (($event instanceof StufZdsCaseRequestedEvent) === false) {
			return;
		}

		try {
			$result = $this->dispatch(event: $event);
		} catch (Throwable $exception) {
			$this->logger->warning('[integriq] a StUF-ZDS case request was refused: ' . $exception->getMessage(), ['operation' => $event->getOperation(), 'sourceApp' => $event->getSourceApp()]);
			$event->refuse(reason: $exception->getMessage());
			return;
		}

		if ($result === null) {
			$event->refuse(reason: 'Unknown StUF-ZDS operation "' . $event->getOperation() . '".');
			return;
		}

		$event->setResult(result: $result);
	}//end handle()

	/**
	 * Call the client operation the event names.
	 *
	 * @param StufZdsCaseRequestedEvent $event The event.
	 *
	 * @return array<string, mixed>|null The result, or null for an unknown operation.
	 */
	private function dispatch(StufZdsCaseRequestedEvent $event): ?array {
		$args     = $event->getArguments();
		$sourceId = $event->getSourceId();
		$context  = [
			'sourceApp' => $event->getSourceApp(),
			'correlationId' => $event->getCorrelationId(),
			'includeDocuments' => (bool)($args['includeDocuments'] ?? false),
		];
		if (isset($args['payloadLimitBytes']) === true) {
			$context['payloadLimitBytes'] = (int)$args['payloadLimitBytes'];
		}

		$caseId = (string)($args['caseIdentification'] ?? '');

		return match ($event->getOperation()) {
			'creeerZaak' => $this->client->creeerZaak(sourceId: $sourceId, case: (array)($args['case'] ?? []), zaaktypeOmschrijving: (string)($args['zaaktypeOmschrijving'] ?? ''), context: $context),
			'actualiseerZaak' => $this->client->actualiseerZaak(sourceId: $sourceId, caseIdentification: $caseId, changes: (array)($args['changes'] ?? []), context: $context),
			'geefZaakdetails' => $this->client->geefZaakdetails(sourceId: $sourceId, caseIdentification: $caseId, wantedElements: array_map('strval', (array)($args['wantedElements'] ?? [])), context: $context),
			'genereerZaakIdentificatie' => $this->client->genereerZaakIdentificatie(sourceId: $sourceId, context: $context),
			'vrijBericht' => $this->client->vrijBericht(sourceId: $sourceId, name: (string)($args['name'] ?? ''), payload: (array)($args['payload'] ?? []), context: $context),
			default => null,
		};
	}//end dispatch()
}//end class
