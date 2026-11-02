<?php

/**
 * Golden characterization of ConfigurationImportPreviewService::preview().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConfigurationImportPreviewService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Import previews of OAS documents against one target environment, recorded
 * against a golden file. Regenerate it on the code it must describe with
 * IMPORT_PREVIEW_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationImportPreviewService
 * @uses   \OCA\Integriq\Service\ConfigurationImportPreviewRun
 */
class ConfigurationImportPreviewGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/configuration/import-preview.json';

	/**
	 * The target environment: slug (or none) => uuid, by schema.
	 *
	 * @var array<string, array<string, string|null>>
	 */
	private const TARGET = [
		'source' => ['src-a' => 'uuid-src-a', 'shared' => 'uuid-src-shared'],
		'mapping' => ['map-a' => 'uuid-map-a'],
		'rule' => ['rule-a' => 'uuid-rule-a'],
		'endpoint' => ['ep-a' => 'uuid-ep-a', '' => 'uuid-ep-noslug'],
		'synchronization' => [],
		'job' => ['job-a' => 'uuid-job-a', 'shared-job' => 'uuid-job-shared'],
	];

	/**
	 * The documents previewed, by case name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function documents(): array {
		return [
			'no-components' => ['info' => []],
			'empty-components' => ['components' => []],
			'every-kind' => [
				'components' => [
					'sources' => [
						'src-a' => ['slug' => 'src-a', 'apikey' => 'k', 'secret' => '', 'username' => [], 'jwt' => 'x', 'authorizationHeader' => 'h', 'authenticationConfig' => ['a' => 1], 'password' => 'p'],
						'new-src' => ['name' => 'no slug in payload'],
						7 => ['slug' => 'shared-job', 'apikey' => 'k'],
						'not-array' => 'string payload',
					],
					'mappings' => [
						'map-a' => ['source_id' => 'src-a', 'target_id' => 'missing-src'],
						'map-new' => ['source_id' => '', 'target_id' => 5],
					],
					'rules' => [
						'rule-x' => ['slug' => 'rule-x', 'configuration' => ['mapping' => 'map-a', 'sourceId' => 'nope', 'nested' => ['jobId' => 'job-zz', 'synchronizationId' => '', 'other' => 'x', 'endpointId' => 'ep-a'], 'list' => [['mapping' => 'map-gone']], 'source' => 'src-a', 'mySourceId' => 'missing']],
						'rule-a' => ['configuration' => 'not an array'],
					],
					'endpoints' => [
						'ep-a' => ['targetType' => 'api', 'targetId' => 'src-missing', 'inputMapping' => 'map-a', 'outputMapping' => 'map-missing', 'rules' => ['rule-a', 'rule-gone', 3, '']],
						'ep-b' => ['targetType' => 'register/schema', 'targetId' => 'nope'],
						'ep-c' => ['targetType' => 'api', 'targetId' => 'zaken/zaak', 'rules' => 'not a list'],
						'ep-d' => ['targetType' => 'api', 'targetId' => 'src-a', 'inputMapping' => ''],
					],
					'synchronizations' => 'not an array',
					'jobs' => [
						'shared' => ['slug' => 'shared'],
						'job-a' => ['configuration' => ['synchronization' => 'sync-missing']],
					],
				],
			],
		];
	}//end documents()

	/**
	 * Every document previews as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryDocumentMatchesTheGoldenFile(): void {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$results = [];
				foreach ((self::TARGET[(string)($config['filters']['schema'] ?? '')] ?? []) as $slug => $uuid) {
					$data = [];
					if ($slug !== '') {
						$data['slug'] = $slug;
					}

					$results[] = ObjectServiceMockBuilder::objectEntity($this, $data, (string)$uuid);
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);
		$service = new ConfigurationImportPreviewService($objects);

		$actual = [];
		foreach ($this->documents() as $name => $document) {
			try {
				$actual[$name] = ['return' => $service->preview($document)];
			} catch (\Throwable $exception) {
				$actual[$name] = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('IMPORT_PREVIEW_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);
	}//end testEveryDocumentMatchesTheGoldenFile()
}//end class
