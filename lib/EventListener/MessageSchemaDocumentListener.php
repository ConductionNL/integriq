<?php

/**
 * Integriq MessageSchemaDocumentListener.
 *
 * Refuses to store a message schema whose document does not parse for its
 * kind, on OpenRegister's own create and update path, so the refusal holds
 * whichever page or app saves the schema.
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
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\MessageValidationService;
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
 * A broken message schema is refused before it is stored.
 *
 * A message schema that does not parse would refuse every message that later
 * meets it, or, worse, be read as "no schema" by whoever looks at it. The save
 * is the one moment the administrator is looking, so the parser's message is
 * shown there and nothing is stored.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
 */
class MessageSchemaDocumentListener implements IEventListener {

	/**
	 * The register that holds message schemas.
	 */
	private const REGISTER_SLUG = 'integriq';

	/**
	 * The schema this listener guards.
	 */
	private const SCHEMA_SLUG = 'message_schema';

	/**
	 * Constructor.
	 *
	 * @param MessageValidationService $validator      Knows how each kind parses.
	 * @param RegisterMapper           $registerMapper Resolves the object's register.
	 * @param SchemaMapper             $schemaMapper   Resolves the object's schema.
	 * @param IL10N                    $l10n           Translates the refusal.
	 * @param LoggerInterface          $logger         Logs an unresolvable object.
	 */
	public function __construct(
		private readonly MessageValidationService $validator,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse the save when the message schema's document does not parse.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-message-schema-is-stored-once-and-referenced-req-msv-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = null;
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->isMessageSchema(object: $entity) === false) {
			return;
		}

		$problem = $this->validator->documentProblem(messageSchema: (array)$entity->getObject());
		if ($problem === null) {
			return;
		}

		$event->setErrors(
			[
				'code' => 'message_schema_document_invalid',
				'message' => $this->l10n->t('This message schema was not saved: %s', [$problem]),
				'status' => 400,
			]
		);
		$event->stopPropagation();

	}//end handle()

	/**
	 * Whether the object is a message schema in the integriq register.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool
	 */
	private function isMessageSchema(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] message schema check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === self::REGISTER_SLUG && $schemaSlug === self::SCHEMA_SLUG;

	}//end isMessageSchema()
}//end class
