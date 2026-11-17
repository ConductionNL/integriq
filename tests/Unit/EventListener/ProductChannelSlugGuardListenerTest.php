<?php

/**
 * Unit tests for ProductChannelSlugGuardListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
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

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\ProductChannelSlugGuardListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A product version's channel slugs are unique.
 */
class ProductChannelSlugGuardListenerTest extends TestCase {

	/**
	 * A product whose channels repeat a slug is refused with 409 on create and on update.
	 *
	 * @return void
	 */
	public function testADuplicateChannelSlugIsRefused(): void {
		$channels = [['slug' => 'publications', 'types' => ['a']], ['slug' => 'decisions', 'types' => ['b']], ['slug' => 'publications', 'types' => ['c']]];

		$create = new ObjectCreatingEvent($this->product(channels: $channels));
		$this->guard(schema: 'api_product')->handle($create);
		$update = new ObjectUpdatingEvent($this->product(channels: $channels), $this->product(channels: []));
		$this->guard(schema: 'api_product')->handle($update);

		foreach ([$create, $update] as $event) {
			$this->assertTrue($event->isPropagationStopped());
			$this->assertSame(['api_product_channel_slug_taken', 409], [$event->getErrors()['code'], $event->getErrors()['status']]);
			$this->assertStringContainsString('publications', $event->getErrors()['message']);
		}
	}//end testADuplicateChannelSlugIsRefused()

	/**
	 * Unique slugs, no channels, or another schema are left alone.
	 *
	 * @return void
	 */
	public function testUniqueSlugsAndOtherSchemasPass(): void {
		foreach ([[['slug' => 'publications'], ['slug' => 'decisions']], [], null] as $channels) {
			$event = new ObjectCreatingEvent($this->product(channels: $channels));
			$this->guard(schema: 'api_product')->handle($event);
			$this->assertFalse($event->isPropagationStopped(), json_encode($channels));
		}

		$other = new ObjectCreatingEvent($this->product(channels: [['slug' => 'x'], ['slug' => 'x']]));
		$this->guard(schema: 'call_log')->handle($other);
		$this->assertFalse($other->isPropagationStopped());
	}//end testUniqueSlugsAndOtherSchemasPass()

	/**
	 * A product version with the given channels.
	 *
	 * @param array|null $channels The channels.
	 *
	 * @return ObjectEntity The object.
	 */
	private function product(?array $channels): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('v2');
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setObject(['productSlug' => 'woo-publications', 'channels' => $channels]);
		return $entity;
	}//end product()

	/**
	 * The guard, with the saved object's schema resolving to the given slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ProductChannelSlugGuardListener The guard.
	 */
	private function guard(string $schema): ProductChannelSlugGuardListener {
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
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new ProductChannelSlugGuardListener($registerMapper, $schemaMapper, $l10n, new NullLogger());
	}//end guard()
}//end class
