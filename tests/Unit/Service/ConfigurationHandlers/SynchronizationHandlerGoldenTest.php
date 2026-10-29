<?php

/**
 * Golden characterization of SynchronizationHandler::export() and ::import().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Every id/slug translation of export() and import(), recorded against a
 * golden file: the exported array, and the save each import makes.
 *
 * Regenerate the golden file on the code it must describe with
 * SYNC_HANDLER_GOLDEN=1.
 */
class SynchronizationHandlerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/configuration/synchronization-handler.json';

	private const UUID = '0f1e2d3c-4b5a-4968-8776-655443322110';

	/**
	 * Every save, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The handler over a recording OpenRegister and a marking redactor.
	 *
	 * @return SynchronizationHandler
	 */
	private function handler(): SynchronizationHandler {
		$or = $this->createMock(OrObjectService::class);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->log[] = ['or.saveObject', $object, $register, $schema, $uuid];
				return new ObjectEntity();
			}
		);

		$registry = $this->createMock(SensitiveFieldRegistry::class);
		$registry->method('redactArray')->willReturnCallback(
			fn (array $data) => ['redacted' => $data]
		);

		return new SynchronizationHandler($or, $registry);
	}//end handler()

	/**
	 * The global id/slug mappings, in both directions.
	 *
	 * @return array<string, array<string, array<int|string, int|string>>>
	 */
	private function mappings(): array {
		$pairs = [
			'source' => [5 => 'src-five', 6 => 'src-six', self::UUID => 'src-uuid'],
			'register' => [1 => 'reg-one'],
			'schema' => [2 => 'sch-two'],
			'mapping' => [7 => 'map-seven', 8 => 'map-eight'],
			'rule' => [3 => 'rule-three', self::UUID => 'rule-uuid', 'abc' => 'rule-abc'],
			'synchronization' => [9 => 'sync-nine'],
		];

		$mappings = [];
		foreach ($pairs as $type => $idToSlug) {
			$mappings[$type] = ['idToSlug' => $idToSlug, 'slugToId' => array_flip($idToSlug)];
		}

		return $mappings;
	}//end mappings()

	/**
	 * The synchronization arrays exported and imported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$lists = ['actions' => [3, '3', self::UUID, 'abc', 'rule-three', 'rule-abc', 4, ['nested'], null, 2.5], 'followUps' => [9, 'sync-nine', 3, true], 'conditions' => [3, 'rule-three', 'zz']];

		return [
			'api-ids' => ['sourceType' => 'api', 'sourceId' => 5, 'targetType' => 'api', 'targetId' => '6', 'slug' => 'given'],
			'api-slugs' => ['sourceType' => 'api', 'sourceId' => 'src-five', 'targetType' => 'database', 'targetId' => 'src-six', 'slug' => 'sync-nine'],
			'api-unknown' => ['sourceType' => 'database', 'sourceId' => 77, 'targetType' => 'api', 'targetId' => 'nope'],
			'register-schema-ids' => ['sourceType' => 'register/schema', 'sourceId' => '1/2', 'targetType' => 'register/schema', 'targetId' => '1/99/extra'],
			'register-schema-slugs' => ['sourceType' => 'register/schema', 'sourceId' => 'reg-one/sch-two', 'targetType' => 'register/schema', 'targetId' => 'x/sch-two'],
			'register-schema-no-slash' => ['sourceType' => 'register/schema', 'sourceId' => '12', 'targetType' => 'register/schema', 'targetId' => 'reg-one'],
			'other-type' => ['sourceType' => 'file', 'sourceId' => 5, 'targetType' => 'zgw', 'targetId' => 6],
			'id-without-type' => ['sourceId' => 5, 'targetId' => 6],
			'type-without-id' => ['sourceType' => 'api', 'targetType' => 'api'],
			'mapping-ids' => ['sourceTargetMapping' => '7', 'targetSourceMapping' => 8],
			'mapping-slugs' => ['sourceTargetMapping' => 'map-seven', 'targetSourceMapping' => 'map-eight'],
			'mapping-id-cast' => ['sourceTargetMapping' => '7 seven', 'targetSourceMapping' => 8.9],
			'mapping-unknown' => ['sourceTargetMapping' => 70, 'targetSourceMapping' => 'zz'],
			'id-lists' => $lists,
			'id-lists-not-arrays' => ['actions' => 'a', 'followUps' => null, 'conditions' => 3],
			'configuration' => ['configuration' => ['token' => 's3cret'], 'name' => 'n', 'id' => 4, 'uuid' => 'u'],
			'configuration-not-array' => ['configuration' => 'plain', 'slug' => ''],
			'existing-slug' => ['slug' => 'sync-nine', 'name' => 'existing'],
			'new-slug' => ['slug' => 'brand-new'],
			'no-slug' => ['name' => 'anonymous'],
		];
	}//end cases()

	/**
	 * Every case exports and imports as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$handler = $this->handler();
			$entity = new ObjectEntity();
			$entity->setUuid('entity-' . $name);
			$entity->setObject($case);

			$mappingIds = [];
			$this->log = [];
			$exported = $handler->export($entity, $this->mappings(), $mappingIds);
			$handler->import($case, $this->mappings());
			$actual[$name] = ['export' => $exported, 'mappingIds' => $mappingIds, 'import' => $this->log];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('SYNC_HANDLER_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()

	/**
	 * A plain Entity (not an OpenRegister object) exports from its jsonSerialize().
	 *
	 * @return void
	 */
	public function testAPlainEntityExportsItsSerializedForm(): void {
		$entity = new class extends \OCP\AppFramework\Db\Entity implements \JsonSerializable {
			/**
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['id' => 1, 'uuid' => 'u', 'sourceType' => 'api', 'sourceId' => 5];
			}
		};

		$this->assertSame(['sourceType' => 'api', 'sourceId' => 'src-five'], $this->handler()->export($entity, $this->mappings()));
	}//end testAPlainEntityExportsItsSerializedForm()
}//end class
