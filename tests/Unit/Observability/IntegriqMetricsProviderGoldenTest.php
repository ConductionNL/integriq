<?php

/**
 * Golden master of IntegriqMetricsProvider::metrics().
 *
 * Every case scripts the schemas, registers and objects OpenRegister serves, and
 * records the samples metrics() returns plus every searchObjects() call it made
 * (query and the system-context flags). The cases cover each schema the
 * provider reads, an unknown schema, a schema without id, a schema in no
 * register, results that are not an array, a failing search and a missing
 * ObjectService. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/prometheus-metrics/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability;

use OCA\Integriq\Observability\IntegriqMetricsProvider;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Recorded metrics() output per scripted OpenRegister world.
 *
 * @covers \OCA\Integriq\Observability\IntegriqMetricsProvider
 */
class IntegriqMetricsProviderGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/integriq-metrics-provider.json';

	/**
	 * The scripted worlds, by case name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$schemas = [
			['id' => 11, 'title' => 'Source'],
			['id' => 12, 'title' => 'CallLog'],
			['id' => 13, 'title' => 'SynchronizationLog'],
			['id' => 14, 'title' => 'API Product'],
		];
		$registers = [
			['id' => 1, 'schemas' => [11, ['id' => 12], '14']],
			['id' => 2, 'schemas' => [['id' => '12'], 13, 99]],
			['id' => null, 'schemas' => [11]],
			['id' => 3, 'schemas' => 'not-a-list'],
		];
		$objects = [
			'1/11' => [
				['name' => 'alpha', 'type' => 'soap', 'circuitBreakerState' => 'open'],
				['name' => 'beta', 'circuitBreakerState' => 'closed'],
				['type' => 'rest'],
			],
			'1/12' => [
				['direction' => 'inbound', 'product' => 'p-1', 'responseTime' => 120, 'statusCode' => 200],
				['direction' => 'inbound', 'product' => 'p-1', 'responseTime' => '480', 'statusCode' => 500],
				['direction' => 'outbound', 'product' => 'p-1', 'responseTime' => 9000, 'statusCode' => 502],
			],
			'2/12' => [
				['direction' => 'inbound', 'product' => 'p-2', 'responseTime' => 'n/a', 'statusCode' => 404],
				['direction' => 'inbound', 'product' => 'p-1', 'responseTime' => 30],
				['product' => 'p-2', 'responseTime' => 70, 'statusCode' => 503],
			],
			'2/13' => [['result' => 'success'], ['result' => 'error'], ['result' => 'success'], []],
			'1/14' => [
				['uuid' => 'p-1', 'productSlug' => 'orders', 'title' => 'Orders'],
				['uuid' => 'p-2'],
				['uuid' => 'p-3', 'productSlug' => 'quiet'],
			],
		];

		return [
			'full' => ['schemas' => $schemas, 'registers' => $registers, 'objects' => $objects],
			'no-schemas' => ['schemas' => [], 'registers' => $registers, 'objects' => $objects],
			'schema-without-id' => [
				'schemas' => [['title' => 'Source'], ['title' => 'CallLog', 'id' => null], ['id' => 14, 'title' => 'API Product']],
				'registers' => $registers,
				'objects' => $objects,
			],
			'no-registers' => ['schemas' => $schemas, 'registers' => [], 'objects' => $objects],
			'not-an-array' => ['schemas' => $schemas, 'registers' => $registers, 'objects' => $objects, 'scalar' => ['1/12', '1/11', '1/14']],
			'search-fails' => ['schemas' => $schemas, 'registers' => $registers, 'objects' => $objects, 'throw' => ['2/12']],
			'search-fails-sources' => ['schemas' => $schemas, 'registers' => $registers, 'objects' => $objects, 'throw' => ['1/11']],
			'search-fails-products' => ['schemas' => $schemas, 'registers' => $registers, 'objects' => $objects, 'throw' => ['1/14']],
			'no-object-service' => ['schemas' => $schemas, 'registers' => $registers, 'objects' => $objects, 'noService' => true],
		];
	}//end worlds()

	/**
	 * Run metrics() in one world and record what it returned and asked for.
	 *
	 * @param array<string, mixed> $world The scripted world.
	 *
	 * @return array<string, mixed>
	 */
	private function record(array $world): array {
		$calls   = new \ArrayObject();
		$service = new class($world, $calls) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $world The scripted world.
			 * @param \ArrayObject         $calls Recorded calls.
			 */
			public function __construct(private array $world, private \ArrayObject $calls) {
			}//end __construct()

			/**
			 * Serve the scripted objects of one register and schema.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param boolean              $_rbac         RBAC flag.
			 * @param boolean              $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$key = ($query['@self']['register'] ?? '?') . '/' . ($query['@self']['schema'] ?? '?');
				if (in_array($key, ($this->world['throw'] ?? []), true) === true) {
					throw new RuntimeException('search failed for ' . $key);
				}

				if (in_array($key, ($this->world['scalar'] ?? []), true) === true) {
					return 'not-an-array';
				}

				return ($this->world['objects'][$key] ?? []);
			}//end searchObjects()
		};

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn($world['schemas']);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn($world['registers']);

		$objectService = (($world['noService'] ?? false) === true) ? null : $service;
		$container     = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $objectService,
				'OCA\OpenRegister\Db\SchemaMapper' => $schemaMapper,
				'OCA\OpenRegister\Db\RegisterMapper' => $registerMapper,
				default => null,
			}
		);

		$warnings = [];
		$logger   = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings) {
				$warnings[] = $message;
			}
		);

		$samples = (new IntegriqMetricsProvider($container, $logger))->metrics();

		return [
			'samples' => array_map(
				static fn (object $s): array => ['name' => $s->name, 'type' => $s->type, 'help' => $s->help, 'samples' => $s->samples],
				$samples
			),
			'calls' => $calls->getArrayCopy(),
			'warnings' => $warnings,
		];
	}//end run()

	/**
	 * Every world against the recorded fixture, case by case.
	 *
	 * @return void
	 */
	public function testMetricsMatchTheRecording(): void {
		$found = [];
		foreach ($this->worlds() as $name => $world) {
			$found[$name] = $this->record(world: $world);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys($golden), array_keys($found));
		foreach ($golden as $name => $expected) {
			$this->assertSame($expected, json_decode(json_encode($found[$name], JSON_PRESERVE_ZERO_FRACTION), true), 'case ' . $name);
		}
	}//end testMetricsMatchTheRecording()
}//end class
