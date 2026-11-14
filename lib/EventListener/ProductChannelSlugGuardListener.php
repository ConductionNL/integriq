<?php

/**
 * Integriq ProductChannelSlugGuardListener
 *
 * A channel slug names a channel in a product's routes and AsyncAPI document,
 * so it must be unique within a product version (events-async-api-products,
 * REQ-AAPI-001). JSON Schema cannot say "unique by one property of the items",
 * so the save of an `api_product` whose channels repeat a slug is refused here,
 * before OpenRegister writes it.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

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
 * Refuses an API product version whose channels repeat a slug.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
 */
class ProductChannelSlugGuardListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper  $registerMapper Resolves the saved object's register slug.
	 * @param SchemaMapper    $schemaMapper   Resolves the saved object's schema slug.
	 * @param IL10N           $l10n           Translates the refusal.
	 * @param LoggerInterface $logger         The logger.
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the save when two channels share a slug.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = $this->savedObject(event: $event);

		$duplicate = $this->duplicateSlug(channels: ($entity->getObject()['channels'] ?? null));
		if ($duplicate === null || $this->isProduct(object: $entity) === false) {
			return;
		}

		$event->setErrors(
			[
				'code' => 'api_product_channel_slug_taken',
				'message' => $this->l10n->t('Two channels of this product version are called %s. Give each channel its own slug.', [$duplicate]),
				'status' => 409,
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The object being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 *
	 * @return ObjectEntity The object.
	 */
	private function savedObject(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end savedObject()

	/**
	 * The first slug two channels share, or null.
	 *
	 * @param mixed $channels The product's channels.
	 *
	 * @return string|null The repeated slug.
	 *
	 * @spec openspec/changes/events-async-api-products/specs/api-product-gateway/spec.md#requirement-an-api-product-can-declare-event-channels-req-aapi-001
	 */
	public function duplicateSlug(mixed $channels): ?string {
		if (is_array($channels) === false) {
			return null;
		}

		$seen = [];
		foreach ($channels as $channel) {
			$slug = '';
			if (is_array($channel) === true) {
				$slug = trim((string)($channel['slug'] ?? ''));
			}

			if ($slug === '') {
				continue;
			}

			if (isset($seen[$slug]) === true) {
				return $slug;
			}

			$seen[$slug] = true;
		}

		return null;
	}//end duplicateSlug()

	/**
	 * Whether the object is an integriq `api_product`.
	 *
	 * @param ObjectEntity $object The saved object.
	 *
	 * @return bool True for an API product.
	 */
	private function isProduct(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] product channel check: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === 'integriq' && $schemaSlug === 'api_product';
	}//end isProduct()
}//end class
