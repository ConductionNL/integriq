<?php

/**
 * Integriq EndpointRoutingListener.
 *
 * Stores the regex and path segments the endpoint router reads whenever an
 * endpoint is created or updated, on OpenRegister's own save path, so an
 * endpoint made on the Endpoints page can be called.
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
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An endpoint's routing fields follow its path.
 *
 * Before the OpenRegister cutover the EndpointMapper derived `endpointRegex`
 * and `endpointArray` from `endpoint` on every insert and update. The cutover
 * deleted the mapper and nothing took over, so an endpoint saved through the
 * generic object API kept both empty and the router skipped it (live defect
 * I1). OpenRegister merges setModifiedData() into the row before it writes.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointRoutingListener implements IEventListener {

	/**
	 * The register that holds endpoints.
	 */
	private const REGISTER_SLUG = 'integriq';

	/**
	 * The schema this listener derives for.
	 */
	private const SCHEMA_SLUG = 'endpoint';

	/**
	 * Constructor.
	 *
	 * @param EndpointCacheService $cache          Derives the routing fields, as the router reads them.
	 * @param RegisterMapper       $registerMapper Resolves the object's register.
	 * @param SchemaMapper         $schemaMapper   Resolves the object's schema.
	 * @param LoggerInterface      $logger         Logs an unresolvable object.
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function __construct(
		private readonly EndpointCacheService $cache,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Derive the routing fields from the endpoint path being saved.
	 *
	 * @param Event $event The creating or updating event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/endpoint-runtime/spec.md
	 */
	public function handle(Event $event): void {
		// Narrow the event first, as SubscriptionSigningDefaultListener does: without
		// OpenRegister's classes (CI's phpstan) `setModifiedData()` is otherwise looked up on Event.
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

		if ($entity === null || $this->isEndpoint(object: $entity) === false) {
			return;
		}

		$endpoint = (string)(((array)$entity->getObject())['endpoint'] ?? '');
		if ($endpoint === '') {
			return;
		}

		$event->setModifiedData($this->cache->routingFor(endpoint: $endpoint));

	}//end handle()

	/**
	 * Whether the object is an endpoint in the integriq register.
	 *
	 * @param ObjectEntity $object The object being saved.
	 *
	 * @return bool
	 */
	private function isEndpoint(ObjectEntity $object): bool {
		try {
			$registerSlug = $this->registerMapper->find($object->getRegister())->getSlug();
			$schemaSlug = $this->schemaMapper->find($object->getSchema())->getSlug();
		} catch (Throwable $failure) {
			$this->logger->debug('[integriq] endpoint routing: could not resolve register/schema: ' . $failure->getMessage());
			return false;
		}

		return $registerSlug === self::REGISTER_SLUG && $schemaSlug === self::SCHEMA_SLUG;

	}//end isEndpoint()
}//end class
