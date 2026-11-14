<?php

/**
 * Unit tests for OpenApiImportService: a Swagger 2.0 and an OpenAPI 3.1
 * petstore preview the same operations, a colliding path is flagged, the
 * preview writes nothing, an import creates one source and one endpoint per
 * chosen operation with no secret, and a URL on an internal address is
 * refused before any request is made.
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
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\OpenApi;

use InvalidArgumentException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiImportService;
use OCA\Integriq\Service\Security\EgressGuard;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OpenAPI import.
 */
#[CoversClass(OpenApiImportService::class)]
#[UsesClass(OpenApiDocumentNormaliser::class)]
#[UsesClass(SourceHandler::class)]
#[UsesClass(EndpointHandler::class)]
#[UsesClass(SensitiveFieldRegistry::class)]
#[UsesClass(EgressGuard::class)]
class OpenApiImportServiceTest extends TestCase {

	private OrObjectService&MockObject $objects;

	private IClientService&MockObject $clients;

	/**
	 * The endpoint rows OpenRegister holds.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $existing = [];

	/**
	 * Every object saved, as [schema, data].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $saved = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->existing = [];
		$this->saved = [];
		$this->objects = $this->createMock(OrObjectService::class);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$rows = [];
				foreach ($this->existing as $row) {
					$entity = new ObjectEntity();
					$entity->setUuid((string) ($row['uuid'] ?? 'existing'));
					$entity->setObject($row);
					$rows[] = $entity;
				}

				return ['results' => $rows];
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, mixed $register = null, mixed $schema = null): ObjectEntity {
				$this->saved[] = [(string) $schema, $object];
				$entity = new ObjectEntity();
				$entity->setUuid($schema.'-uuid-'.count($this->saved));
				$entity->setObject($object);

				return $entity;
			}
		);
		$this->clients = $this->createMock(IClientService::class);
	}//end setUp()

	/**
	 * The service, with the real handlers over the mocked object service.
	 *
	 * @param EgressGuardInterface|null $guard The egress guard; the real one when null.
	 *
	 * @return OpenApiImportService The service.
	 */
	private function service(?EgressGuardInterface $guard = null): OpenApiImportService {
		$registry = new SensitiveFieldRegistry();

		return new OpenApiImportService(
			objects: $this->objects,
			sources: new SourceHandler(orObjectService: $this->objects, sensitiveFieldRegistry: $registry),
			endpoints: new EndpointHandler(orObjectService: $this->objects, sensitiveFieldRegistry: $registry),
			normaliser: new OpenApiDocumentNormaliser(),
			egressGuard: ($guard ?? new EgressGuard()),
			clientService: $this->clients,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A fixture document.
	 *
	 * @param string $version `3.1` or `2.0`.
	 *
	 * @return array<string,mixed> The document.
	 */
	private static function petstore(string $version): array {
		$path = __DIR__.'/../../../../lib/Settings/examples/petstore-openapi.json';
		if ($version === '2.0') {
			$path = __DIR__.'/../../../fixtures/openapi/petstore-swagger-2.0.json';
		}

		return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
	}//end petstore()

	/**
	 * The operations of a preview, without the flags, keyed for comparison.
	 *
	 * @param array<string,mixed> $preview The preview.
	 *
	 * @return array<int,array<string,mixed>> The operations.
	 */
	private static function operations(array $preview): array {
		return array_map(
			static fn (array $operation): array => array_intersect_key($operation, array_flip(['key', 'method', 'path', 'gatewayPath', 'endpoint', 'endpointArray'])),
			$preview['operations']
		);
	}//end operations()

	/**
	 * Swagger 2.0 and OpenAPI 3.1 preview the same operations, source location and credential placeholder.
	 *
	 * @return void
	 */
	public function testSwaggerAndOpenApiPreviewTheSameOperations(): void {
		$v31 = $this->service()->preview(document: self::petstore(version: '3.1'), prefix: 'vendor');
		$v20 = $this->service()->preview(document: self::petstore(version: '2.0'), prefix: '/vendor/');

		$this->assertSame('3.1.0', $v31['openapi']);
		$this->assertSame('2.0', $v20['openapi']);
		$this->assertSame(self::operations(preview: $v31), self::operations(preview: $v20));
		$this->assertSame(
			[
				['key' => 'listPets', 'method' => 'GET', 'path' => '/pets', 'gatewayPath' => 'vendor/pets', 'endpoint' => 'pets', 'endpointArray' => ['vendor', 'pets']],
				['key' => 'createPets', 'method' => 'POST', 'path' => '/pets', 'gatewayPath' => 'vendor/pets', 'endpoint' => 'pets', 'endpointArray' => ['vendor', 'pets']],
				[
					'key' => 'showPetById',
					'method' => 'GET',
					'path' => '/pets/{petId}',
					'gatewayPath' => 'vendor/pets/{{petId}}',
					'endpoint' => 'pets/{{petId}}',
					'endpointArray' => ['vendor', 'pets', '{{petId}}'],
				],
			],
			self::operations(preview: $v31)
		);
		$this->assertSame('https://petstore.example.com/v1', $v31['source']['location']);
		$this->assertSame($v31['source']['location'], $v20['source']['location']);
		$this->assertSame($v31['credentials'], $v20['credentials']);
		$this->assertSame(
			[['scheme' => 'apiKeyAuth', 'type' => 'apiKey', 'credentialRef' => null, 'supported' => true, 'in' => 'header', 'name' => 'X-API-Key', 'required' => ['apiKey']]],
			$v31['credentials']
		);
		$this->assertSame(3, $v31['total']);
	}//end testSwaggerAndOpenApiPreviewTheSameOperations()

	/**
	 * A server's variables are filled from their defaults, and an unknown server is refused.
	 *
	 * @return void
	 */
	public function testTheChosenServerBecomesTheLocation(): void {
		$preview = $this->service()->preview(document: self::petstore(version: '3.1'), prefix: 'vendor', serverIndex: 1);

		$this->assertSame('https://sandbox.petstore.example.com/v1', $preview['source']['location']);

		$this->expectException(InvalidArgumentException::class);
		$this->service()->preview(document: self::petstore(version: '3.1'), prefix: 'vendor', serverIndex: 7);
	}//end testTheChosenServerBecomesTheLocation()

	/**
	 * An operation whose path an existing endpoint already serves is flagged, and nothing is written.
	 *
	 * @return void
	 */
	public function testACollidingPathIsFlaggedAndThePreviewWritesNothing(): void {
		$this->existing = [
			['uuid' => 'e-1', 'name' => 'Existing pets list', 'endpoint' => 'vendor/pets', 'endpointArray' => ['vendor', 'pets'], 'method' => 'GET'],
			['uuid' => 'e-2', 'name' => 'Existing pet', 'endpoint' => 'vendor/pets/{{id}}', 'method' => 'GET'],
		];
		$this->objects->expects($this->never())->method('saveObject');
		$this->clients->expects($this->never())->method('newClient');

		$preview = $this->service()->preview(document: self::petstore(version: '2.0'), prefix: 'vendor');

		$flags = array_column($preview['operations'], 'collides', 'key');
		$this->assertSame(['listPets' => true, 'createPets' => false, 'showPetById' => true], $flags);
		$this->assertSame('Existing pets list', $preview['operations'][0]['collidesWith']);
		$this->assertSame('Existing pet', $preview['operations'][2]['collidesWith']);
		$this->assertSame([], $this->saved);
	}//end testACollidingPathIsFlaggedAndThePreviewWritesNothing()

	/**
	 * The preview pages its operation list.
	 *
	 * @return void
	 */
	public function testThePreviewPagesTheOperations(): void {
		$preview = $this->service()->preview(document: self::petstore(version: '3.1'), prefix: 'vendor', offset: 1, limit: 1);

		$this->assertSame(3, $preview['total']);
		$this->assertSame(['createPets'], array_column($preview['operations'], 'key'));
	}//end testThePreviewPagesTheOperations()

	/**
	 * Three chosen operations make one source and three endpoints with the right fields, and no secret.
	 *
	 * @return void
	 */
	public function testAnImportCreatesOneSourceAndAnEndpointPerOperation(): void {
		$result = $this->service()->import(
			document: self::petstore(version: '3.1'),
			prefix: 'vendor',
			operationKeys: ['listPets', 'createPets', 'showPetById']
		);

		$this->assertSame(3, $result['created']);
		$this->assertSame(0, $result['skipped'] + $result['failed'] + $result['unknown']);
		$this->assertSame(['source', 'endpoint', 'endpoint', 'endpoint'], array_column($this->saved, 0));

		$source = $this->saved[0][1];
		$this->assertSame('Swagger Petstore', $source['name']);
		$this->assertSame('https://petstore.example.com/v1', $source['location']);
		$this->assertSame('api', $source['type']);
		$this->assertSame(['credentialRef' => ['credentialName' => '']], $source['configuration']['authentication']);
		$this->assertSame('apiKeyAuth', $source['configuration']['credentialPlaceholders'][0]['scheme']);
		$this->assertSame('3.1.0', $source['openApiDocument']['openapi']);
		$this->assertSame(['listPets', 'createPets'], [$source['openApiDocument']['paths']['/pets']['get']['operationId'], $source['openApiDocument']['paths']['/pets']['post']['operationId']]);
		$this->assertSame(
			['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
			$source['openApiDocument']['components']['securitySchemes']['apiKeyAuth']
		);
		$this->assertStringNotContainsString('demo-key-0000-not-real', (string) json_encode($this->saved));

		$sourceUuid = $result['source']['uuid'];
		$this->assertSame('source-uuid-1', $sourceUuid);
		$show = $this->saved[3][1];
		$this->assertSame('showPetById', $show['name']);
		$this->assertSame('GET', $show['method']);
		$this->assertSame('pets/{{petId}}', $show['endpoint']);
		$this->assertSame(['vendor', 'pets', '{{petId}}'], $show['endpointArray']);
		$this->assertSame(1, preg_match($show['endpointRegex'], 'vendor/pets/42'));
		$this->assertSame(0, preg_match($show['endpointRegex'], 'pets/42'));
		$this->assertSame('api', $show['targetType']);
		$this->assertSame($sourceUuid, $show['targetId']);
		$this->assertSame(['created', 'created', 'created'], array_column($result['endpoints'], 'outcome'));
		$this->assertSame([1, 1, 1], array_column($result['endpoints'], 'batch'));
		$this->assertSame('endpoint-uuid-4', $result['endpoints'][2]['uuid']);
	}//end testAnImportCreatesOneSourceAndAnEndpointPerOperation()

	/**
	 * A colliding or unknown chosen operation is reported, not written; the rest are created.
	 *
	 * @return void
	 */
	public function testACollidingChoiceIsSkippedAndAnUnknownOneReported(): void {
		$this->existing = [['uuid' => 'e-1', 'name' => 'Existing', 'endpoint' => 'vendor/pets', 'method' => 'GET']];

		$result = $this->service()->import(document: self::petstore(version: '2.0'), prefix: 'vendor', operationKeys: ['listPets', 'createPets', 'nope']);

		$this->assertSame(['created' => 1, 'skipped' => 1, 'failed' => 0, 'unknown' => 1], array_intersect_key($result, array_flip(['created', 'skipped', 'failed', 'unknown'])));
		$this->assertSame(['source', 'endpoint'], array_column($this->saved, 0));
		$this->assertSame('POST', $this->saved[1][1]['method']);
	}//end testACollidingChoiceIsSkippedAndAnUnknownOneReported()

	/**
	 * When every choice collides, nothing at all is written, not even the source.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenEveryChoiceCollides(): void {
		$this->existing = [['uuid' => 'e-1', 'name' => 'Existing', 'endpoint' => 'vendor/pets', 'method' => 'GET']];

		$result = $this->service()->import(document: self::petstore(version: '3.1'), prefix: 'vendor', operationKeys: ['listPets']);

		$this->assertNull($result['source']);
		$this->assertSame([], $this->saved);
	}//end testNothingIsWrittenWhenEveryChoiceCollides()

	/**
	 * A large selection is written in batches of 50, each operation with its own outcome.
	 *
	 * @return void
	 */
	public function testALargeImportIsWrittenInBatchesOfFifty(): void {
		$document = ['openapi' => '3.0.3', 'info' => ['title' => 'Many', 'version' => '1'], 'servers' => [['url' => 'https://many.example.com']], 'paths' => []];
		$keys = [];
		for ($index = 1; $index <= 120; $index++) {
			$document['paths']['/items'.$index] = ['get' => ['operationId' => 'item'.$index, 'responses' => ['200' => ['description' => 'OK']]]];
			$keys[] = 'item'.$index;
		}

		$result = $this->service()->import(document: $document, prefix: 'many', operationKeys: $keys);

		$this->assertSame(120, $result['created']);
		$this->assertSame([1 => 50, 2 => 50, 3 => 20], array_count_values(array_column($result['endpoints'], 'batch')));
	}//end testALargeImportIsWrittenInBatchesOfFifty()

	/**
	 * An import needs a chosen operation, a usable prefix and an absolute server URL.
	 *
	 * @return void
	 */
	public function testAnImportRefusesWhatItCannotUse(): void {
		$refusals = 0;
		$cases = [
			fn () => $this->service()->import(document: self::petstore(version: '3.1'), prefix: 'vendor', operationKeys: []),
			fn () => $this->service()->import(document: self::petstore(version: '3.1'), prefix: '../etc', operationKeys: ['listPets']),
			fn () => $this->service()->import(
				document: ['openapi' => '3.0.0', 'info' => ['title' => 'x', 'version' => '1'], 'servers' => [['url' => '/v1']], 'paths' => ['/a' => ['get' => []]]],
				prefix: 'vendor',
				operationKeys: ['GET /a']
			),
			fn () => $this->service()->preview(document: ['info' => ['title' => 'not a document']], prefix: 'vendor'),
		];
		foreach ($cases as $case) {
			try {
				$case();
			} catch (InvalidArgumentException $exception) {
				$refusals++;
			}
		}

		$this->assertSame(4, $refusals);
		$this->assertSame([], $this->saved);
	}//end testAnImportRefusesWhatItCannotUse()

	/**
	 * A URL on an internal address is refused by the egress guard before any request is made.
	 *
	 * @return void
	 */
	public function testAUrlOnAnInternalAddressIsRefused(): void {
		$this->clients->expects($this->never())->method('newClient');

		foreach (['https://127.0.0.1/openapi.json', 'https://10.1.2.3/openapi.json', 'https://169.254.169.254/latest', 'http://petstore.example.com/openapi.json'] as $url) {
			try {
				$this->service()->fetch(url: $url);
				$this->fail('Fetched '.$url);
			} catch (EgressRefusedException $exception) {
				$this->assertNotSame('', $exception->getMessage());
			}
		}
	}//end testAUrlOnAnInternalAddressIsRefused()

	/**
	 * An allowed URL is fetched without following redirects, and its JSON decoded.
	 *
	 * @return void
	 */
	public function testAnAllowedUrlIsFetchedWithoutRedirects(): void {
		$guard = $this->createMock(EgressGuardInterface::class);
		$guard->expects($this->once())->method('assertAllowed')->with('https://vendor.example.com/openapi.json');
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn((string) json_encode(self::petstore(version: '3.1')));
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('get')
			->with('https://vendor.example.com/openapi.json', $this->callback(static fn (array $options): bool => $options['allow_redirects'] === false))
			->willReturn($response);
		$this->clients->method('newClient')->willReturn($client);

		$document = $this->service(guard: $guard)->fetch(url: 'https://vendor.example.com/openapi.json');

		$this->assertSame('Swagger Petstore', $document['info']['title']);
	}//end testAnAllowedUrlIsFetchedWithoutRedirects()
}//end class
