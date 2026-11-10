<?php

/**
 * Integriq PDOK addresses schema test
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Connectors;

use OCA\Integriq\Connectors\PdokConnector;
use OCA\Integriq\Repair\InitializeRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * What the PDOK write-through saves fits the integriq/addresses schema.
 */
class PdokAddressSchemaTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The integriq register, after every register.d fragment is merged in, lists the addresses schema.
	 *
	 * @return void
	 */
	public function testTheMergedRegisterHoldsTheAddressesSchema(): void {
		$descriptor = $this->mergedDescriptor();

		$this->assertContains(needle: 'addresses', haystack: $descriptor['components']['registers']['integriq']['schemas']);
		$this->assertSame(expected: 'addresses', actual: $descriptor['components']['schemas']['addresses']['slug']);
	}//end testTheMergedRegisterHoldsTheAddressesSchema()

	/**
	 * Every object the connector saves names exactly the schema's properties, with values of their type.
	 *
	 * @param string $fixture The PDOK response fixture.
	 *
	 * @return void
	 *
	 * @dataProvider fixtures
	 */
	public function testTheWriteMapsOntoTheSchema(string $fixture): void {
		$schema = $this->mergedDescriptor()['components']['schemas']['addresses'];
		$saved  = $this->savedObjects(fixture: $fixture);

		$this->assertNotEmpty(actual: $saved);
		foreach ($saved as $save) {
			$this->assertSame(expected: ['integriq', 'addresses'], actual: [$save['register'], $save['schema']]);
			$this->assertEqualsCanonicalizing(expected: array_keys($schema['properties']), actual: array_keys($save['object']));

			foreach ($schema['required'] as $required) {
				$this->assertNotNull(actual: $save['object'][$required], message: $required . ' is required');
			}

			foreach ($save['object'] as $key => $value) {
				if ($value === null) {
					$this->assertNotContains(needle: $key, haystack: $schema['required'], message: $key . ' is required but null');
					continue;
				}

				$this->assertSame(expected: $schema['properties'][$key]['type'], actual: $this->jsonType(value: $value), message: $key);
				if (is_array($value) === true && array_is_list($value) === true) {
					foreach ($value as $item) {
						$this->assertSame(expected: $schema['properties'][$key]['items']['type'], actual: $this->jsonType(value: $item), message: $key . ' item');
					}
				}
			}

			$this->assertSame(expected: 'Point', actual: $save['object']['location']['type']);
			$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', string: $save['object']['fetchedAt']);
		}//end foreach
	}//end testTheWriteMapsOntoTheSchema()

	/**
	 * The PDOK response fixtures.
	 *
	 * @return array<string, array{string}>
	 */
	public static function fixtures(): array {
		return [
			'address' => ['lauriergracht'],
			'address with letter' => ['stadhuisplein-tilburg'],
			'place without street' => ['woonplaats-tilburg'],
		];
	}//end fixtures()

	/**
	 * Run one fixture through the connector's normalise and write-through, recording every save.
	 *
	 * @param string $fixture The fixture name.
	 *
	 * @return list<array{object: array, register: ?string, schema: ?string}>
	 */
	private function savedObjects(string $fixture): array {
		$saves = [];
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn(value: []);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null) use (&$saves): ObjectEntity {
				$saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return new ObjectEntity();
			}
		);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn(value: $this->createMock(originalClassName: ICache::class));
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(value: $objectService);
		$connector = new PdokConnector(
			clientService: $this->createMock(originalClassName: IClientService::class),
			cacheFactory: $cacheFactory,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			container: $container
		);

		$raw = json_decode(json: (string)file_get_contents(filename: __DIR__ . '/../../fixtures/pdok/fixture-' . $fixture . '.json'), associative: true);
		foreach ($raw['response']['docs'] as $doc) {
			$connector->writeThrough(doc: $connector->normalize(pdokDoc: $doc));
		}

		return $saves;
	}//end savedObjects()

	/**
	 * The register descriptor with every register.d fragment merged in, as InitializeRegister merges them.
	 *
	 * @return array
	 */
	private function mergedDescriptor(): array {
		$merge      = new ReflectionMethod(objectOrMethod: InitializeRegister::class, method: 'deepMergeConfig');
		$descriptor = json_decode(json: (string)file_get_contents(filename: self::SETTINGS . '/integriq_register.json'), associative: true);
		$fragments  = glob(pattern: self::SETTINGS . '/register.d/*.json');
		sort(array: $fragments);
		foreach ($fragments as $fragment) {
			$fragmentData = json_decode(json: (string)file_get_contents(filename: $fragment), associative: true);
			$descriptor   = $merge->invokeArgs(object: null, args: [$descriptor, $fragmentData]);
		}

		return $descriptor;
	}//end mergedDescriptor()

	/**
	 * The JSON Schema type of a decoded value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function jsonType(mixed $value): string {
		return match (true) {
			is_string($value) => 'string',
			is_bool($value) => 'boolean',
			is_int($value) => 'integer',
			is_float($value) => 'number',
			is_array($value) && array_is_list($value) => 'array',
			default => 'object',
		};
	}//end jsonType()
}//end class
