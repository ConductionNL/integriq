<?php

/**
 * Unit tests for OpenApiPublishService: the product and gateway documents are
 * valid OpenAPI 3.1, describe the gateway paths with the OpenRegister schema
 * and the stored vendor schemas, carry the authentication rule's scheme, mark
 * a deprecated product, and never contain a source location.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\OpenApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\OpenApi;

use OCA\Integriq\Service\EndpointTargetResolver;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiPublishService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use OCA\Integriq\Service\EndpointService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the published OpenAPI documents.
 */
#[CoversClass(OpenApiPublishService::class)]
#[UsesClass(OpenApiDocumentNormaliser::class)]
#[UsesClass(EndpointTargetResolver::class)]
#[UsesClass(EndpointService::class)]
class OpenApiPublishServiceTest extends TestCase {

	private const LOCATION = 'https://petstore.example.com/v1';

	private const SERVER = 'https://gemeente.example/index.php/apps/integriq/api/endpoint';

	/**
	 * The rows OpenRegister holds, by schema.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * Build the default rows: a petstore source, an imported and a register endpoint, an API key rule, two products.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$vendor = json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/openapi/petstore-swagger-2.0.json'), true, flags: JSON_THROW_ON_ERROR);
		// A vendor description that names its upstream address must not publish it.
		$vendor['paths']['/pets/{petId}']['get']['description'] = 'Proxied from '.self::LOCATION.'/pets.';
		$this->rows = [
			'source' => [['uuid' => 'src-1', 'name' => 'Petstore', 'location' => self::LOCATION, 'type' => 'api', 'openApiDocument' => $vendor]],
			'endpoint' => [
				[
					'uuid' => 'ep-pet',
					'slug' => 'vendor-show-pet',
					'name' => 'showPetById',
					// As the import writes it: the gateway route in `endpoint`,
					// the vendor path in `upstreamPath`.
					'endpoint' => 'vendor/pets/{{petId}}',
					'upstreamPath' => 'pets/{{petId}}',
					'endpointArray' => ['vendor', 'pets', '{{petId}}'],
					'method' => 'GET',
					'targetType' => 'api',
					'targetId' => 'src-1',
					'rules' => ['rule-key'],
				],
				[
					'uuid' => 'ep-zaak',
					'name' => 'Zaken',
					'endpoint' => 'zaken',
					'endpointArray' => ['zaken'],
					'method' => 'GET',
					'targetType' => 'register/schema',
					'targetId' => 'zaken-register/zaak',
					'rules' => [],
				],
				['uuid' => 'ep-hidden', 'name' => 'Not in a product', 'endpoint' => 'internal', 'endpointArray' => ['internal'], 'method' => 'GET', 'targetType' => 'api', 'targetId' => 'src-1'],
			],
			'rule' => [
				['uuid' => 'rule-key', 'type' => 'authentication', 'configuration' => ['authentication' => ['type' => 'apikey', 'header' => 'X-Api-Key', 'keys' => ['never-published']]]],
			],
			'api_product' => [
				['uuid' => 'prod-1', 'name' => 'Zaken API', 'version' => '1.0.0', 'visibility' => 'public', 'status' => 'active', 'endpoints' => ['ep-zaak', 'vendor-show-pet']],
				['uuid' => 'prod-old', 'name' => 'Old', 'version' => '0.9.0', 'visibility' => 'public', 'status' => 'deprecated', 'sunsetDate' => '2027-01-01T00:00:00+00:00', 'endpoints' => ['ep-hidden']],
				['uuid' => 'prod-private', 'name' => 'Private', 'version' => '1.0.0', 'visibility' => 'private', 'status' => 'active', 'endpoints' => ['ep-hidden']],
			],
		];
	}//end setUp()

	/**
	 * An entity holding a row.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return ObjectEntity The entity.
	 */
	private static function entity(array $row): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string) $row['uuid']);
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * The service, over mocked OpenRegister boundaries.
	 *
	 * @return OpenApiPublishService The service.
	 */
	private function service(): OpenApiPublishService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = []): array => ['results' => array_map(self::entity(...), ($this->rows[$config['filters']['schema']] ?? []))]
		);
		$objects->method('find')->willReturnCallback(
			function (mixed $id, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				foreach (($this->rows[$schema] ?? []) as $row) {
					if ($row['uuid'] === $id) {
						return self::entity(row: $row);
					}
				}

				return null;
			}
		);

		// OpenRegister's Register and Schema entities, as the accessors the resolver and the service read.
		$register = new class {
			/**
			 * The register id.
			 *
			 * @return int The id.
			 */
			public function getId(): int {
				return 3;
			}

			/**
			 * The register's schema ids.
			 *
			 * @return array<int,int> The ids.
			 */
			public function getSchemas(): array {
				return [7];
			}
		};
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$schema = new class {
			/**
			 * The schema id.
			 *
			 * @return int The id.
			 */
			public function getId(): int {
				return 7;
			}

			/**
			 * The schema slug.
			 *
			 * @return string The slug.
			 */
			public function getSlug(): string {
				return 'zaak';
			}

			/**
			 * The schema title.
			 *
			 * @return string The title.
			 */
			public function getTitle(): string {
				return 'Zaak';
			}

			/**
			 * The schema properties.
			 *
			 * @return array<string,array<string,string>> The properties.
			 */
			public function getProperties(): array {
				return ['identificatie' => ['type' => 'string'], 'omschrijving' => ['type' => 'string']];
			}

			/**
			 * The required properties.
			 *
			 * @return array<int,string> The names.
			 */
			public function getRequired(): array {
				return ['identificatie'];
			}
		};
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		return new OpenApiPublishService(
			objects: $objects,
			normaliser: new OpenApiDocumentNormaliser(),
			targets: new EndpointTargetResolver(registerMapper: $registers, schemaMapper: $schemas),
			schemaMapper: $schemas,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Assert a document is valid OpenAPI 3.1 against the official 3.1 meta-schema.
	 *
	 * @param array<string,mixed> $document The document.
	 *
	 * @return void
	 */
	private function assertValidOpenApi31(array $document): void {
		// The official OpenAPI 3.1 meta-schema. Its Schema Object is `$dynamicRef: "#meta"`, which opis
		// resolves to the document root instead of the `$defs/schema` anchor; pinned to that anchor here.
		$meta = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', (string) file_get_contents(__DIR__.'/../../../fixtures/openapi/oas-3.1-schema.json'));
		$schema = json_decode($meta);
		$validator = new Validator();
		// Validate, never fill: the meta-schema's defaults (style, explode) would otherwise be written into the data.
		$validator->parser()->setOption('allowDefaults', false);
		$result = $validator->validate(json_decode((string) json_encode($document)), $schema);

		$errors = [];
		if ($result->error() !== null) {
			$errors = (new ErrorFormatter())->format($result->error(), true);
		}

		$this->assertSame([], $errors, 'Not a valid OpenAPI 3.1 document.');
	}//end assertValidOpenApi31()

	/**
	 * A public product with a register and an imported endpoint: both paths, their schemas, the API key scheme, no upstream address.
	 *
	 * @return void
	 */
	public function testAProductDocumentDescribesItsEndpointsWithoutTheSourceLocation(): void {
		$document = $this->service()->productDocument(product: self::entity(row: $this->rows['api_product'][0]), serverUrl: self::SERVER);
		$this->assertValidOpenApi31(document: $document);
		$encoded = (string) json_encode($document);

		$this->assertSame('3.1.0', $document['openapi']);
		$this->assertSame('Zaken API', $document['info']['title']);
		$this->assertSame([['url' => self::SERVER]], $document['servers']);
		$this->assertSame(['/vendor/pets/{petId}', '/zaken'], array_keys($document['paths']));

		$this->assertStringNotContainsString('petstore.example.com', $encoded);
		$this->assertStringNotContainsString('never-published', $encoded);

		$zaken = $document['paths']['/zaken']['get'];
		$this->assertSame('#/components/schemas/register.zaak', $zaken['responses']['200']['content']['application/json']['schema']['properties']['results']['items']['$ref']);
		$zaak = $document['components']['schemas']['register.zaak'];
		$this->assertSame(['identificatie'], $zaak['required']);
		$this->assertSame(['identificatie' => ['type' => 'string'], 'omschrijving' => ['type' => 'string']], (array) $zaak['properties']);

		$pet = $document['paths']['/vendor/pets/{petId}']['get'];
		$this->assertSame('vendor-show-pet', $pet['operationId']);
		$this->assertSame('Info for a specific pet', $pet['summary']);
		$this->assertSame([['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], $pet['parameters']);
		$petSchema = $pet['responses']['200']['content']['application/json']['schema']['$ref'];
		$this->assertStringEndsWith('.Pet', $petSchema);
		$this->assertArrayHasKey(substr($petSchema, strlen('#/components/schemas/')), $document['components']['schemas']);
		$this->assertSame([['apiKey.X-Api-Key' => []]], $pet['security']);
		$this->assertSame(['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'], $document['components']['securitySchemes']['apiKey.X-Api-Key']);
		$this->assertArrayNotHasKey('security', $zaken);
		$this->assertArrayNotHasKey('deprecated', $pet);
	}//end testAProductDocumentDescribesItsEndpointsWithoutTheSourceLocation()

	/**
	 * A deprecated product marks every operation deprecated and carries its sunset date.
	 *
	 * @return void
	 */
	public function testADeprecatedProductCarriesItsSunset(): void {
		$document = $this->service()->productDocument(product: self::entity(row: $this->rows['api_product'][1]), serverUrl: self::SERVER);
		$this->assertValidOpenApi31(document: $document);

		$this->assertSame('2027-01-01T00:00:00+00:00', $document['info']['x-sunset']);
		$this->assertTrue($document['paths']['/internal']['get']['deprecated']);
		$this->assertStringNotContainsString('petstore.example.com', (string) json_encode($document));
	}//end testADeprecatedProductCarriesItsSunset()

	/**
	 * The gateway document holds only the endpoints of public, active products.
	 *
	 * @return void
	 */
	public function testTheGatewayDocumentHoldsOnlyPublicActiveProducts(): void {
		$document = $this->service()->gatewayDocument(serverUrl: self::SERVER, version: '2.4.0');
		$this->assertValidOpenApi31(document: $document);

		$this->assertSame('2.4.0', $document['info']['version']);
		$this->assertSame(['/vendor/pets/{petId}', '/zaken'], array_keys($document['paths']));
		$this->assertStringNotContainsString('petstore.example.com', (string) json_encode($document));
	}//end testTheGatewayDocumentHoldsOnlyPublicActiveProducts()

	/**
	 * An empty gateway is still a valid document, and an endpoint without a schema is an open object.
	 *
	 * @return void
	 */
	public function testAnEmptyGatewayAndAnUndescribedEndpoint(): void {
		$this->rows['api_product'] = [];
		$empty = $this->service()->gatewayDocument(serverUrl: self::SERVER, version: '1.0.0');
		$this->assertValidOpenApi31(document: $empty);
		$this->assertSame('{}', json_encode($empty['paths']));

		$this->rows['source'][0]['openApiDocument'] = null;
		$this->rows['api_product'] = [['uuid' => 'p', 'visibility' => 'public', 'status' => 'active', 'endpoints' => ['ep-pet']]];
		$open = $this->service()->gatewayDocument(serverUrl: self::SERVER, version: '1.0.0');
		$this->assertValidOpenApi31(document: $open);
		$this->assertSame('object', $open['paths']['/vendor/pets/{petId}']['get']['responses']['200']['content']['application/json']['schema']['type']);
	}//end testAnEmptyGatewayAndAnUndescribedEndpoint()
}//end class
