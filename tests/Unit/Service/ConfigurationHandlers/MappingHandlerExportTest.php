<?php

/**
 * Characterisation tests for MappingHandler::export().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Pins every branch of the mapping export: entity shape, slug fallback,
 * source/target id to slug conversion and the collected mapping ids.
 */
class MappingHandlerExportTest extends TestCase {

	/**
	 * The handler under test.
	 *
	 * @var MappingHandler
	 */
	private MappingHandler $handler;

	/**
	 * The global id/slug mappings the export converts with.
	 *
	 * @var array
	 */
	private array $mappings = [
		'source'  => ['idToSlug' => ['11' => 'source-a', '12' => 'source-b'], 'slugToId' => []],
		'mapping' => ['idToSlug' => [], 'slugToId' => ['child-map' => '77']],
	];

	/**
	 * Set up the handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->handler = new MappingHandler(ObjectServiceMockBuilder::make($this), new SensitiveFieldRegistry());

	}//end setUp()

	/**
	 * Ids and uuid are dropped, the slug falls back to the uuid, and known
	 * source and target ids become slugs; an unknown one stays.
	 *
	 * @return void
	 */
	public function testAnObjectEntityExportsWithSlugsInPlaceOfIds(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['id' => 5, 'uuid' => 'x', 'name' => 'M', 'source_id' => '11', 'target_id' => '12'],
			'map-uuid'
		);
		$ids = ['1'];

		$export = $this->handler->export($entity, $this->mappings, $ids);

		$this->assertSame(['name' => 'M', 'source_id' => 'source-a', 'target_id' => 'source-b', 'slug' => 'map-uuid'], $export);
		$this->assertSame(['1'], $ids);

		$entity = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'own', 'source_id' => '99', 'target_id' => '11'], 'u');
		$export = $this->handler->export($entity, $this->mappings, $ids);
		$this->assertSame(['slug' => 'own', 'source_id' => '99', 'target_id' => 'source-a'], $export);

		$export = $this->handler->export($entity, [], $ids);
		$this->assertSame(['slug' => 'own', 'source_id' => '99', 'target_id' => '11'], $export);

	}//end testAnObjectEntityExportsWithSlugsInPlaceOfIds()

	/**
	 * A plain entity exports its jsonSerialize() form and never gains a slug.
	 *
	 * @return void
	 */
	public function testAPlainEntityExportsItsSerialisedFormWithoutSlugFallback(): void {
		$entity = new class extends Entity implements \JsonSerializable {
			/**
			 * The serialised form.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['id' => 3, 'name' => 'Plain', 'target_id' => '12'];
			}
		};

		$this->assertSame(['name' => 'Plain', 'target_id' => 'source-b'], $this->handler->export($entity, $this->mappings));

	}//end testAPlainEntityExportsItsSerialisedFormWithoutSlugFallback()

	/**
	 * Every executeMapping() reference in the field mapping is collected,
	 * a known slug as its id and an unknown one as written, after the ids
	 * collected before.
	 *
	 * @return void
	 */
	public function testExecuteMappingReferencesAreCollected(): void {
		$entity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'slug'    => 'parent',
				'mapping' => [
					'a' => "{{ executeMapping('child-map', data.a) }}",
					'b' => "{{ executeMapping( 'unknown' , x) }} and {{ executeMapping(child-map, y) }}",
					'c' => 'plain.value',
				],
			],
			'u'
		);
		$ids = ['first'];

		$export = $this->handler->export($entity, $this->mappings, $ids);

		$this->assertSame(['first', '77', 'unknown', '77'], $ids);
		$this->assertSame('plain.value', $export['mapping']['c']);

		$entity = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'p', 'mapping' => []], 'u');
		$this->handler->export($entity, $this->mappings, $ids);
		$this->assertSame(['first', '77', 'unknown', '77'], $ids);

	}//end testExecuteMappingReferencesAreCollected()

}//end class
