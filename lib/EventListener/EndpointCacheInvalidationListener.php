<?php

/**
 * OpenConnector EndpointCacheInvalidation EventListener.
 *
 * Listens for OpenRegister object create/update/delete events and clears the
 * endpoint routing cache whenever an `openconnector`/`endpoint` object changes,
 * so the runtime path-to-endpoint matcher never serves stale routing after an
 * endpoint is created, updated, or deleted.
 *
 * @category EventListener
 * @package  OCA\OpenConnector\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/revive-dead-capabilities/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Invalidates the endpoint routing cache on endpoint-object writes.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class EndpointCacheInvalidationListener implements IEventListener {

	/**
	 * Register slug that holds OpenConnector endpoint objects.
	 */
	private const ENDPOINT_REGISTER_SLUG = 'openconnector';

	/**
	 * Schema slug of endpoint objects.
	 */
	private const ENDPOINT_SCHEMA_SLUG = 'endpoint';

	/**
	 * Constructor.
	 *
	 * @param EndpointCacheService $endpointCacheService Cache to invalidate.
	 * @param RegisterMapper $registerMapper Resolves an object's register slug.
	 * @param SchemaMapper $schemaMapper Resolves an object's schema slug.
	 * @param LoggerInterface $logger Logger for resolution failures.
	 */
	public function __construct(
		private readonly EndpointCacheService $endpointCacheService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired OR object event.
	 *
	 * @param Event $event Event payload to handle.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		$object = $this->resolveObject(event: $event);
		if ($object === null) {
			return;
		}

		if ($this->isEndpointObject(object: $object) === false) {
			return;
		}

		$this->endpointCacheService->clearCache();

	}//end handle()

	/**
	 * Extract the affected ObjectEntity from a create/update/delete event.
	 *
	 * @param Event $event The fired event.
	 *
	 * @return ObjectEntity|null The affected object, or null when not relevant.
	 */
	private function resolveObject(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectUpdatedEvent === true) {
			return $event->getNewObject();
		}

		if ($event instanceof ObjectCreatedEvent === true
			|| $event instanceof ObjectDeletedEvent === true
		) {
			return $event->getObject();
		}

		return null;
	}//end resolveObject()

	/**
	 * Determine whether the object is an OpenConnector endpoint object.
	 *
	 * @param ObjectEntity $object The object to classify.
	 *
	 * @return bool True when the object is an endpoint definition.
	 */
	private function isEndpointObject(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (\Throwable $e) {
			// Unresolvable register/schema — not an endpoint we can act on.
			$this->logger->debug('EndpointCacheInvalidation: could not resolve register/schema slug: ' . $e->getMessage());
			return false;
		}

		return $registerSlug === self::ENDPOINT_REGISTER_SLUG
			&& $schemaSlug === self::ENDPOINT_SCHEMA_SLUG;

	}//end isEndpointObject()
}//end class
