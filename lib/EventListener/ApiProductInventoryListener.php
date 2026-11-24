<?php

/**
 * Integriq ApiProductInventoryListener.
 *
 * Keeps the API inventory's row of each integriq API product current: a
 * created or updated `api_product` is written there, a deleted one is marked
 * no longer seen. A failure is logged and never blocks the product's save:
 * the inventory is a view, the product is the record.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\ExternalApi\ApiProductInventory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mirrors API products into the API inventory.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */
class ApiProductInventoryListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ApiProductInventory $inventory      Writes the inventory rows.
	 * @param RegisterMapper      $registerMapper Resolves an object's register slug.
	 * @param SchemaMapper        $schemaMapper   Resolves an object's schema slug.
	 * @param LoggerInterface     $logger         Records a row that could not be written.
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function __construct(
		private readonly ApiProductInventory $inventory,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Write or retire the inventory row of a saved or deleted API product.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function handle(Event $event): void {
		[$object, $deleted] = match (true) {
			$event instanceof ObjectCreatedEvent, $event instanceof ObjectDeletedEvent => [$event->getObject(), $event instanceof ObjectDeletedEvent],
			$event instanceof ObjectUpdatedEvent => [$event->getNewObject(), false],
			default => [null, false],
		};

		if ($object === null || $this->slugsOf(object: $object) !== ['integriq', 'api_product']) {
			return;
		}

		try {
			if ($deleted === true) {
				$this->inventory->retire(product: $object);
				return;
			}

			$this->inventory->mirror(product: $object);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[integriq] the API inventory row of an API product could not be written',
				['product' => $object->getUuid(), 'errorClass' => $failure::class]
			);
		}

	}//end handle()

	/**
	 * The register and schema slugs of an object, or two empty strings.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function slugsOf(ObjectEntity $object): array {
		try {
			return [
				(string)$this->registerMapper->find($object->getRegister())->getSlug(),
				(string)$this->schemaMapper->find($object->getSchema())->getSlug(),
			];
		} catch (Throwable $unresolved) {
			return ['', ''];
		}

	}//end slugsOf()

}//end class
