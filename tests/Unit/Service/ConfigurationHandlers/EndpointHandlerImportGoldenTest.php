<?php

/**
 * Golden characterization of EndpointHandler::import().
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

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Every slug/id translation import() makes, recorded as the save it ends in.
 * Regenerate the golden file on the code it must describe with
 * ENDPOINT_IMPORT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler
 */
class EndpointHandlerImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/configuration/endpoint-handler-import.json';

	/**
	 * Every save, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The handler over a recording OpenRegister.
	 *
	 * @return EndpointHandler
	 */
	private function handler(): EndpointHandler {
		$or = $this->createMock(OrObjectService::class);
		$or->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->log[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			}
		);

		return new EndpointHandler($or, $this->createMock(SensitiveFieldRegistry::class));
	}//end handler()

	/**
	 * The global id/slug mappings, in both directions.
	 *
	 * @return array<string, array<string, array<int|string, int|string>>>
	 */
	private function mappings(): array {
		$pairs = [
			'source' => ['src-uuid-5' => 'src-five', 'src-uuid-6' => 'src-six'],
			'register' => [1 => 'reg-one', 4 => 'reg-four'],
			'schema' => [2 => 'sch-two'],
			'mapping' => ['map-uuid-7' => 'map-seven', 8 => 'map-eight', 'zero' => '0'],
			'rule' => ['rule-uuid-3' => 'rule-three', 33 => 'rule-int', 'r-zero' => 'rule-zero'],
			'endpoint' => ['ep-uuid-9' => 'ep-nine'],
		];

		$mappings = [];
		foreach ($pairs as $type => $idToSlug) {
			$mappings[$type] = ['idToSlug' => $idToSlug, 'slugToId' => array_flip($idToSlug)];
		}

		$mappings['rule']['slugToId']['rule-zero'] = '0';

		return $mappings;
	}//end mappings()

	/**
	 * The endpoint configurations imported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'api-known' => ['targetType' => 'api', 'targetId' => 'src-five'],
			'database-known' => ['targetType' => 'database', 'targetId' => 'src-six'],
			'api-unknown' => ['targetType' => 'api', 'targetId' => 'nope'],
			'api-register-slug' => ['targetType' => 'api', 'targetId' => 'reg-one/sch-two'],
			'register-schema-known' => ['targetType' => 'register/schema', 'targetId' => 'reg-one/sch-two'],
			'register-schema-partly' => ['targetType' => 'register/schema', 'targetId' => 'reg-four/unknown'],
			'register-schema-extra' => ['targetType' => 'register/schema', 'targetId' => 'reg-one/sch-two/tail'],
			'register-schema-no-slash' => ['targetType' => 'register/schema', 'targetId' => 'src-five'],
			'register-schema-empty-parts' => ['targetType' => 'register/schema', 'targetId' => '/'],
			'other-type' => ['targetType' => 'zgw', 'targetId' => 'src-five'],
			'type-true' => ['targetType' => true, 'targetId' => 'src-five'],
			'type-zero' => ['targetType' => 0, 'targetId' => 'src-five'],
			'id-without-type' => ['targetId' => 'src-five'],
			'type-without-id' => ['targetType' => 'api'],
			'id-null' => ['targetType' => 'api', 'targetId' => null],
			'mappings-known' => ['inputMapping' => 'map-seven', 'outputMapping' => 'map-eight'],
			'mappings-unknown' => ['inputMapping' => 'zz', 'outputMapping' => 'map-seven-x'],
			'mappings-zero' => ['inputMapping' => '0', 'outputMapping' => null],
			'input-only' => ['inputMapping' => 'map-eight'],
			'output-only' => ['outputMapping' => 'map-seven'],
			'rules' => ['rules' => ['rule-three', 'rule-int', 'gone', 'rule-zero', 'rule-three']],
			'rules-int' => ['rules' => [5, 'rule-int']],
			'rules-keyed' => ['rules' => ['a' => 'rule-three', 'b' => 'gone', 'c' => 'rule-int']],
			'rules-empty' => ['rules' => []],
			'rules-string' => ['rules' => 'rule-three'],
			'rules-null' => ['rules' => null],
			'rules-bad-element' => ['rules' => [['nested']]],
			'existing-slug' => ['slug' => 'ep-nine', 'name' => 'existing'],
			'new-slug' => ['slug' => 'brand-new'],
			'null-slug' => ['slug' => null],
			'no-slug' => ['name' => 'anonymous'],
			'everything' => ['slug' => 'ep-nine', 'targetType' => 'register/schema', 'targetId' => 'reg-one/sch-two', 'inputMapping' => 'map-seven', 'outputMapping' => 'map-eight', 'rules' => ['rule-three'], 'extra' => ['kept' => true]],
		];
	}//end cases()

	/**
	 * What one case imports as: the saves, or the error.
	 *
	 * @param array<string, mixed> $case The configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function imported(array $case): array {
		$this->log = [];
		try {
			$this->handler()->import($case, $this->mappings());
		} catch (\Throwable $exception) {
			return ['throws' => get_class($exception), 'saves' => $this->log];
		}

		return ['saves' => $this->log];
	}//end run()

	/**
	 * Every case imports as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->imported(case: $case);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('ENDPOINT_IMPORT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$actual = json_decode($encoded, true);
		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($actual as $name => $result) {
			$this->assertSame($expected[$name], $result, $name);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
