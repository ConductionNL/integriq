<?php

/**
 * Characterization tests for ConfigurationService::exportRegister().
 *
 * They pin which entities a register export collects, the order and keys of the
 * exported components, that register and schema slugs are resolved before any entity
 * is exported, and that mappings referenced by rules and by other mappings follow.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\JobHandler;
use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\ConfigurationHandlers\RuleHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\ConfigurationService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * A handler export that records what it saw and follows the entity's `refs` as mapping ids.
 */
trait RecordingExport {

	/**
	 * Export an entity as its uuid, the register slugs known at that moment, and add its refs.
	 *
	 * @param Entity $entity     The entity.
	 * @param array  $mappings   The id-to-slug mappings.
	 * @param array  $mappingIds Mapping ids to export next; the entity's `refs` are added.
	 *
	 * @return array The export.
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array {
		foreach (($entity->getObject()['refs'] ?? []) as $ref) {
			$mappingIds[] = $ref;
		}

		return ['uuid' => $entity->getUuid(), 'registers' => ($mappings['register']['idToSlug'] ?? []), 'schemas' => ($mappings['schema']['idToSlug'] ?? [])];
	}//end export()
}//end trait

/**
 * The register export.
 */
class ConfigurationServiceExportRegisterTest extends TestCase {

	/**
	 * Build the service over the given entities per schema.
	 *
	 * @param array<string, array<string, array>> $entities The entity bodies by schema, then by uuid.
	 *
	 * @return ConfigurationService
	 */
	private function service(array $entities): ConfigurationService {
		$or = ObjectServiceMockBuilder::make($this);
		$or->method('findAll')->willReturnCallback(
			function (...$args) use ($entities) {
				$schema = ($args[0]['filters']['schema'] ?? '');
				$rows = [];
				foreach (($entities[$schema] ?? []) as $uuid => $body) {
					$rows[] = ObjectServiceMockBuilder::objectEntity($this, $body, $uuid);
				}

				return ['results' => $rows, 'total' => count($rows)];
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id) {
				if ($id === '404') {
					throw new \RuntimeException('no register');
				}

				return $this->slugged(id: (int)$id, slug: 'reg' . $id);
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(fn ($id) => $this->slugged(id: (int)$id, slug: 'sch' . $id));

		$registry = new SensitiveFieldRegistry();

		return new ConfigurationService(
			$or,
			$registerMapper,
			$schemaMapper,
			new class($or, $registry) extends EndpointHandler {
				use RecordingExport;
			},
			new class($or, $registry) extends SynchronizationHandler {
				use RecordingExport;
			},
			new class($or, $registry) extends MappingHandler {
				use RecordingExport;
			},
			new class($or, $registry) extends JobHandler {
				use RecordingExport;
			},
			new class($or, $registry) extends SourceHandler {
				use RecordingExport;
			},
			new class($or, $registry) extends RuleHandler {
				use RecordingExport;
			},
		);
	}//end service()

	/**
	 * A register or schema stub with an id and a slug.
	 *
	 * @param int    $id   The id.
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function slugged(int $id, string $slug): object {
		return new class($id, $slug) {
			/**
			 * @param int    $id   The id.
			 * @param string $slug The slug.
			 */
			public function __construct(private int $id, private string $slug) {
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end slugged()

	/**
	 * The fixture: a register 5 with one endpoint and two synchronizations, and the
	 * sources, rules, mappings and jobs they reach.
	 *
	 * @return array<string, array<string, array>>
	 */
	private function fixture(): array {
		return [
			'endpoint' => [
				'e-1' => ['slug' => 'endpoint-one', 'targetType' => 'register/schema', 'targetId' => '5/7', 'inputMapping' => 'm-1', 'outputMapping' => 'm-6', 'rules' => ['r-1']],
				'e-2' => ['slug' => 'other', 'targetType' => 'register/schema', 'targetId' => '9/1', 'rules' => ['r-9']],
			],
			'synchronization' => [
				's-1' => [
					'slug' => 'sync-one',
					'sourceType' => 'api',
					'sourceId' => 'src-1',
					'targetType' => 'register/schema',
					'targetId' => '5/8',
					'sourceTargetMapping' => 'm-2',
					'actions' => ['r-2', 'r-1'],
				],
				's-2' => ['sourceType' => 'register/schema', 'sourceId' => '5/3', 'targetType' => 'api', 'targetId' => 'src-2', 'targetSourceMapping' => 'm-2'],
			],
			'source' => [
				'src-1' => ['slug' => 'source-one'],
				'src-2' => [],
				'src-9' => ['slug' => 'unrelated'],
			],
			'rule' => [
				'r-1' => ['slug' => 'rule-one'],
				'r-2' => ['slug' => 'rule-two', 'refs' => ['m-3']],
				'r-9' => ['slug' => 'unrelated'],
			],
			'mapping' => [
				'm-1' => ['slug' => 'mapping-one'],
				'm-2' => ['slug' => 'mapping-two'],
				'm-3' => ['slug' => 'mapping-three', 'refs' => ['m-4', 'm-4']],
				'm-4' => ['slug' => 'mapping-one', 'refs' => ['m-5']],
				'm-5' => [],
				'm-6' => ['slug' => 'mapping-six'],
				'm-9' => ['slug' => 'unrelated'],
			],
			'job' => [
				'j-1' => ['slug' => 'job-one', 'arguments' => ['synchronizationId' => 's-1']],
				'j-2' => ['arguments' => json_encode(['sourceId' => 'src-2'])],
				'j-3' => ['slug' => 'job-endpoint', 'arguments' => ['endpointId' => 'e-1']],
				'j-9' => ['slug' => 'unrelated', 'arguments' => ['endpointId' => 'e-2']],
			],
		];
	}//end fixture()

	/**
	 * The full export of register 5.
	 *
	 * @return void
	 */
	public function testTheRegisterExport(): void {
		$out = $this->service($this->fixture())->exportRegister(registerId: '5');

		$this->assertSame(['components'], array_keys($out));
		$components = $out['components'];
		$this->assertSame(['mappings', 'sources', 'rules', 'endpoints', 'synchronizations', 'jobs'], array_keys($components));

		$this->assertSame(['sync-one', 's-2'], array_keys($components['synchronizations']));
		$this->assertSame(['endpoint-one'], array_keys($components['endpoints']));
		$this->assertSame(['source-one', 'src-2'], array_keys($components['sources']));
		$this->assertSame(['rule-one', 'rule-two'], array_keys($components['rules']));
		$this->assertSame(['mapping-one', 'mapping-two', 'mapping-three', 'mapping-six', 'm-5'], array_keys($components['mappings']));
		$this->assertSame('m-4', $components['mappings']['mapping-one']['uuid']);
		$this->assertSame(['job-one', 'j-2', 'job-endpoint'], array_keys($components['jobs']));

		$registers = ['5' => 'reg5'];
		$schemas = ['7' => 'sch7', '8' => 'sch8', '3' => 'sch3'];
		$this->assertSame($registers, $components['endpoints']['endpoint-one']['registers']);
		$this->assertSame($schemas, $components['synchronizations']['sync-one']['schemas']);
	}//end testTheRegisterExport()

	/**
	 * Without endpoints, their rules, mappings and jobs are not exported.
	 *
	 * @return void
	 */
	public function testWithoutEndpoints(): void {
		$out = $this->service($this->fixture())->exportRegister(registerId: '5', includeEndpoints: false);

		$components = $out['components'];
		$this->assertSame([], $components['endpoints']);
		$this->assertSame(['rule-one', 'rule-two'], array_keys($components['rules']));
		$this->assertSame(['job-one', 'j-2'], array_keys($components['jobs']));
	}//end testWithoutEndpoints()

	/**
	 * Without synchronizations and with a target search only, the export narrows to endpoints.
	 *
	 * @return void
	 */
	public function testWithoutSynchronizations(): void {
		$out = $this->service($this->fixture())->exportRegister(registerId: '5', includeSynchronizations: false);

		$components = $out['components'];
		$this->assertSame([], $components['synchronizations']);
		$this->assertSame([], $components['sources']);
		$this->assertSame(['mapping-one', 'mapping-six'], array_keys($components['mappings']));
		$this->assertSame(['rule-one'], array_keys($components['rules']));
		$this->assertSame('m-1', $components['mappings']['mapping-one']['uuid']);
		$this->assertSame(['job-endpoint'], array_keys($components['jobs']));
	}//end testWithoutSynchronizations()

	/**
	 * The source and target searches each select synchronizations.
	 *
	 * @return void
	 */
	public function testSourceAndTargetSearch(): void {
		$service = $this->service($this->fixture());

		$this->assertSame(['sync-one'], array_keys($service->exportRegister(registerId: '5', searchSource: false)['components']['synchronizations']));
		$this->assertSame(['s-2'], array_keys($service->exportRegister(registerId: '5', searchTarget: false)['components']['synchronizations']));
	}//end testSourceAndTargetSearch()

	/**
	 * A register with nothing connected exports empty components and no jobs.
	 *
	 * @return void
	 */
	public function testAnEmptyRegister(): void {
		$out = $this->service($this->fixture())->exportRegister(registerId: '404');

		$this->assertSame(
			['components' => ['mappings' => [], 'sources' => [], 'rules' => [], 'endpoints' => [], 'synchronizations' => [], 'jobs' => []]],
			$out
		);
	}//end testAnEmptyRegister()
}//end class
