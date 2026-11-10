<?php

/**
 * Golden characterization of ConfigurationService::exportConfiguration().
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
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * One configuration with every entity kind, register/schema and api sides,
 * a slugless entity and entities of another configuration, exported and
 * recorded against a golden file together with the register and schema
 * lookups. Regenerate it on the code it must describe with
 * CONFIGURATION_EXPORT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\ConfigurationService
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\JobHandler
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\MappingHandler
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\RuleHandler
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\SourceHandler
 * @uses   \OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 */
class ConfigurationServiceExportConfigurationGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/configuration/export-configuration.json';

	/**
	 * Every register and schema lookup, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The stored objects by schema: uuid => data.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function store(): array {
		$in = ['configurations' => ['cfg-1']];
		$out = ['configurations' => ['cfg-2']];
		return [
			'source' => [
				'src-1' => $in + ['slug' => 'source-one', 'type' => 'api', 'authorizationHeader' => 'Authorization', 'location' => 'https://a.invalid'],
				'src-2' => $in + ['type' => 'database', 'authorizationHeader' => '', 'location' => 'https://b.invalid'],
				'src-3' => $out + ['slug' => 'other-source', 'type' => 'api', 'authorizationHeader' => ''],
			],
			'endpoint' => [
				'ep-1' => $in + ['slug' => 'endpoint-one', 'targetType' => 'register/schema', 'targetId' => '7/11', 'rules' => ['rule-1']],
				'ep-2' => $in + ['slug' => 'endpoint-two', 'targetType' => 'api', 'targetId' => 'src-1'],
				'ep-3' => $in + ['slug' => 'endpoint-three', 'targetType' => 'register/schema', 'targetId' => 'noslash'],
			],
			'mapping' => [
				'map-1' => $in + ['slug' => 'mapping-one', 'mapping' => ['a' => 'b']],
			],
			'rule' => [
				'rule-1' => $in + ['slug' => 'rule-one', 'type' => 'mapping', 'configuration' => ['mapping' => 'map-1']],
			],
			'job' => [
				'job-1' => $in + ['slug' => 'job-one', 'jobClass' => 'X', 'arguments' => ['synchronizationId' => 'sync-1']],
			],
			'synchronization' => [
				'sync-1' => $in + ['slug' => 'sync-one', 'sourceType' => 'register/schema', 'sourceId' => '8/12', 'targetType' => 'register/schema', 'targetId' => '7/13', 'sourceTargetMapping' => 'map-1'],
				'sync-2' => $in + ['sourceType' => 'api', 'sourceId' => 'src-1', 'targetType' => 'register/schema', 'targetId' => '99/98'],
			],
		];
	}//end store()

	/**
	 * A register or schema lookup answer.
	 *
	 * @param string $kind The mapper kind.
	 * @param mixed $id The id looked up.
	 *
	 * @return object
	 */
	private function found(string $kind, mixed $id): object {
		$this->log[] = [$kind . '.find', $id];
		if ((string)$id === '99' || (string)$id === '98') {
			throw new RuntimeException('missing');
		}

		return new class ((int)$id, $kind . '-' . $id) {
			/**
			 * @param int $id The id.
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
	}//end found()

	/**
	 * The export of one configuration matches the golden file.
	 *
	 * @return void
	 */
	public function testExportConfigurationMatchesTheGoldenFile(): void {
		$store = $this->store();
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($store): array {
				$schema = (string)($config['filters']['schema'] ?? '');
				$results = [];
				foreach (($store[$schema] ?? []) as $uuid => $data) {
					$results[] = ObjectServiceMockBuilder::objectEntity($this, $data, (string)$uuid);
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(fn ($id): object => $this->found(kind: 'register', id: $id));
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(fn ($id): object => $this->found(kind: 'schema', id: $id));

		$registry = new SensitiveFieldRegistry();
		$service = new ConfigurationService(
			$objects,
			$registers,
			$schemas,
			new EndpointHandler($objects, $registry),
			new SynchronizationHandler($objects, $registry),
			new MappingHandler($objects, $registry),
			new JobHandler($objects, $registry),
			new SourceHandler($objects, $registry),
			new RuleHandler($objects, $registry),
		);

		$actual = ['export' => $service->exportConfiguration('cfg-1'), 'lookups' => $this->log];
		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('CONFIGURATION_EXPORT_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);
	}//end testExportConfigurationMatchesTheGoldenFile()
}//end class
