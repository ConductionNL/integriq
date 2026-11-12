<?php

/**
 * Characterisation tests for RuleHandler's id/slug translation of the
 * nested rule configuration.
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

use OCA\Integriq\Service\ConfigurationHandlers\RuleHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Pins which configuration keys are translated in each direction, the
 * recursion into nested arrays, and the mapping ids export collects.
 */
class RuleHandlerReferenceTest extends TestCase {

	/**
	 * A nested configuration naming every kind of reference.
	 *
	 * @var array
	 */
	private array $config = [
		'source'      => '1',
		'sourceId'    => '1',
		'cronjobId'   => '2',
		'mapping'     => '3',
		'steps'       => [
			['mappingId' => '3', 'schema' => '4', 'other' => '1'],
			['registerId' => '9', 'endpoint' => '5', 'synchronizationId' => '6'],
		],
		'unknownId'   => '1',
		'sourceRef'   => '1',
		7             => '1',
		'job'         => 'not-known',
		'description' => 'plain',
	];

	/**
	 * Build the global mappings for one direction.
	 *
	 * @param string $direction idToSlug or slugToId.
	 *
	 * @return array
	 */
	private function mappings(string $direction): array {
		$pairs = [
			'source'          => ['1' => 'src'],
			'job'             => ['2' => 'job-a'],
			'mapping'         => ['3' => 'map-a'],
			'schema'          => ['4' => 'schema-a'],
			'endpoint'        => ['5' => 'ep-a'],
			'synchronization' => ['6' => 'sync-a'],
		];
		$mappings = [];
		foreach ($pairs as $type => $map) {
			$mappings[$type] = [$direction => $map];
		}

		return $mappings;

	}//end mappings()

	/**
	 * Export translates every known reference key, recursively, and collects
	 * each translated mapping id in traversal order after the ids before.
	 *
	 * @return void
	 */
	public function testExportTranslatesIdsToSlugsAndCollectsMappingIds(): void {
		$handler = new RuleHandler(ObjectServiceMockBuilder::make($this), new SensitiveFieldRegistry());
		$entity = ObjectServiceMockBuilder::objectEntity($this, ['slug' => 'r', 'configuration' => $this->config], 'u');
		$ids = [5 => 'before'];

		$export = $handler->export($entity, $this->mappings('idToSlug'), $ids);

		$this->assertSame(
			[
				'source'      => 'src',
				'sourceId'    => 'src',
				'cronjobId'   => 'job-a',
				'mapping'     => 'map-a',
				'steps'       => [
					['mappingId' => 'map-a', 'schema' => 'schema-a', 'other' => '1'],
					['registerId' => '9', 'endpoint' => 'ep-a', 'synchronizationId' => 'sync-a'],
				],
				'unknownId'   => '1',
				'sourceRef'   => '1',
				7             => '1',
				'job'         => 'not-known',
				'description' => 'plain',
			],
			$export['configuration']
		);
		$this->assertSame([5 => 'before', 6 => '3', 7 => '3'], $ids);

	}//end testExportTranslatesIdsToSlugsAndCollectsMappingIds()

	/**
	 * Import translates the same keys back from slugs to ids.
	 *
	 * @return void
	 */
	public function testImportTranslatesSlugsToIds(): void {
		$mappings = [];
		foreach ($this->mappings('idToSlug') as $type => $map) {
			$mappings[$type] = ['slugToId' => array_flip($map['idToSlug'])];
		}

		$exported = [
			'slug'          => 'r',
			'configuration' => [
				'source' => 'src',
				'cronjobId' => 'job-a',
				'steps' => [['mappingId' => 'map-a', 'schema' => 'schema-a', 'other' => 'src']],
				'unknownId' => 'src',
				'mapping' => 'missing',
			],
		];
		$saved = null;
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$saved): ObjectEntity {
				$saved = $args['object'] ?? $args[0];
				return new ObjectEntity();
			}
		);
		$handler = new RuleHandler($objects, new SensitiveFieldRegistry());

		$handler->import($exported, $mappings);

		$this->assertSame(
			[
				'source' => 1,
				'cronjobId' => 2,
				'steps' => [['mappingId' => 3, 'schema' => 4, 'other' => 'src']],
				'unknownId' => 'src',
				'mapping' => 'missing',
			],
			$saved['configuration']
		);

	}//end testImportTranslatesSlugsToIds()

}//end class
