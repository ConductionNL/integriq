<?php

/**
 * Golden characterization of the unresolved references an import preview reports.
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
 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-surfaces-an-unresolvable-slug-reference-as-a-blocking-warning
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConfigurationImportPreviewService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * One entity per case, previewed against one target environment; the
 * unresolved references of each are recorded against a golden file.
 * Regenerate it on the code it must describe with PREVIEW_REFERENCES_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationImportPreviewService
 * @uses   \OCA\Integriq\Service\ConfigurationImportPreviewRun
 */
class ConfigurationImportPreviewReferencesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/configuration/import-preview-references.json';

	/**
	 * The target environment: slugs by schema.
	 *
	 * @var array<string, list<string>>
	 */
	private const TARGET = [
		'source' => ['src-a', '7'],
		'mapping' => ['map-a'],
		'rule' => ['rule-a'],
		'endpoint' => ['ep-a'],
		'synchronization' => ['sync-a'],
		'job' => ['job-a'],
	];

	/**
	 * The entities previewed, by case name: the component bucket and the payload.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	private function cases(): array {
		return [
			'ep-api-missing' => ['endpoints', ['targetType' => 'api', 'targetId' => 'src-gone']],
			'ep-api-known' => ['endpoints', ['targetType' => 'api', 'targetId' => 'src-a']],
			'ep-no-type-missing' => ['endpoints', ['targetId' => 'src-gone']],
			'ep-register-schema' => ['endpoints', ['targetType' => 'register/schema', 'targetId' => 'nope']],
			'ep-slash' => ['endpoints', ['targetType' => 'api', 'targetId' => 'a/b']],
			'ep-empty-target' => ['endpoints', ['targetType' => 'database', 'targetId' => '']],
			'ep-int-target' => ['endpoints', ['targetType' => 'api', 'targetId' => 7]],
			'ep-null-type' => ['endpoints', ['targetType' => null, 'targetId' => 'x']],
			'ep-int-type' => ['endpoints', ['targetType' => 5, 'targetId' => 'x']],
			'ep-mappings' => ['endpoints', ['inputMapping' => 'map-a', 'outputMapping' => 'map-gone']],
			'ep-mappings-both-missing' => ['endpoints', ['inputMapping' => 'in-gone', 'outputMapping' => 'out-gone']],
			'ep-mappings-odd' => ['endpoints', ['inputMapping' => '', 'outputMapping' => 3]],
			'ep-mapping-is-source' => ['endpoints', ['inputMapping' => 'src-a']],
			'ep-rules' => ['endpoints', ['rules' => ['rule-a', 'rule-gone', '', 4, null, 'map-a', 'rule-gone']]],
			'ep-rules-keyed' => ['endpoints', ['rules' => ['x' => 'rule-gone']]],
			'ep-rules-string' => ['endpoints', ['rules' => 'rule-gone']],
			'ep-rules-null' => ['endpoints', ['rules' => null]],
			'ep-everything' => ['endpoints', ['targetType' => 'api', 'targetId' => 'src-gone', 'inputMapping' => 'map-gone', 'outputMapping' => 'map-a', 'rules' => ['rule-gone'], 'source_id' => 'src-x', 'configuration' => ['job' => 'job-gone']]],
			'rule-like-endpoint' => ['rules', ['targetType' => 'api', 'targetId' => 'src-gone', 'rules' => ['rule-gone']]],
			'cfg-type-keys' => ['rules', ['configuration' => ['source' => 'src-gone', 'job' => 'job-a', 'endpoint' => 'ep-gone', 'mapping' => 'map-gone', 'synchronization' => 'sync-gone', 'rule' => 'rule-gone']]],
			'cfg-id-suffixes' => ['rules', ['configuration' => ['sourceId' => 'x1', 'SourceId' => 'x2', 'mySourceId' => 'x3', 'mysourceId' => 'x4', 'sourceid' => 'x5', 'jobId' => 'job-a', 'endpointId' => 'x6', 'synchronizationId' => 'x7', 'mappingId' => 'x8', 'ruleId' => 'x9']]],
			'cfg-first-type-wins' => ['rules', ['configuration' => ['sourceJobId' => 'src-a', 'jobSourceId' => 'job-a']]],
			'cfg-odd-values' => ['rules', ['configuration' => ['source' => '', 'job' => 5, 'endpoint' => null, 'mapping' => true, 'synchronization' => 2.5]]],
			'cfg-nested' => ['rules', ['configuration' => ['a' => ['b' => ['jobId' => 'job-gone', 'c' => 'x'], 'source' => 'src-a'], 'mapping' => 'map-gone']]],
			'cfg-lists' => ['rules', ['configuration' => ['list' => [['mapping' => 'm1'], ['mapping' => 'map-a'], 'job-gone'], 7 => ['source' => 's7']]]],
			'cfg-empty-root-key' => ['rules', ['configuration' => ['' => ['source' => 'src-gone', '' => ['job' => 'job-gone']]]]],
			'cfg-empty-inner-key' => ['rules', ['configuration' => ['a' => ['' => ['source' => 'src-gone']]]]],
			'cfg-int-root-key' => ['rules', ['configuration' => [0 => ['source' => 'src-gone'], 1 => 'x']]],
			'cfg-empty-array' => ['rules', ['configuration' => ['source' => []]]],
			'cfg-not-array' => ['rules', ['configuration' => 'source']],
			'cfg-numeric-slug' => ['rules', ['configuration' => ['source' => '7', 'job' => '7']]],
			'top-level-ids' => ['mappings', ['source_id' => 'src-gone', 'target_id' => 'src-a']],
			'top-level-odd' => ['jobs', ['source_id' => '', 'target_id' => ['x']]],
		];
	}//end cases()

	/**
	 * Every case reports the unresolved references the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$results = [];
				foreach ((self::TARGET[(string)($config['filters']['schema'] ?? '')] ?? []) as $slug) {
					$results[] = ObjectServiceMockBuilder::objectEntity($this, ['slug' => $slug], 'uuid-' . $slug);
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);
		$service = new ConfigurationImportPreviewService($objects);

		$actual = [];
		foreach ($this->cases() as $name => [$bucket, $payload]) {
			$preview = $service->preview(['components' => [$bucket => ['subject' => $payload]]]);
			$actual[$name] = $preview['unresolvedReferences'];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('PREVIEW_REFERENCES_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$actual = json_decode($encoded, true);
		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($actual as $name => $references) {
			$this->assertSame($expected[$name], $references, $name);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
