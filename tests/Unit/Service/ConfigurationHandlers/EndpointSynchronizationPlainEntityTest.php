<?php

/**
 * Unit tests for the endpoint and synchronization configuration handlers:
 * the entity type each names, export of entities that are not register
 * objects, and the reference translations that only those entities and
 * edge-case payloads reach.
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
 *
 * @spec openspec/specs/configuration-export-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use JsonSerializable;
use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * The two handlers over the real redaction registry; OpenRegister is the double.
 *
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\EndpointReferenceTranslator
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\Security\StructuredSecretRedactor
 * @uses   \OCA\Integriq\Service\Security\SensitiveValueMasker
 * @uses   \OCA\Integriq\Service\Security\RedactedValueFilter
 */
class EndpointSynchronizationPlainEntityTest extends TestCase {

	/**
	 * Saves, as [schema, uuid, object].
	 *
	 * @var array<int, array{0:string,1:string|null,2:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * The endpoint handler under test.
	 *
	 * @var EndpointHandler
	 */
	private EndpointHandler $endpoints;

	/**
	 * The synchronization handler under test.
	 *
	 * @var SynchronizationHandler
	 */
	private SynchronizationHandler $synchronizations;

	/**
	 * Build the handlers over a recording OpenRegister double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saves = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null): ObjectEntity {
				$this->assertSame('integriq', $register);
				$this->saves[] = [$schema, $uuid, $object];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'new-uuid');
				$entity->setObject($object);
				return $entity;
			}
		);

		$registry = new SensitiveFieldRegistry();
		$this->endpoints = new EndpointHandler($objects, $registry);
		$this->synchronizations = new SynchronizationHandler($objects, $registry);

	}//end setUp()

	/**
	 * The id/slug mappings both directions are read from.
	 *
	 * @return array<string, array{idToSlug: array<int|string, string>, slugToId: array<string, int|string>}>
	 */
	private function mappings(): array {
		return [
			'source' => ['idToSlug' => [7 => 'bron-a'], 'slugToId' => ['bron-a' => 7]],
			'register' => ['idToSlug' => [1 => 'reg'], 'slugToId' => ['reg' => 1]],
			'schema' => ['idToSlug' => [2 => 'sch'], 'slugToId' => ['sch' => 2]],
			'mapping' => ['idToSlug' => [5 => 'map-in'], 'slugToId' => ['map-in' => 5]],
			'rule' => ['idToSlug' => [3 => 'regel-a'], 'slugToId' => ['regel-a' => 3]],
			'synchronization' => ['idToSlug' => [8 => 'sync-b'], 'slugToId' => ['sync-b' => 'sync-uuid-8']],
			'endpoint' => ['idToSlug' => [], 'slugToId' => ['ep-known' => 'ep-uuid-1']],
		];

	}//end mappings()

	/**
	 * An entity that is not a register object, serialising to the given body.
	 *
	 * @param array<string, mixed> $body The serialised body.
	 *
	 * @return Entity
	 */
	private function plainEntity(array $body): Entity {
		return new class($body) extends Entity implements JsonSerializable {

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $body The body.
			 */
			public function __construct(private readonly array $body) {
			}

			/**
			 * The body.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->body;
			}
		};

	}//end plainEntity()

	/**
	 * Each handler names the entity type it handles.
	 *
	 * @return void
	 */
	public function testEachHandlerNamesItsType(): void {
		$this->assertSame('endpoint', $this->endpoints->getEntityType());
		$this->assertSame('synchronization', $this->synchronizations->getEntityType());

	}//end testEachHandlerNamesItsType()

	/**
	 * A plain endpoint exports its serialised body without id and uuid, with
	 * no slug invented, and its references still translated to slugs.
	 *
	 * @return void
	 */
	public function testAPlainEndpointExportsItsBodyWithoutInventingASlug(): void {
		$plain = $this->plainEntity(
			[
				'id' => 4,
				'uuid' => 'ep-uuid',
				'name' => 'Plat',
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'inputMapping' => 5,
				'outputMapping' => 99,
				'rules' => ['3', 42],
			]
		);

		$export = $this->endpoints->export($plain, $this->mappings());

		$this->assertSame(
			[
				'name' => 'Plat',
				'targetType' => 'register/schema',
				'targetId' => 'reg/sch',
				'inputMapping' => 'map-in',
				'outputMapping' => 99,
				'rules' => [0 => 'regel-a'],
			],
			$export
		);

	}//end testAPlainEndpointExportsItsBodyWithoutInventingASlug()

	/**
	 * Endpoint targets: an api target maps to its source slug, a
	 * register/schema target without a slash and an unknown target type are
	 * left as they are, in both directions.
	 *
	 * @return void
	 */
	public function testEndpointTargetsFallBackToTheirReference(): void {
		$api = $this->endpoints->export($this->plainEntity(['targetType' => 'api', 'targetId' => 7]), $this->mappings());
		$this->assertSame('bron-a', $api['targetId']);

		$noSlash = $this->endpoints->export($this->plainEntity(['targetType' => 'register/schema', 'targetId' => '12']), $this->mappings());
		$this->assertSame('12', $noSlash['targetId']);

		$other = $this->endpoints->export($this->plainEntity(['targetType' => 'webhook', 'targetId' => 7]), $this->mappings());
		$this->assertSame(7, $other['targetId']);

		$this->endpoints->import(['targetType' => 'database', 'targetId' => 'bron-a'], $this->mappings());
		$this->endpoints->import(['targetType' => 'register/schema', 'targetId' => 'reg'], $this->mappings());
		$this->endpoints->import(['targetType' => 'register/schema', 'targetId' => 'reg/onbekend'], $this->mappings());
		$this->endpoints->import(['targetType' => 'webhook', 'targetId' => 'bron-a'], $this->mappings());

		$this->assertSame(7, $this->saves[0][2]['targetId']);
		$this->assertSame('reg', $this->saves[1][2]['targetId']);
		$this->assertSame('1/onbekend', $this->saves[2][2]['targetId']);
		$this->assertSame('bron-a', $this->saves[3][2]['targetId']);

	}//end testEndpointTargetsFallBackToTheirReference()

	/**
	 * An endpoint import with a known slug updates that endpoint; an unknown
	 * slug or none creates one; rules that are not a list import as none and
	 * unknown rule slugs are dropped.
	 *
	 * @return void
	 */
	public function testAnEndpointImportUpdatesAKnownSlugAndCreatesOtherwise(): void {
		$known = $this->endpoints->import(['slug' => 'ep-known', 'rules' => ['regel-a', 'onbekend'], 'outputMapping' => 'map-in'], $this->mappings());
		$this->endpoints->import(['slug' => 'ep-nieuw', 'rules' => 'regel-a'], $this->mappings());
		$this->endpoints->import(['name' => 'Zonder slug'], $this->mappings());

		$this->assertSame('ep-uuid-1', $known->getUuid());
		$this->assertSame(['endpoint', 'ep-uuid-1', ['slug' => 'ep-known', 'rules' => [0 => 3], 'outputMapping' => 5]], $this->saves[0]);
		$this->assertSame(['endpoint', null, ['slug' => 'ep-nieuw', 'rules' => []]], $this->saves[1]);
		$this->assertSame(['endpoint', null, ['name' => 'Zonder slug', 'rules' => []]], $this->saves[2]);

	}//end testAnEndpointImportUpdatesAKnownSlugAndCreatesOtherwise()

	/**
	 * A plain synchronization exports its serialised body without id and
	 * uuid and with no slug invented, while a register object without a slug
	 * gets its uuid as slug.
	 *
	 * @return void
	 */
	public function testASynchronizationGetsAUuidSlugOnlyAsARegisterObject(): void {
		$plain = $this->synchronizations->export($this->plainEntity(['id' => 8, 'uuid' => 'u', 'name' => 'Plat']), $this->mappings());
		$this->assertSame(['name' => 'Plat'], $plain);

		$object = new ObjectEntity();
		$object->setUuid('sync-uuid');
		$object->setObject(['name' => 'Object', 'id' => 8]);
		$this->assertSame(['name' => 'Object', 'slug' => 'sync-uuid'], $this->synchronizations->export($object, $this->mappings()));

		$slugged = new ObjectEntity();
		$slugged->setUuid('sync-uuid');
		$slugged->setObject(['name' => 'Object', 'slug' => 'eigen-slug']);
		$this->assertSame('eigen-slug', $this->synchronizations->export($slugged, $this->mappings())['slug']);

	}//end testASynchronizationGetsAUuidSlugOnlyAsARegisterObject()

	/**
	 * Synchronization id lists translate known numeric ids to slugs and keep
	 * unknown ids, non-id strings and nested values; mapping ids translate
	 * by their integer value.
	 *
	 * @return void
	 */
	public function testASynchronizationExportTranslatesOnlyIdShapedReferences(): void {
		$export = $this->synchronizations->export(
			$this->plainEntity(
				[
					'actions' => [3, 'regel-tekst', ['nested' => 3], 404],
					'followUps' => ['8'],
					'conditions' => 'geen-lijst',
					'sourceTargetMapping' => '5',
					'targetSourceMapping' => 6,
					'sourceType' => 'api',
					'sourceId' => 7,
				]
			),
			$this->mappings()
		);

		$this->assertSame([0 => 'regel-a', 1 => 'regel-tekst', 2 => ['nested' => 3], 3 => 404], $export['actions']);
		$this->assertSame(['sync-b'], $export['followUps']);
		$this->assertSame('geen-lijst', $export['conditions']);
		$this->assertSame('map-in', $export['sourceTargetMapping']);
		$this->assertSame(6, $export['targetSourceMapping']);
		$this->assertSame('bron-a', $export['sourceId']);

	}//end testASynchronizationExportTranslatesOnlyIdShapedReferences()

	/**
	 * A synchronization import translates known slugs in its lists to ids,
	 * keeps unknown and nested entries, and updates a synchronization whose
	 * slug is known while creating one otherwise.
	 *
	 * @return void
	 */
	public function testASynchronizationImportTranslatesSlugsAndChoosesUpdateOrCreate(): void {
		$this->synchronizations->import(
			[
				'slug' => 'sync-b',
				'actions' => ['regel-a', 'onbekend', ['nested' => 'regel-a']],
				'followUps' => ['sync-b'],
				'sourceTargetMapping' => 'map-in',
			],
			$this->mappings()
		);
		$this->synchronizations->import(['slug' => 'sync-nieuw'], $this->mappings());

		$this->assertSame('synchronization', $this->saves[0][0]);
		$this->assertSame('sync-uuid-8', $this->saves[0][1]);
		$this->assertSame([3, 'onbekend', ['nested' => 'regel-a']], $this->saves[0][2]['actions']);
		$this->assertSame(['sync-uuid-8'], $this->saves[0][2]['followUps']);
		$this->assertSame(5, $this->saves[0][2]['sourceTargetMapping']);
		$this->assertSame(['synchronization', null, ['slug' => 'sync-nieuw']], $this->saves[1]);

	}//end testASynchronizationImportTranslatesSlugsAndChoosesUpdateOrCreate()
}//end class
