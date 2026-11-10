<?php

/**
 * Golden characterization of JobHandler::import().
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

use OCA\Integriq\Service\ConfigurationHandlers\JobHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\SaveCallShape;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Every slug/id translation import() makes, recorded as the save it ends in.
 * Regenerate the golden file on the code it must describe with
 * JOB_IMPORT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\JobHandler
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\Security\RedactedValueFilter
 * @uses   \OCA\Integriq\Service\Security\SensitiveValueMasker
 */
class JobHandlerImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../fixtures/configuration/job-handler-import.json';

	/**
	 * Every save, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The handler over a recording OpenRegister.
	 *
	 * @return JobHandler
	 */
	private function handler(): JobHandler {
		$or = $this->createMock(OrObjectService::class);
		$or->method('saveObject')->willReturnCallback(SaveCallShape::callback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->log[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return new ObjectEntity();
			}
		));

		return new JobHandler($or, new SensitiveFieldRegistry());
	}//end handler()

	/**
	 * The global id/slug mappings, in both directions.
	 *
	 * @return array<string, array<string, array<int|string, int|string>>>
	 */
	private function mappings(): array {
		$pairs = [
			'synchronization' => ['sync-uuid-1' => 'sync-one', 12 => 'sync-twelve'],
			'endpoint' => ['ep-uuid-2' => 'ep-two', 'zero' => 'ep-zero'],
			'source' => ['src-uuid-3' => 'src-three'],
			'job' => ['job-uuid-9' => 'job-nine'],
		];

		$mappings = [];
		foreach ($pairs as $type => $idToSlug) {
			$mappings[$type] = ['idToSlug' => $idToSlug, 'slugToId' => array_flip($idToSlug)];
		}

		$mappings['endpoint']['slugToId']['ep-null'] = null;

		return $mappings;
	}//end mappings()

	/**
	 * The job configurations imported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$all = ['synchronizationId' => 'sync-one', 'endpointId' => 'ep-two', 'sourceId' => 'src-three', 'other' => 'sync-one'];

		return [
			'all-known' => ['arguments' => $all],
			'all-known-json' => ['arguments' => json_encode($all)],
			'sync-int-id' => ['arguments' => ['synchronizationId' => 'sync-twelve']],
			'endpoint-only' => ['arguments' => ['endpointId' => 'ep-zero']],
			'endpoint-null-mapping' => ['arguments' => ['endpointId' => 'ep-null']],
			'source-only' => ['arguments' => ['sourceId' => 'src-three']],
			'unknown' => ['arguments' => ['synchronizationId' => 'x', 'endpointId' => 'y', 'sourceId' => 'z']],
			'null-values' => ['arguments' => ['synchronizationId' => null, 'endpointId' => null, 'sourceId' => null]],
			'int-values' => ['arguments' => ['synchronizationId' => 12, 'sourceId' => 3]],
			'cross-kind' => ['arguments' => ['synchronizationId' => 'ep-two', 'endpointId' => 'src-three', 'sourceId' => 'sync-one']],
			'empty-array' => ['arguments' => []],
			'empty-json' => ['arguments' => '{}'],
			'json-list' => ['arguments' => '["sync-one"]'],
			'json-scalar' => ['arguments' => '"sync-one"'],
			'json-number' => ['arguments' => '42'],
			'invalid-json' => ['arguments' => '{not json'],
			'int-arguments' => ['arguments' => 7],
			'null-arguments' => ['arguments' => null],
			'no-arguments' => ['name' => 'x'],
			'array-value' => ['arguments' => ['sourceId' => ['src-three']]],
			'existing-slug' => ['slug' => 'job-nine', 'arguments' => ['sourceId' => 'src-three']],
			'new-slug' => ['slug' => 'brand-new'],
			'null-slug' => ['slug' => null],
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
		if (getenv('JOB_IMPORT_GOLDEN') === '1') {
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
