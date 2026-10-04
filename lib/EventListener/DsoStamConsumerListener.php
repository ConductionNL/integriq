<?php

/**
 * Integriq DsoStamConsumerListener.
 *
 * Refuses a second `dso-stam` consumer on OpenRegister's own create and
 * update path. One gemeente has one STAM koppeling per environment, and two
 * consumers would make "which account does the intake act as" ambiguous.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-a-second-dso-stam-consumer-is-refused
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * At most one dso-stam consumer per instance.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/consumer-management/spec.md#scenario-a-second-dso-stam-consumer-is-refused
 */
class DsoStamConsumerListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DsoConnection   $connection     Lists the existing dso-stam consumers.
	 * @param RegisterMapper  $registerMapper Resolves the object's register slug.
	 * @param SchemaMapper    $schemaMapper   Resolves the object's schema slug.
	 * @param IL10N           $l10n           Translates the refusal.
	 * @param LoggerInterface $logger         Diagnostics.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function __construct(
		private readonly DsoConnection $connection,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse the save when another dso-stam consumer already exists.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-1
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedObject(event: $event);
		if ($this->isDsoStamConsumer(object: $entity) === false) {
			return;
		}

		foreach ($this->connection->findConsumers() as $existing) {
			if ($existing->getUuid() === $entity->getUuid()) {
				continue;
			}

			$event->setErrors(
				[
					'code' => 'dso_connection_exists',
					'message' => $this->l10n->t('Only one DSO connection is allowed. Edit the existing one instead.'),
					'status' => 409,
				]
			);
			$event->stopPropagation();
			return;
		}

	}//end handle()

	/**
	 * The object being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity The new object.
	 */
	private function savedObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();

	}//end savedObject()

	/**
	 * Whether the object is a consumer in the integriq register with type dso-stam.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool
	 */
	private function isDsoStamConsumer(ObjectEntity $object): bool {
		if (strtolower((string)($object->getObject()['authorizationType'] ?? '')) !== DsoConnection::AUTHORIZATION_TYPE) {
			return false;
		}

		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] dso-stam consumer check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === DsoConnection::REGISTER && $schemaSlug === DsoConnection::SCHEMA_CONSUMER;

	}//end isDsoStamConsumer()
}//end class
