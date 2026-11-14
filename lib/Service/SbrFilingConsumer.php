<?php

/**
 * Integriq SBR Filing Consumer.
 *
 * Listens for OpenRegister's `ObjectCreatedEvent` and reacts when the created
 * object is a `nl.conduction.sbr.filing.requested` CloudEvent in integriq's
 * own `event` schema. A sending app (shillinq for the VAT return) raises that
 * event with the finished XBRL instance stored in Nextcloud Files; everything
 * after that lives in {@see SbrFilingService}, so this stays a thin dispatch
 * shell, like the Peppol consumer it mirrors.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\Digipoort\SbrFilingService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches `nl.conduction.sbr.filing.requested` CloudEvents to SbrFilingService.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
 */
class SbrFilingConsumer implements IEventListener {
	/**
	 * Schema slug of integriq's CloudEvent storage.
	 *
	 * @var string
	 */
	private const EVENT_SCHEMA = 'event';

	/**
	 * Constructor.
	 *
	 * @param SbrFilingService       $filings        Stores and delivers the filing.
	 * @param ListenerSchemaResolver $schemaResolver Maps the stamped register and schema ids back to slugs.
	 * @param LoggerInterface        $logger         Logger for a filing that could not be processed.
	 */
	public function __construct(
		private readonly SbrFilingService $filings,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * React to a created filing request; ignore every other event.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		$object = $event->getObject();
		$objectData = $object->getObject();
		// The type is a plain array read; checking it first keeps the id
		// lookups off every other object write on the instance.
		if (($objectData['type'] ?? null) !== SbrFilingService::EVENT_TYPE_REQUESTED) {
			return;
		}

		if ($this->schemaResolver->matchesSchema(entity: $object, expectedSlug: self::EVENT_SCHEMA) === false) {
			return;
		}

		try {
			$this->filings->handleRequested(eventData: (array)($objectData['data'] ?? []));
		} catch (Throwable $exception) {
			$this->logger->error(
				'[SbrFilingConsumer] failed to process a filing request: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}
	}//end handle()
}//end class
