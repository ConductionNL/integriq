<?php

/**
 * Integriq DsoActivityMappingGuardListener.
 *
 * Refuses a `dso_activity_mapping` row the schema cannot refuse on its own,
 * on OpenRegister's own create and update path, so the refusal holds
 * whichever page or API saves the row (change dso-activity-mapping-table,
 * design D1).
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
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\Dso\DsoActivityTable;
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
 * Two rules on a DSO activity mapping row.
 *
 * A row names an imowId or an activityId, or it can never match: the schema
 * cannot say "one of the two", because OpenRegister reads a schema-level
 * `anyOf` as schema composition and does not enforce it. And two active rows
 * may not share an imowId: the first would win at intake and the second would
 * never be used, while it looks configured.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
 */
class DsoActivityMappingGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DsoActivityTable $table          Lists the existing rows.
	 * @param RegisterMapper   $registerMapper Resolves the object's register slug.
	 * @param SchemaMapper     $schemaMapper   Resolves the object's schema slug.
	 * @param IL10N            $l10n           Translates the refusal.
	 * @param LoggerInterface  $logger         Diagnostics.
	 */
	public function __construct(
		private readonly DsoActivityTable $table,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse a row without an identifier, or an active row whose imowId another active row has.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedObject(event: $event);
		if ($this->isMappingRow(object: $entity) === false) {
			return;
		}

		$row = (array)$entity->getObject();
		$imowId = trim((string)($row['imowId'] ?? ''));
		if ($imowId === '' && trim((string)($row['activityId'] ?? '')) === '') {
			$this->refuse(
				event: $event,
				code: 'dso_activity_identifier_missing',
				message: $this->l10n->t('Fill in the imow-id or the activity id. A row without either never matches a verzoek.'),
				status: 400
			);
			return;
		}

		if ($imowId === '' || ($row['isActive'] ?? true) === false) {
			return;
		}

		foreach ($this->table->activeRows() as $existing) {
			if ((string)$existing['id'] === (string)$entity->getUuid()
				|| trim((string)($existing['imowId'] ?? '')) !== $imowId
			) {
				continue;
			}

			$this->refuse(
				event: $event,
				code: 'dso_activity_imow_id_taken',
				message: $this->l10n->t('Another active row already maps imow-id %s. Edit that row, or deactivate it first.', [$imowId]),
				status: 409
			);
			return;
		}

	}//end handle()

	/**
	 * Stop the save with an error.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The event.
	 * @param string                                  $code    The error code.
	 * @param string                                  $message The translated message.
	 * @param int                                     $status  The HTTP status.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $code, string $message, int $status): void {
		$event->setErrors(['code' => $code, 'message' => $message, 'status' => $status]);
		$event->stopPropagation();

	}//end refuse()

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
	 * Whether the object is a dso_activity_mapping row in the integriq register.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool
	 */
	private function isMappingRow(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] dso activity mapping check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === DsoActivityTable::REGISTER && $schemaSlug === DsoActivityTable::SCHEMA;

	}//end isMappingRow()
}//end class
