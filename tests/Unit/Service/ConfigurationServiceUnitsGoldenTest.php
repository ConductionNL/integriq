<?php

/**
 * Golden characterization of ConfigurationService's import order, component
 * grouping and job selection.
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
 * @spec openspec/specs/configuration-export-import/spec.md
 */

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\JobHandler;
use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\ConfigurationHandlers\RuleHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\ConfigurationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Three public entry points over one stored world, with every handler
 * replaced by a recorder that exports a payload unchanged and records each
 * import:
 *
 * - importConfiguration() of many OAS documents: the handler calls in order and the result;
 * - exportConfiguration() of one configuration whose entities carry every
 *   shape the component grouping distinguishes;
 * - exportRegister() with every include flag combination, over jobs whose
 *   arguments reference the register's endpoints, synchronizations and sources.
 *
 * Regenerate the golden file on the code it must describe with
 * CONFIGURATION_UNITS_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationService
 */
class ConfigurationServiceUnitsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/configuration/configuration-service-units.json';

	/**
	 * Every handler import, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $log = [];

	/**
	 * The stored objects by schema: uuid => data.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * The stored world: the slug maps, the configuration's shapes and the register's entities.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function world(): array {
		$in = ['configurations' => ['cfg-1']];
		$shapes = [
			'source-shape' => ['type' => 'api', 'authorizationHeader' => 'h'],
			'source-int-type' => ['type' => 5, 'authorizationHeader' => ''],
			'source-null-header' => ['type' => 'soap', 'authorizationHeader' => null],
			'source-and-target' => ['type' => 'api', 'authorizationHeader' => 'h', 'targetType' => 'register/schema'],
			'endpoint-shape' => ['targetType' => 'zgw'],
			'target-and-mapping' => ['targetType' => 'api', 'mapping' => []],
			'null-target' => ['targetType' => null],
			'null-target-and-mapping' => ['targetType' => null, 'mapping' => ['a' => 'b']],
			'mapping-shape' => ['mapping' => ['a' => 'b']],
			'null-mapping' => ['mapping' => null],
			'mapping-and-job' => ['mapping' => 'm', 'jobClass' => 'X'],
			'rule-shape' => ['type' => 'fetch_file', 'configuration' => []],
			'null-type-rule' => ['type' => null, 'configuration' => []],
			'type-without-more' => ['type' => 'lonely'],
			'rule-and-job' => ['type' => 'error', 'configuration' => 'x', 'jobClass' => 'X'],
			'job-shape' => ['jobClass' => 'OCA\\Job'],
			'null-job' => ['jobClass' => null],
			'source-type-only' => ['sourceType' => 'api'],
			'null-source-type' => ['sourceType' => null],
			'nothing' => ['name' => 'plain'],
		];

		$sources = ['src-uuid-1' => ['slug' => 'src-one']];
		foreach ($shapes as $slug => $shape) {
			$sources['shape-' . $slug] = $in + ['slug' => $slug] + $shape;
		}

		$sources['shape-no-slug'] = $in + ['type' => 'api', 'authorizationHeader' => 'h'];

		$jobs = [
			'job-sync' => ['arguments' => ['synchronizationId' => 'sync-uuid-1']],
			'job-sync-int' => ['arguments' => ['synchronizationId' => 5]],
			'job-endpoint' => ['arguments' => ['endpointId' => 'ep-uuid-1']],
			'job-endpoint-json' => ['arguments' => '{"endpointId":"ep-uuid-1"}'],
			'job-source' => ['arguments' => ['sourceId' => 'src-uuid-1']],
			'job-source-json' => ['arguments' => '{"sourceId":"src-uuid-1","other":1}'],
			'job-source-int' => ['arguments' => ['sourceId' => 77]],
			'job-source-numeric-text' => ['arguments' => ['sourceId' => '77']],
			'job-other-sync' => ['arguments' => ['synchronizationId' => 'sync-uuid-9']],
			'job-cross-kind' => ['arguments' => ['sourceId' => 'ep-uuid-1', 'endpointId' => 'sync-uuid-1', 'synchronizationId' => 'src-uuid-1']],
			'job-null-ref' => ['arguments' => ['synchronizationId' => null, 'endpointId' => 'nope']],
			'job-no-arguments' => ['name' => 'x'],
			'job-null-arguments' => ['arguments' => null],
			'job-bad-json' => ['arguments' => '{bad'],
			'job-json-string' => ['arguments' => '"sync-uuid-1"'],
			'job-int-arguments' => ['arguments' => 42],
			'job-list' => ['arguments' => ['sync-uuid-1', 'ep-uuid-1']],
		];

		$jobStore = [];
		foreach ($jobs as $slug => $job) {
			$jobStore['uuid-' . $slug] = ['slug' => $slug] + $job;
		}

		$jobStore['uuid-job-unslugged'] = ['arguments' => ['endpointId' => 'ep-uuid-1']];

		return [
			'source' => $sources,
			'endpoint' => [
				'ep-uuid-1' => $in + ['slug' => 'ep-one', 'targetType' => 'register/schema', 'targetId' => '7/1'],
				'ep-uuid-2' => ['slug' => 'ep-elsewhere', 'targetType' => 'register/schema', 'targetId' => '8/1'],
			],
			'synchronization' => [
				'sync-uuid-1' => ['slug' => 'sync-one', 'sourceType' => 'register/schema', 'sourceId' => '7/2', 'targetType' => 'api', 'targetId' => 'src-uuid-1'],
				'sync-uuid-3' => ['slug' => 'sync-int-source', 'sourceType' => 'register/schema', 'sourceId' => '7/3', 'targetType' => 'api', 'targetId' => 77],
				'5' => ['slug' => 'sync-five', 'sourceType' => 'api', 'sourceId' => 'src-uuid-9', 'targetType' => 'register/schema', 'targetId' => '7'],
				'sync-uuid-70' => ['slug' => 'sync-seventy', 'sourceType' => 'register/schema', 'sourceId' => '70/1', 'targetType' => 'register/schema', 'targetId' => '77'],
			],
			'mapping' => ['map-uuid-1' => ['slug' => 'map-one']],
			'rule' => ['rule-uuid-1' => ['slug' => 'rule-one']],
			'job' => $jobStore,
		];
	}//end world()

	/**
	 * The OAS documents imported.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function documents(): array {
		$every = [
			'jobs' => ['j1' => ['slug' => 'j1'], 'j2' => ['slug' => 'j2']],
			'synchronizations' => ['s1' => ['slug' => 's1']],
			'endpoints' => ['e1' => ['slug' => 'e1'], 7 => ['slug' => 'e7']],
			'rules' => ['r1' => ['slug' => 'r1']],
			'mappings' => ['m1' => ['slug' => 'm1']],
			'sources' => ['src-a' => ['slug' => 'src-a'], 'src-b' => ['name' => 'b']],
			'unknown' => ['u1' => ['slug' => 'u1']],
		];

		return [
			'no-components' => ['info' => []],
			'null-components' => ['components' => null],
			'empty-components' => ['components' => []],
			'string-components' => ['components' => 'sources'],
			'every-kind' => ['components' => $every],
			'sources-only' => ['components' => ['sources' => ['only' => ['slug' => 'only']]]],
			'null-kinds' => ['components' => ['sources' => null, 'jobs' => ['j' => ['slug' => 'j']], 'rules' => []]],
			'endpoints-and-jobs' => ['components' => ['endpoints' => ['e' => ['slug' => 'e']], 'jobs' => ['j' => ['slug' => 'j']]]],
			'mappings-and-synchronizations' => ['components' => ['synchronizations' => ['s' => ['slug' => 's']], 'mappings' => ['m' => ['slug' => 'm']]]],
			'rules-only' => ['components' => ['rules' => ['r' => ['slug' => 'r'], 'r2' => ['slug' => 'r2']]]],
			'handler-throws' => ['components' => ['sources' => ['s' => ['slug' => 's']], 'mappings' => ['boom' => ['slug' => 'boom']], 'jobs' => ['j' => ['slug' => 'j']]]],
		];
	}//end documents()

	/**
	 * A recording handler of one kind: export returns the stored payload, import records.
	 *
	 * @param string $class The handler class.
	 * @param string $kind The kind recorded.
	 *
	 * @return object
	 */
	private function handler(string $class, string $kind): object {
		$handler = $this->createMock($class);
		$handler->method('export')->willReturnCallback(
			fn (ObjectEntity $entity): array => $entity->getObject()
		);
		$handler->method('import')->willReturnCallback(
			function (array $data, array $mappings) use ($kind): ObjectEntity {
				$this->log[] = ['kind' => $kind, 'data' => $data, 'sourceSlugs' => $mappings['source']['slugToId'], 'mappingKinds' => array_keys($mappings)];
				if (($data['slug'] ?? null) === 'boom') {
					throw new RuntimeException('boom');
				}

				return ObjectServiceMockBuilder::objectEntity($this, [], $kind . ':' . ($data['slug'] ?? 'none'));
			}
		);

		return $handler;
	}//end handler()

	/**
	 * The service over the stored world and recording handlers.
	 *
	 * @return ConfigurationService
	 */
	private function service(): ConfigurationService {
		$this->store = $this->world();
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$results = [];
				foreach (($this->store[(string)($config['filters']['schema'] ?? '')] ?? []) as $uuid => $data) {
					$results[] = ObjectServiceMockBuilder::objectEntity($this, $data, (string)$uuid);
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new RuntimeException('no register'));
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willThrowException(new RuntimeException('no schema'));

		return new ConfigurationService(
			$objects,
			$registers,
			$schemas,
			$this->handler(EndpointHandler::class, 'endpoint'),
			$this->handler(SynchronizationHandler::class, 'synchronization'),
			$this->handler(MappingHandler::class, 'mapping'),
			$this->handler(JobHandler::class, 'job'),
			$this->handler(SourceHandler::class, 'source'),
			$this->handler(RuleHandler::class, 'rule'),
		);
	}//end service()

	/**
	 * What importing one document does: the handler calls and the result, or the error.
	 *
	 * @param array<string, mixed> $document The OAS document.
	 *
	 * @return array<string, mixed>
	 */
	private function imported(array $document): array {
		$service = $this->service();
		$this->log = [];
		try {
			$result = $service->importConfiguration($document);
		} catch (\Throwable $exception) {
			return ['throws' => get_class($exception), 'message' => $exception->getMessage(), 'calls' => $this->log];
		}

		$uuids = array_map(
			static fn (array $list): array => array_map(static fn (ObjectEntity $entity): ?string => $entity->getUuid(), $list),
			$result
		);

		return ['result' => $uuids, 'calls' => $this->log];
	}//end imported()

	/**
	 * The slugs of every component of a register export.
	 *
	 * @param array<int, bool> $flags includeEndpoints, includeSynchronizations, searchSource, searchTarget.
	 *
	 * @return array<string, list<int|string>>
	 */
	private function registerExport(array $flags): array {
		[$endpoints, $synchronizations, $source, $target] = $flags;
		$export = $this->service()->exportRegister('7', $endpoints, $synchronizations, $source, $target);

		return array_map(static fn (array $list): array => array_keys($list), $export['components']);
	}//end registerExport()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->documents() as $name => $document) {
			$actual['import:' . $name] = $this->imported(document: $document);
		}

		$actual['export-configuration'] = $this->service()->exportConfiguration('cfg-1');
		$actual['export-configuration-unknown'] = $this->service()->exportConfiguration('cfg-none');

		$flagSets = [
			'all' => [true, true, true, true],
			'no-endpoints' => [false, true, true, true],
			'no-synchronizations' => [true, false, true, true],
			'nothing' => [false, false, true, true],
			'sync-targets-only' => [false, true, false, true],
			'sync-sources-only' => [false, true, true, false],
			'sync-search-off' => [false, true, false, false],
		];
		foreach ($flagSets as $name => $flags) {
			$actual['export-register:' . $name] = $this->registerExport(flags: $flags);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('CONFIGURATION_UNITS_GOLDEN') === '1') {
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
