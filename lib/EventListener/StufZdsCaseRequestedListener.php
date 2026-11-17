<?php

/**
 * Integriq StufZdsCaseRequested Listener.
 *
 * Sends the StUF-ZDS case message a sibling app asks for through
 * {@see StufZdsCaseClientInterface} and writes the
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\StufZdsCaseRequestedEvent;
use OCA\Integriq\Service\StufZkn\StufZdsCaseClientInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes a StufZdsCaseRequestedEvent to the ZDS case client.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
 */
class StufZdsCaseRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param StufZdsCaseClientInterface $client The ZDS case client.
	 * @param LoggerInterface   $logger The logger.
	 */
	public function __construct(
		private readonly StufZdsCaseClientInterface $client,
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
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-messages-are-a-cross-app-command
	 */
	public function handle(Event $event): void {
		if (($event instanceof StufZdsCaseRequestedEvent) === false) {
			return;
		}

		try {
			$result = $this->dispatch(event: $event);
		} catch (Throwable $exception) {
			$this->refuse(event: $event, exception: $exception);
			return;
		}

		$this->settle(event: $event, result: $result);
	}//end handle()

	/**
	 * Record the client's result on the event, or refuse an operation the listener does not know.
	 *
	 * @param StufZdsCaseRequestedEvent $event  The event.
	 * @param array<string, mixed>|null $result The result, or null for an unknown operation.
	 *
	 * @return void
	 */
	private function settle(StufZdsCaseRequestedEvent $event, ?array $result): void {
		if ($result === null) {
			$event->refuse(reason: 'Unknown StUF-ZDS operation "' . $event->getOperation() . '".');
			return;
		}

		$event->setResult(result: $result);
	}//end settle()

	/**
	 * Log a request the client refused and record the refusal on the event.
	 *
	 * @param StufZdsCaseRequestedEvent $event     The event.
	 * @param Throwable                 $exception Why the client refused it.
	 *
	 * @return void
	 */
	private function refuse(StufZdsCaseRequestedEvent $event, Throwable $exception): void {
		$this->logger->warning(
			'[integriq] a StUF-ZDS case request was refused: ' . $exception->getMessage(),
			['operation' => $event->getOperation(), 'sourceApp' => $event->getSourceApp()]
		);
		$event->refuse(reason: $exception->getMessage());
	}//end refuse()

	/**
	 * Call the client operation the event names.
	 *
	 * @param StufZdsCaseRequestedEvent $event The event.
	 *
	 * @return array<string, mixed>|null The result, or null for an unknown operation.
	 */
	private function dispatch(StufZdsCaseRequestedEvent $event): ?array {
		$context = $this->contextOf(event: $event);

		return match ($event->getOperation()) {
			'creeerZaak' => $this->creeerZaak(event: $event, context: $context),
			'actualiseerZaak' => $this->actualiseerZaak(event: $event, context: $context),
			'geefZaakdetails' => $this->geefZaakdetails(event: $event, context: $context),
			'genereerZaakIdentificatie' => $this->client->genereerZaakIdentificatie(sourceId: $event->getSourceId(), context: $context),
			'vrijBericht' => $this->vrijBericht(event: $event, context: $context),
			default => null,
		};
	}//end dispatch()

	/**
	 * The call context every operation passes: who asks, the correlation id,
	 * whether to include documents and, when asked, the payload limit.
	 *
	 * @param StufZdsCaseRequestedEvent $event The event.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function contextOf(StufZdsCaseRequestedEvent $event): array {
		$args    = $event->getArguments();
		$context = [
			'sourceApp' => $event->getSourceApp(),
			'correlationId' => $event->getCorrelationId(),
			'includeDocuments' => (bool)($args['includeDocuments'] ?? false),
		];
		if (isset($args['payloadLimitBytes']) === true) {
			$context['payloadLimitBytes'] = (int)$args['payloadLimitBytes'];
		}

		return $context;
	}//end contextOf()

	/**
	 * Create the case the event carries.
	 *
	 * @param StufZdsCaseRequestedEvent $event   The event.
	 * @param array<string, mixed>      $context The call context.
	 *
	 * @return array<string, mixed> The client's result.
	 */
	private function creeerZaak(StufZdsCaseRequestedEvent $event, array $context): array {
		$args = $event->getArguments();
		return $this->client->creeerZaak(
			sourceId: $event->getSourceId(),
			case: (array)($args['case'] ?? []),
			zaaktypeOmschrijving: (string)($args['zaaktypeOmschrijving'] ?? ''),
			context: $context
		);
	}//end creeerZaak()

	/**
	 * Update the case the event names with its changes.
	 *
	 * @param StufZdsCaseRequestedEvent $event   The event.
	 * @param array<string, mixed>      $context The call context.
	 *
	 * @return array<string, mixed> The client's result.
	 */
	private function actualiseerZaak(StufZdsCaseRequestedEvent $event, array $context): array {
		$args = $event->getArguments();
		return $this->client->actualiseerZaak(
			sourceId: $event->getSourceId(),
			caseIdentification: (string)($args['caseIdentification'] ?? ''),
			changes: (array)($args['changes'] ?? []),
			context: $context
		);
	}//end actualiseerZaak()

	/**
	 * Read the details of the case the event names.
	 *
	 * @param StufZdsCaseRequestedEvent $event   The event.
	 * @param array<string, mixed>      $context The call context.
	 *
	 * @return array<string, mixed> The client's result.
	 */
	private function geefZaakdetails(StufZdsCaseRequestedEvent $event, array $context): array {
		$args = $event->getArguments();
		return $this->client->geefZaakdetails(
			sourceId: $event->getSourceId(),
			caseIdentification: (string)($args['caseIdentification'] ?? ''),
			wantedElements: array_map('strval', (array)($args['wantedElements'] ?? [])),
			context: $context
		);
	}//end geefZaakdetails()

	/**
	 * Send the free message the event names.
	 *
	 * @param StufZdsCaseRequestedEvent $event   The event.
	 * @param array<string, mixed>      $context The call context.
	 *
	 * @return array<string, mixed> The client's result.
	 */
	private function vrijBericht(StufZdsCaseRequestedEvent $event, array $context): array {
		$args = $event->getArguments();
		return $this->client->vrijBericht(
			sourceId: $event->getSourceId(),
			name: (string)($args['name'] ?? ''),
			payload: (array)($args['payload'] ?? []),
			context: $context
		);
	}//end vrijBericht()
}//end class
