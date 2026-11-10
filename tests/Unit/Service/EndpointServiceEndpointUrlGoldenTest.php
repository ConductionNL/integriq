<?php

/**
 * Golden characterization of EndpointService::generateEndpointUrl().
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
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Tests\Helpers\FindCallShape;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every endpoint shape generateEndpointUrl() can pick and fill, recorded
 * against a golden file: the url, and the endpoint lookups it made.
 *
 * Regenerate the golden file on the code it must describe with
 * ENDPOINT_URL_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 */
class EndpointServiceEndpointUrlGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/endpoint/generate-endpoint-url.json';

	/**
	 * Calls made to the collaborators in the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $log = [];

	/**
	 * The cases: the endpoint arrays found, the schema title and the call arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$plain = ['zaken', '{{id}}'];
		$sub = ['zaken', '{{ zaak_id }}', 'documenten', '{{id}}'];
		return [
			'no-endpoint' => ['endpoints' => [], 'title' => 'Zaak'],
			'plain' => ['endpoints' => [$plain], 'title' => 'Zaak'],
			'plain-with-parent' => ['endpoints' => [$plain], 'title' => 'Zaak', 'parentIds' => ['p-1']],
			'first-without-id-placeholder' => ['endpoints' => [['zaken'], $plain], 'title' => 'Zaak'],
			'none-with-id-placeholder' => ['endpoints' => [['zaken', '{{other}}'], ['b']], 'title' => 'Zaak'],
			'no-endpoint-array' => ['endpoints' => [null], 'title' => 'Zaak'],
			'sub-with-parent' => ['endpoints' => [$sub], 'title' => 'Zaak', 'parentIds' => ['p-1', 'p-2']],
			'sub-without-parent' => ['endpoints' => [$sub], 'title' => 'ZAAK'],
			'sub-other-schema' => ['endpoints' => [$sub], 'title' => 'Document', 'parentIds' => ['p-1']],
			'schema-placeholder-only' => ['endpoints' => [['zaken', '{{zaak_id}}']], 'title' => 'Zaak', 'parentIds' => ['p-1']],
			'two-id-placeholders' => ['endpoints' => [['a', '{{id}}', 'b', '{{id}}']], 'title' => 'Zaak'],
			'empty-placeholder' => ['endpoints' => [['a', '{{}}', '{{ id }}']], 'title' => 'Zaak'],
			'unknown-placeholders' => ['endpoints' => [['{{x}}', '{{id}}', '{{zaak_idx}}']], 'title' => 'Zaak', 'parentIds' => ['p-1']],
			'second-with-id-placeholder' => ['endpoints' => [['a'], ['b', '{{id}}'], ['c', '{{id}}']], 'title' => 'Zaak'],
			'register-looked-up' => ['endpoints' => [$plain], 'title' => 'Zaak', 'lookup' => true],
			'parent-null-entry' => ['endpoints' => [$sub], 'title' => 'Zaak', 'parentIds' => [null, 'p-2']],
			'empty-title' => ['endpoints' => [['x', '{{_id}}', '{{id}}']], 'title' => '', 'parentIds' => ['p-1']],
		];
	}//end cases()

	/**
	 * The service over recording collaborators for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return EndpointService
	 */
	private function service(array $case): EndpointService {
		$stored = new ObjectEntity();
		$stored->setRegister('7');
		$stored->setSchema('8');
		$mapper = $this->getMockBuilder(\stdClass::class)->addMethods(['find'])->getMock();
		$mapper->method('find')->willReturnCallback(FindCallShape::callback(
			function ($id) use ($stored) {
				$this->log[] = ['objectEntity.find', $id];
				return $stored;
			}
		));
		$openRegisters = $this->createMock(ORObjectService::class);
		$openRegisters->method('getMapper')->willReturn($mapper);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getOpenRegisters')->willReturn($openRegisters);

		$endpoints = [];
		foreach ($case['endpoints'] as $index => $endpointArray) {
			$body = [];
			if ($endpointArray !== null) {
				$body['endpointArray'] = $endpointArray;
			}

			$endpoints[] = ObjectServiceMockBuilder::objectEntity($this, $body, 'ep-' . $index);
		}

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('findAll')->willReturnCallback(
			function (array $config) use ($endpoints) {
				$this->log[] = ['findAll', $config];
				return ['results' => $endpoints];
			}
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.org');

		$args = [];
		foreach ((new ReflectionClass(EndpointService::class))->getConstructor()->getParameters() as $param) {
			$args[$param->getName()] = match ($param->getName()) {
				'objectService' => $objectService,
				'orObjectService' => $orObjectService,
				'urlGenerator' => $urlGenerator,
				default => $param->isOptional() === true ? $param->getDefaultValue() : $this->createMock((string)$param->getType()),
			};
		}

		return new EndpointService(...$args);
	}//end service()

	/**
	 * A schema mapper whose schema carries the case's title.
	 *
	 * @param string $title The schema title.
	 *
	 * @return SchemaMapper
	 */
	private function schemaMapper(string $title): SchemaMapper {
		$schema = new class($title) {
			/**
			 * @param string $title The title.
			 */
			public function __construct(private string $title) {
			}

			/**
			 * @return string
			 */
			public function getTitle(): string {
				return $this->title;
			}
		};
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('find')->willReturnCallback(FindCallShape::callback(
			function ($id) use ($schema) {
				$this->log[] = ['schema.find', $id];
				return $schema;
			}
		));
		return $mapper;
	}//end schemaMapper()

	/**
	 * Every case's url and collaborator calls as JSON text.
	 *
	 * @return array<string, string>
	 */
	private function record(): array {
		$out = [];
		foreach ($this->cases() as $name => $case) {
			$this->log = [];
			$register = 1;
			$schema = 2;
			if (($case['lookup'] ?? false) === true) {
				$register = null;
				$schema = null;
			}

			$url = $this->service($case)->generateEndpointUrl(
				id: 'obj-1',
				schemaMapper: $this->schemaMapper($case['title']),
				register: $register,
				schema: $schema,
				parentIds: ($case['parentIds'] ?? [])
			);
			$out[$name] = json_encode(['url' => $url, 'log' => $this->log], JSON_UNESCAPED_SLASHES);
		}//end foreach

		return $out;
	}//end record()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$record = $this->record();
		if (getenv('ENDPOINT_URL_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($record, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($record));
		foreach ($record as $name => $value) {
			$this->assertSame($golden[$name], $value, $name);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
