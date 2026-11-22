<?php

/**
 * Characterisation test for OpenApiChecker::check.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use PHPUnit\Framework\TestCase;

/**
 * Golden record of the OpenAPI operation check.
 */
class OpenApiCheckerGoldenTest extends TestCase {

	/**
	 * The recorded checks.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$seen = [];
		$jsonSchema = $this->createMock(JsonSchemaChecker::class);
		$jsonSchema->method('check')->willReturnCallback(
			static function (mixed $schema, mixed $payload) use (&$seen): ValidationOutcome {
				$seen[] = json_encode($schema);
				return ValidationOutcome::valid();
			}
		);
		$checker = new OpenApiChecker($jsonSchema);

		$document = json_encode([
			'openapi' => '3.0.3',
			'paths' => [
				'/zaken/{uuid}' => [
					'put' => [
						'operationId' => 'zaak_update',
						'requestBody' => ['content' => ['application/xml' => ['schema' => ['type' => 'string']], 'application/json' => ['schema' => ['$ref' => '#/components/schemas/Zaak']]]],
						'responses' => [
							'200' => ['content' => ['application/hal+json' => ['schema' => ['type' => 'object', 'nullable' => true, 'enum' => ['a', null]]]]],
							'4XX' => ['content' => ['text/plain' => ['schema' => ['type' => 'string', 'nullable' => true]]]],
							'default' => ['description' => 'fout'],
						],
					],
					'get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Zaak']]]]]]],
					'parameters' => [],
				],
				'/plain' => ['post' => ['requestBody' => ['content' => ['application/json' => ['example' => 1]]]], 'delete' => 'not an operation'],
				'/broken' => ['post' => ['requestBody' => ['$ref' => '#/components/requestBodies/Missing']]],
				'/text' => 'not a path item',
			],
			'components' => ['schemas' => ['Zaak' => ['type' => 'object', 'properties' => ['naam' => ['type' => 'string', 'nullable' => true, 'enum' => ['x']], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['naam']]]],
		]);
		$yaml = "openapi: 3.0.3\npaths:\n  /ping:\n    get:\n      operationId: ping\n      responses:\n        '200':\n          content:\n            application/json:\n              schema:\n                type: string\n";

		$cases = [
			'by operation id' => [$document, ['operationId' => 'zaak_update']],
			'by method and path' => [$document, ['method' => 'PUT', 'path' => '/zaken/123/']],
			'response 200' => [$document, ['operationId' => 'zaak_update', 'direction' => 'response']],
			'response 404 via 4XX' => [$document, ['operationId' => 'zaak_update', 'direction' => 'response', 'status' => '404']],
			'response 500 via default' => [$document, ['operationId' => 'zaak_update', 'direction' => 'response', 'status' => '500']],
			'get list response' => [$document, ['method' => 'get', 'path' => '/zaken/1', 'direction' => 'response']],
			'no schema in media' => [$document, ['method' => 'post', 'path' => '/plain']],
			'unresolvable reference' => [$document, ['method' => 'post', 'path' => '/broken']],
			'unknown operation id' => [$document, ['operationId' => 'nope']],
			'unknown path' => [$document, ['method' => 'get', 'path' => '/elsewhere']],
			'method on a non-operation' => [$document, ['method' => 'delete', 'path' => '/plain']],
			'yaml document' => [$yaml, ['operationId' => 'ping', 'direction' => 'response']],
			'not a document' => ['{"broken', ['operationId' => 'x']],
			'json scalar' => ['42', ['operationId' => 'x']],
		];
		$record = [];
		foreach ($cases as $name => [$doc, $context]) {
			$seen = [];
			$outcome = $checker->check($doc, ['naam' => 'x'], $context);
			$record[$name] = ['valid' => $outcome->isValid(), 'errors' => $outcome->firstErrors(5), 'schemas' => $seen];
		}

		foreach (['good' => $document, 'yaml' => $yaml, 'no paths' => '{"openapi":"3"}', 'scalar' => '"x"', 'broken' => "a: [b\n"] as $name => $doc) {
			$record['documentProblem ' . $name] = $checker->documentProblem($doc);
		}

		$path = __DIR__ . '/../../../fixtures/message-validation/openapi-checker.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()

	/**
	 * A body schema whose reference resolves to null is no schema: the body
	 * holder is resolved in depth first, so the null never reaches the JSON
	 * Schema check.
	 *
	 * @return void
	 */
	public function testABodySchemaResolvingToNullDeclaresNoSchema(): void {
		$checker = new OpenApiChecker(new JsonSchemaChecker());
		$document = json_encode([
			'openapi' => '3.0.3',
			'paths' => ['/x' => ['post' => ['operationId' => 'x', 'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Nothing']]]]]]],
			'components' => ['schemas' => ['Nothing' => null]],
		]);

		$this->assertTrue($checker->check($document, ['a' => 1], ['operationId' => 'x'])->isValid());
	}//end testABodySchemaResolvingToNullDeclaresNoSchema()
}//end class
