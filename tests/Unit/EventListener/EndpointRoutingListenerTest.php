<?php

/**
 * Saving an endpoint stores the regex and segments the router reads (live defect I1).
 *
 * Driven with OpenRegister's real ObjectCreatingEvent and ObjectUpdatingEvent:
 * what the listener puts in setModifiedData() is what OpenRegister merges into
 * the stored row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\EndpointRoutingListener;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/specs/endpoint-runtime/spec.md
 */
class EndpointRoutingListenerTest extends TestCase {

	/**
	 * The listener; the object resolves to integriq and the given schema.
	 *
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return EndpointRoutingListener
	 */
	private function listener(string $schemaSlug = 'endpoint'): EndpointRoutingListener {
		$slug = static fn (string $value): object => new class($value) {
			public function __construct(private string $value) {
			}

			public function getSlug(): string {
				return $this->value;
			}
		};
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($slug('integriq'));
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($slug($schemaSlug));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('3600');
		$cache = new EndpointCacheService($this->createMock(ICacheFactory::class), $this->createMock(ORObjectService::class), new NullLogger(), $appConfig);

		return new EndpointRoutingListener(cache: $cache, registerMapper: $registers, schemaMapper: $schemas, logger: new NullLogger());
	}//end listener()

	/**
	 * An entity carrying the given data.
	 *
	 * @param array $data The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setRegister(1);
		$entity->setSchema(2);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A new endpoint from the Endpoints page gets the regex and segments.
	 *
	 * @return void
	 */
	public function testANewEndpointGetsItsRegexAndSegments(): void {
		$event = new ObjectCreatingEvent(self::entity(['name' => 'p', 'endpoint' => 'livepass-lane9/person', 'method' => 'POST']));

		$this->listener()->handle($event);

		$this->assertSame(
			['endpointRegex' => '#^livepass-lane9/person$#', 'endpointArray' => ['livepass-lane9', 'person']],
			$event->getModifiedData()
		);
		$this->assertSame(1, preg_match($event->getModifiedData()['endpointRegex'], 'livepass-lane9/person'));
	}//end testANewEndpointGetsItsRegexAndSegments()

	/**
	 * Changing the path re-derives both, so no stale regex is left behind.
	 *
	 * @return void
	 */
	public function testAChangedPathIsDerivedAgain(): void {
		$old = self::entity(['endpoint' => 'a/b', 'endpointRegex' => '#^a/b$#', 'endpointArray' => ['a', 'b']]);
		$new = self::entity(['endpoint' => 'personen/{{id}}', 'endpointRegex' => '#^a/b$#', 'endpointArray' => ['a', 'b']]);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame(['personen', '{{id}}'], $modified['endpointArray']);
		$this->assertSame(1, preg_match($modified['endpointRegex'], 'personen/123'));
		$this->assertSame(0, preg_match($modified['endpointRegex'], 'a/b'));
	}//end testAChangedPathIsDerivedAgain()

	/**
	 * Another schema, or an endpoint without a path, is left alone.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreLeftAlone(): void {
		$other = new ObjectCreatingEvent(self::entity(['endpoint' => 'a/b']));
		$this->listener(schemaSlug: 'source')->handle($other);
		$this->assertSame([], $other->getModifiedData());

		$pathless = new ObjectCreatingEvent(self::entity(['name' => 'no path']));
		$this->listener()->handle($pathless);
		$this->assertSame([], $pathless->getModifiedData());
	}//end testOtherObjectsAreLeftAlone()
}//end class
