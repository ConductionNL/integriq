<?php

/**
 * Unit tests for the rule, source, mapping and job configuration handlers:
 * create-versus-update on import, slug-to-id translation, redacted values
 * dropped on import, and export of entities that are not register objects.
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
use OCA\Integriq\Service\ConfigurationHandlers\JobHandler;
use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\ConfigurationHandlers\RuleHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * The handlers over the real redaction registry; OpenRegister is the double.
 *
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\RuleHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\SourceHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\MappingHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\JobHandler
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\ConfigurationReferenceTranslator
 */
class ConfigurationHandlerImportExportTest extends TestCase {

	/**
	 * Saves, as [schema, uuid, object].
	 *
	 * @var array<int, array{0:string,1:string|null,2:array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * The OpenRegister double recording every save.
	 *
	 * @var ObjectService
	 */
	private ObjectService $objects;

	/**
	 * Build the recording double.
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
		$this->objects = $objects;

	}//end setUp()

	/**
	 * The id/slug maps an import or export runs with.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	private function mappings(): array {
		return [
			'source' => ['slugToId' => ['zaken-api' => 'src-1', 'brp-api' => 'src-2'], 'idToSlug' => ['src-1' => 'zaken-api']],
			'rule' => ['slugToId' => ['bestaande-regel' => 'rule-7']],
			'mapping' => ['slugToId' => ['bestaande-mapping' => 'map-3']],
			'synchronization' => ['idToSlug' => ['12' => 'zaken-sync']],
			'endpoint' => ['idToSlug' => ['ep-4' => 'zaken-endpoint']],
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
		$registry = new SensitiveFieldRegistry();

		$this->assertSame('rule', (new RuleHandler($this->objects, $registry))->getEntityType());
		$this->assertSame('source', (new SourceHandler($this->objects, $registry))->getEntityType());
		$this->assertSame('mapping', (new MappingHandler($this->objects, $registry))->getEntityType());
		$this->assertSame('job', (new JobHandler($this->objects, $registry))->getEntityType());

	}//end testEachHandlerNamesItsType()

	/**
	 * A rule whose slug is known updates that rule, with its source and target
	 * slugs translated to ids and redacted placeholders dropped; an unknown
	 * slug creates a new rule and leaves unknown references as they are.
	 *
	 * @return void
	 */
	public function testARuleImportUpdatesAKnownSlugAndCreatesOtherwise(): void {
		$handler = new RuleHandler($this->objects, new SensitiveFieldRegistry());

		$handler->import(
			['slug' => 'bestaande-regel', 'source_id' => 'zaken-api', 'target_id' => 'brp-api', 'password' => SensitiveFieldRegistry::PLACEHOLDER],
			$this->mappings()
		);
		$handler->import(['slug' => 'nieuwe-regel', 'source_id' => 'onbekend'], $this->mappings());
		$handler->import(['name' => 'zonder slug'], $this->mappings());

		$this->assertSame(
			[
				['rule', 'rule-7', ['slug' => 'bestaande-regel', 'source_id' => 'src-1', 'target_id' => 'src-2']],
				['rule', null, ['slug' => 'nieuwe-regel', 'source_id' => 'onbekend']],
				['rule', null, ['name' => 'zonder slug']],
			],
			$this->saves
		);

	}//end testARuleImportUpdatesAKnownSlugAndCreatesOtherwise()

	/**
	 * A mapping import translates source and target slugs and updates a known
	 * mapping, creating an unknown one.
	 *
	 * @return void
	 */
	public function testAMappingImportUpdatesAKnownSlugAndCreatesOtherwise(): void {
		$handler = new MappingHandler($this->objects, new SensitiveFieldRegistry());

		$handler->import(['slug' => 'bestaande-mapping', 'source_id' => 'brp-api', 'target_id' => 'zaken-api'], $this->mappings());
		$handler->import(['slug' => 'nieuwe-mapping', 'target_id' => 'onbekend'], $this->mappings());

		$this->assertSame(
			[
				['mapping', 'map-3', ['slug' => 'bestaande-mapping', 'source_id' => 'src-2', 'target_id' => 'src-1']],
				['mapping', null, ['slug' => 'nieuwe-mapping', 'target_id' => 'onbekend']],
			],
			$this->saves
		);

	}//end testAMappingImportUpdatesAKnownSlugAndCreatesOtherwise()

	/**
	 * A source import updates a known source and creates an unknown one, and
	 * never stores a redacted placeholder as a credential.
	 *
	 * @return void
	 */
	public function testASourceImportUpdatesAKnownSlugAndCreatesOtherwise(): void {
		$handler = new SourceHandler($this->objects, new SensitiveFieldRegistry());

		$handler->import(['slug' => 'zaken-api', 'location' => 'https://zaken.example.org', 'apikey' => SensitiveFieldRegistry::PLACEHOLDER], $this->mappings());
		$handler->import(['slug' => 'nieuwe-bron', 'location' => 'https://nieuw.example.org'], $this->mappings());

		$this->assertSame(
			[
				['source', 'src-1', ['slug' => 'zaken-api', 'location' => 'https://zaken.example.org']],
				['source', null, ['slug' => 'nieuwe-bron', 'location' => 'https://nieuw.example.org']],
			],
			$this->saves
		);

	}//end testASourceImportUpdatesAKnownSlugAndCreatesOtherwise()

	/**
	 * A register object without a slug exports its uuid as the slug; an entity
	 * that is not a register object exports its serialised body and gets no
	 * slug invented.
	 *
	 * @return void
	 */
	public function testExportFillsTheSlugOnlyForRegisterObjects(): void {
		$registry = new SensitiveFieldRegistry();
		$handlers = [
			new RuleHandler($this->objects, $registry),
			new SourceHandler($this->objects, $registry),
			new JobHandler($this->objects, $registry),
		];

		foreach ($handlers as $handler) {
			$object = new ObjectEntity();
			$object->setUuid('obj-uuid');
			$object->setObject(['name' => 'Zonder slug', 'uuid' => 'obj-uuid', 'id' => 9]);
			$this->assertSame(['name' => 'Zonder slug', 'slug' => 'obj-uuid'], $handler->export($object, $this->mappings()), $handler->getEntityType());

			$plain = $this->plainEntity(['name' => 'Plat', 'id' => 3]);
			$this->assertSame(['name' => 'Plat'], $handler->export($plain, $this->mappings()), $handler->getEntityType());
		}

	}//end testExportFillsTheSlugOnlyForRegisterObjects()

	/**
	 * A job export replaces known synchronization, endpoint and source ids in
	 * its arguments with slugs and keeps unknown ones unchanged.
	 *
	 * @return void
	 */
	public function testAJobExportTranslatesArgumentIdsToSlugs(): void {
		$job = new ObjectEntity();
		$job->setUuid('job-1');
		$job->setObject(
			[
				'slug' => 'nachtelijke-sync',
				'arguments' => ['synchronizationId' => 12, 'endpointId' => 'ep-4', 'sourceId' => 'src-1', 'other' => 'blijft'],
			]
		);
		$unknown = new ObjectEntity();
		$unknown->setUuid('job-2');
		$unknown->setObject(['slug' => 'andere', 'arguments' => ['synchronizationId' => 99, 'sourceId' => 'src-9']]);

		$handler = new JobHandler($this->objects, new SensitiveFieldRegistry());

		$this->assertSame(
			['synchronizationId' => 'zaken-sync', 'endpointId' => 'zaken-endpoint', 'sourceId' => 'zaken-api', 'other' => 'blijft'],
			$handler->export($job, $this->mappings())['arguments']
		);
		$this->assertSame(
			['synchronizationId' => 99, 'sourceId' => 'src-9'],
			$handler->export($unknown, $this->mappings())['arguments']
		);

	}//end testAJobExportTranslatesArgumentIdsToSlugs()
}//end class
