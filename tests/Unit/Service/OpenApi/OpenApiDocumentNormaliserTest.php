<?php

/**
 * Unit tests for OpenApiDocumentNormaliser: Swagger 2.0 is converted to the
 * OpenAPI 3.0 shape (servers, security schemes, schemas and their references,
 * body and form parameters, response content), OpenAPI 3.x passes through,
 * anything else is refused, and JSON or YAML text decodes.
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
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for reading a vendor document.
 */
#[CoversClass(OpenApiDocumentNormaliser::class)]
class OpenApiDocumentNormaliserTest extends TestCase {

	/**
	 * The Swagger 2.0 petstore becomes OpenAPI 3.0 with its servers, schemes, schemas, body and responses moved.
	 *
	 * @return void
	 */
	public function testSwaggerIsConvertedToTheOpenApiThreeShape(): void {
		$swagger = json_decode((string) file_get_contents(__DIR__.'/../../../fixtures/openapi/petstore-swagger-2.0.json'), true, flags: JSON_THROW_ON_ERROR);
		$swagger['schemes'] = ['https', 'http'];

		$document = (new OpenApiDocumentNormaliser())->normalise(document: $swagger);

		$this->assertSame('3.0.3', $document['openapi']);
		$this->assertSame([['url' => 'https://petstore.example.com/v1'], ['url' => 'http://petstore.example.com/v1']], $document['servers']);
		$this->assertSame(['type' => 'apiKey', 'name' => 'X-API-Key', 'in' => 'header'], $document['components']['securitySchemes']['apiKeyAuth']);
		$this->assertSame('#/components/schemas/Pet', $document['components']['schemas']['Pets']['items']['$ref']);
		$this->assertArrayNotHasKey('definitions', $document);

		$list = $document['paths']['/pets']['get'];
		$this->assertSame([['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'format' => 'int32', 'maximum' => 100]]], $list['parameters']);
		$this->assertSame('#/components/schemas/Pets', $list['responses']['200']['content']['application/json']['schema']['$ref']);

		$create = $document['paths']['/pets']['post'];
		$this->assertArrayNotHasKey('parameters', $create);
		$this->assertTrue($create['requestBody']['required']);
		$this->assertSame('#/components/schemas/Pet', $create['requestBody']['content']['application/json']['schema']['$ref']);
		$this->assertSame(['description' => 'Null response'], $create['responses']['201']);
	}//end testSwaggerIsConvertedToTheOpenApiThreeShape()

	/**
	 * Form parameters become a form request body; basic and OAuth2 definitions become their OpenAPI 3 schemes.
	 *
	 * @return void
	 */
	public function testFormParametersAndOtherSchemes(): void {
		$document = (new OpenApiDocumentNormaliser())->normalise(
			document: [
				'swagger' => '2.0',
				'info' => ['title' => 'Forms', 'version' => '1'],
				'securityDefinitions' => [
					'basic' => ['type' => 'basic'],
					'oauth' => ['type' => 'oauth2', 'flow' => 'application', 'tokenUrl' => 'https://auth.example.com/token', 'scopes' => ['read' => 'Read']],
				],
				'paths' => [
					'/upload' => [
						'post' => [
							'consumes' => ['multipart/form-data'],
							'parameters' => [
								['name' => 'file', 'in' => 'formData', 'type' => 'file', 'required' => true],
								['name' => 'note', 'in' => 'formData', 'type' => 'string'],
							],
							'responses' => ['204' => ['description' => 'Stored']],
						],
					],
				],
			]
		);

		$this->assertSame([['url' => '']], $document['servers']);
		$this->assertSame(['type' => 'http', 'scheme' => 'basic'], $document['components']['securitySchemes']['basic']);
		$this->assertSame(
			['type' => 'oauth2', 'flows' => ['clientCredentials' => ['tokenUrl' => 'https://auth.example.com/token', 'scopes' => ['read' => 'Read']]]],
			$document['components']['securitySchemes']['oauth']
		);
		$this->assertSame(
			['type' => 'object', 'properties' => ['file' => ['type' => 'file'], 'note' => ['type' => 'string']], 'required' => ['file']],
			$document['paths']['/upload']['post']['requestBody']['content']['multipart/form-data']['schema']
		);
	}//end testFormParametersAndOtherSchemes()

	/**
	 * An OpenAPI 3.x document passes through; anything else is refused.
	 *
	 * @return void
	 */
	public function testOpenApiThreePassesAndOthersAreRefused(): void {
		$normaliser = new OpenApiDocumentNormaliser();
		$document = ['openapi' => '3.1.0', 'info' => ['title' => 'x', 'version' => '1'], 'paths' => ['/a' => []]];

		$this->assertSame($document, $normaliser->normalise(document: $document));
		$this->assertSame('3.1.0', $normaliser->versionOf(document: $document));

		foreach ([['swagger' => '1.2'], ['openapi' => '4.0.0'], []] as $other) {
			try {
				$normaliser->normalise(document: $other);
				$this->fail('Accepted '.json_encode($other));
			} catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('not an OpenAPI', $exception->getMessage());
			}
		}
	}//end testOpenApiThreePassesAndOthersAreRefused()

	/**
	 * JSON and YAML text decode; text that is neither an object nor parses is refused.
	 *
	 * @return void
	 */
	public function testJsonAndYamlTextDecode(): void {
		$normaliser = new OpenApiDocumentNormaliser();

		$this->assertSame(['openapi' => '3.0.0'], $normaliser->decode(text: '{"openapi": "3.0.0"}'));
		$this->assertSame(['openapi' => '3.0.0', 'info' => ['title' => 'Y']], $normaliser->decode(text: "openapi: 3.0.0\ninfo:\n  title: Y\n"));

		$this->expectException(InvalidArgumentException::class);
		$normaliser->decode(text: 'just a sentence');
	}//end testJsonAndYamlTextDecode()
}//end class
