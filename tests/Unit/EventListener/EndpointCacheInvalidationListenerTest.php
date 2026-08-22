<?php

/**
 * Unit tests for EndpointCacheInvalidationListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/revive-dead-capabilities/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\EndpointCacheInvalidationListener;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the listener that invalidates the endpoint routing cache whenever an
 * openconnector/endpoint object is created, updated, or deleted.
 */
class EndpointCacheInvalidationListenerTest extends TestCase {

	/**
	 * Build an ObjectEntity with integer register/schema ids.
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(int $registerId, int $schemaId): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('obj-uuid');
		$entity->setRegister($registerId);
		$entity->setSchema($schemaId);
		$entity->setObject(['type' => 'endpoint']);

		return $entity;
	}//end entity()

	/**
	 * A tiny slug-bearing value object standing in for a Register/Schema.
	 *
	 * @param string $slug The slug to return.
	 *
	 * @return object
	 */
	private function slugObject(string $slug): object {
		return new class($slug) {
			public function __construct(
				private string $slug,
			) {
			}

			public function getSlug(): string {
				return $this->slug;
			}
		};

	}//end slugObject()

	/**
	 * Build the listener with mappers resolving to the given slugs.
	 *
	 * @param string $registerSlug Register slug the mapper resolves to.
	 * @param string $schemaSlug Schema slug the mapper resolves to.
	 * @param EndpointCacheService $cache The cache mock (with expectations).
	 * @param bool $throwOnResolve Whether the mappers throw on find().
	 *
	 * @return EndpointCacheInvalidationListener
	 */
	private function makeListener(
		string $registerSlug,
		string $schemaSlug,
		EndpointCacheService $cache,
		bool $throwOnResolve = false,
	): EndpointCacheInvalidationListener {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);

		if ($throwOnResolve === true) {
			$registerMapper->method('find')->willThrowException(new \RuntimeException('not found'));
			$schemaMapper->method('find')->willThrowException(new \RuntimeException('not found'));
		} else {
			$registerMapper->method('find')->willReturn($this->slugObject($registerSlug));
			$schemaMapper->method('find')->willReturn($this->slugObject($schemaSlug));
		}

		return new EndpointCacheInvalidationListener(
			$cache,
			$registerMapper,
			$schemaMapper,
			$this->createMock(LoggerInterface::class)
		);

	}//end makeListener()

	/**
	 * An endpoint object created event clears the routing cache — proving the
	 * real trigger fires clearCache() and its cache-invalidation side effect.
	 *
	 * @return void
	 */
	public function testClearsCacheWhenEndpointObjectCreated(): void {
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->expects($this->once())->method('clearCache');

		$listener = $this->makeListener('openconnector', 'endpoint', $cache);
		$listener->handle(new ObjectCreatedEvent($this->entity(1, 2)));

	}//end testClearsCacheWhenEndpointObjectCreated()

	/**
	 * Endpoint update and delete events each clear the cache (stale-routing fix
	 * for the mutate/delete paths the smart-fallback refresh does not cover).
	 *
	 * @return void
	 */
	public function testClearsCacheOnEndpointUpdateAndDelete(): void {
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->expects($this->exactly(2))->method('clearCache');

		$endpoint = $this->entity(1, 2);
		$listener = $this->makeListener('openconnector', 'endpoint', $cache);
		$listener->handle(new ObjectUpdatedEvent(newObject: $endpoint, oldObject: $endpoint));
		$listener->handle(new ObjectDeletedEvent($endpoint));

	}//end testClearsCacheOnEndpointUpdateAndDelete()

	/**
	 * A non-endpoint object (right register, wrong schema) is a cheap no-op.
	 *
	 * @return void
	 */
	public function testIgnoresNonEndpointObject(): void {
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->expects($this->never())->method('clearCache');

		$listener = $this->makeListener('openconnector', 'source', $cache);
		$listener->handle(new ObjectCreatedEvent($this->entity(1, 9)));

	}//end testIgnoresNonEndpointObject()

	/**
	 * An endpoint-named schema in a different register is not our endpoint.
	 *
	 * @return void
	 */
	public function testIgnoresEndpointSchemaInOtherRegister(): void {
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->expects($this->never())->method('clearCache');

		$listener = $this->makeListener('someapp', 'endpoint', $cache);
		$listener->handle(new ObjectCreatedEvent($this->entity(7, 2)));

	}//end testIgnoresEndpointSchemaInOtherRegister()

	/**
	 * Unrelated NC events are ignored without touching the mappers or cache.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedEvent(): void {
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->expects($this->never())->method('clearCache');

		$listener = $this->makeListener('openconnector', 'endpoint', $cache);
		$listener->handle($this->createMock(Event::class));

	}//end testIgnoresUnrelatedEvent()

	/**
	 * Unresolvable register/schema fails safe — no crash, no cache clear.
	 *
	 * @return void
	 */
	public function testFailsSafeWhenRegisterSchemaUnresolvable(): void {
		$cache = $this->createMock(EndpointCacheService::class);
		$cache->expects($this->never())->method('clearCache');

		$listener = $this->makeListener('openconnector', 'endpoint', $cache, throwOnResolve: true);
		$listener->handle(new ObjectCreatedEvent($this->entity(1, 2)));

	}//end testFailsSafeWhenRegisterSchemaUnresolvable()
}//end class
