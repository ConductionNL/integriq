<?php

/**
 * Unit tests for ProductChangeNoticeListener: an updated API product with a
 * notable change notifies its subscribers, a created one announces a new
 * version, and other schemas and failures leave the save alone.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscribers-are-told-when-a-product-they-use-changes-req-devp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\ProductChangeNoticeListener;
use OCA\Integriq\Service\ProductChangeNoticeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the product change notice listener.
 */
#[CoversClass(ProductChangeNoticeListener::class)]
class ProductChangeNoticeListenerTest extends TestCase {

	/**
	 * The notice service.
	 *
	 * @var ProductChangeNoticeService&MockObject
	 */
	private ProductChangeNoticeService&MockObject $notices;

	/**
	 * A saved object.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private static function object(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p-1');
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setObject($data);

		return $entity;
	}//end object()

	/**
	 * The listener, with the saved object's schema resolving to the given slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ProductChangeNoticeListener The listener.
	 */
	private function listener(string $schema): ProductChangeNoticeListener {
		$slug = static fn (string $value): object => new class($value) {
			/**
			 * @param string $value The slug.
			 */
			public function __construct(private string $value) {
			}

			/**
			 * @return string The slug.
			 */
			public function getSlug(): string {
				return $this->value;
			}
		};
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($slug('integriq'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($slug($schema));
		$this->notices = $this->createMock(ProductChangeNoticeService::class);

		return new ProductChangeNoticeListener($this->notices, $registerMapper, $schemaMapper, new NullLogger());
	}//end listener()

	/**
	 * A deprecated product notifies its subscribers with the kind judged from old and new.
	 *
	 * @return void
	 */
	public function testAnUpdatedProductNotifiesItsSubscribersOfTheChange(): void {
		$listener = $this->listener(schema: 'api_product');
		$new = self::object(data: ['status' => 'deprecated']);
		$this->notices->expects($this->once())->method('kindFor')->with(['status' => 'active'], ['status' => 'deprecated'])->willReturn('deprecated');
		$this->notices->expects($this->once())->method('notifySubscribers')->with($new, 'deprecated')->willReturn(2);

		$listener->handle(new ObjectUpdatedEvent($new, self::object(data: ['status' => 'active'])));
	}//end testAnUpdatedProductNotifiesItsSubscribersOfTheChange()

	/**
	 * An edit subscribers need not hear about writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnremarkableEditNotifiesNobody(): void {
		$listener = $this->listener(schema: 'api_product');
		$this->notices->method('kindFor')->willReturn(null);
		$this->notices->expects($this->never())->method('notifySubscribers');

		$listener->handle(new ObjectUpdatedEvent(self::object(data: ['name' => 'b']), self::object(data: ['name' => 'a'])));
	}//end testAnUnremarkableEditNotifiesNobody()

	/**
	 * A created product announces a new version.
	 *
	 * @return void
	 */
	public function testACreatedProductAnnouncesANewVersion(): void {
		$listener = $this->listener(schema: 'api_product');
		$created = self::object(data: ['productSlug' => 'zaken-api', 'version' => '2.0.0']);
		$this->notices->expects($this->once())->method('notifyNewVersion')->with($created)->willReturn(1);

		$listener->handle(new ObjectCreatedEvent($created));
	}//end testACreatedProductAnnouncesANewVersion()

	/**
	 * Objects of other schemas are ignored.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$listener = $this->listener(schema: 'endpoint');
		$this->notices->expects($this->never())->method('kindFor');
		$this->notices->expects($this->never())->method('notifyNewVersion');

		$listener->handle(new ObjectUpdatedEvent(self::object(data: ['status' => 'deprecated']), self::object(data: [])));
		$listener->handle(new ObjectCreatedEvent(self::object(data: [])));
	}//end testOtherSchemasAreIgnored()

	/**
	 * A failing notice run does not escape into the product save.
	 *
	 * @return void
	 */
	public function testAFailingNoticeRunDoesNotFailTheSave(): void {
		$listener = $this->listener(schema: 'api_product');
		$this->notices->method('kindFor')->willReturn('retired');
		$this->notices->expects($this->once())->method('notifySubscribers')->willThrowException(new RuntimeException('down'));

		$listener->handle(new ObjectUpdatedEvent(self::object(data: ['status' => 'retired']), null));
		$this->addToAssertionCount(1);
	}//end testAFailingNoticeRunDoesNotFailTheSave()
}//end class
