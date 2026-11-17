<?php

/**
 * Integriq OpenFormulierenMappingGuardListener.
 *
 * Refuses to activate an Open Formulieren form mapping that its destination
 * schema would refuse, on OpenRegister's own save path.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\OpenFormulieren\MappingCheck;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
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
 * An active `openformulieren_form_mapping` must be valid against its destination.
 *
 * A mapping saved with `isEnabled` false is stored as it is, so an administrator
 * can keep a draft; activating it runs the check, and findings refuse the save
 * with 422 and the findings in the message.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-mapping-must-be-valid-against-its-case-schema-before-it-is-active
 */
class OpenFormulierenMappingGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param MappingCheck    $check          Judges a mapping against its destination.
	 * @param RegisterMapper  $registerMapper Resolves the saved object's register slug.
	 * @param SchemaMapper    $schemaMapper   Resolves the saved object's schema slug.
	 * @param IL10N           $l10n           Translations.
	 * @param LoggerInterface $logger         Logger.
	 */
	public function __construct(
		private readonly MappingCheck $check,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse activating a mapping with findings.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-mapping-must-be-valid-against-its-case-schema-before-it-is-active
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedObject(event: $event);
		if ($this->isMapping(object: $entity) === false) {
			return;
		}

		$mapping = (array)$entity->getObject();
		if (($mapping['isEnabled'] ?? true) === false) {
			return;
		}

		$findings = $this->check->findings(mapping: $mapping);
		if ($findings === []) {
			return;
		}

		$event->setErrors(
			[
				'code' => 'form_mapping_invalid',
				'message' => $this->l10n->t('This mapping cannot be active: %s', [$this->describe(findings: $findings)]),
				'status' => 422,
				'findings' => $findings,
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The findings as one line: `code on property`, comma-separated.
	 *
	 * @param list<array<string, mixed>> $findings The findings.
	 *
	 * @return string
	 */
	private function describe(array $findings): string {
		$parts = [];
		foreach ($findings as $finding) {
			$parts[] = (string)($finding['code'] ?? '') . ' on ' . (string)($finding['property'] ?? '');
		}

		return implode(', ', $parts);
	}//end describe()

	/**
	 * The object being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity
	 */
	private function savedObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end savedObject()

	/**
	 * Whether the object is an integriq `openformulieren_form_mapping`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isMapping(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug   = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] form mapping check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === OpenFormulierenIntakeService::REGISTER && $schemaSlug === OpenFormulierenIntakeService::SCHEMA_MAPPING;
	}//end isMapping()
}//end class
