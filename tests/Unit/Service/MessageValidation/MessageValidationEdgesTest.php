<?php

/**
 * Integriq — message validation at its edges: unusable schema documents,
 * reference cycles and remote references, and a failure without its errors.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use InvalidArgumentException;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiReferenceResolver;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A schema document that is not an object or that Opis cannot compile fails
 * the check with that reason; a reference cycle or a remote reference is
 * refused; a failure handed no errors still says the message was not checked.
 */
#[CoversClass(JsonSchemaChecker::class)]
#[CoversClass(OpenApiReferenceResolver::class)]
#[CoversClass(ValidationOutcome::class)]
class MessageValidationEdgesTest extends TestCase {

	/**
	 * Schema documents that parse but cannot be used, and why.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unusableSchemas(): array {
		return [
			'a list' => ['[1, 2]', 'The JSON Schema document is not an object'],
			'a string' => ['"text"', 'The JSON Schema document is not an object'],
			'a type that is a number' => ['{"type": 5}', 'The JSON Schema document cannot be used: type can only be a string or an array of string'],
			'a pattern that is not a regex' => ['{"pattern": "("}', 'The JSON Schema document cannot be used: pattern value must be a valid regex'],
			'a remote reference' => ['{"$ref": "http://elsewhere.example/x.json"}', 'The JSON Schema document cannot be used: Unresolved reference: http://elsewhere.example/x.json#'],
		];
	}//end unusableSchemas()

	/**
	 * @dataProvider unusableSchemas
	 */
	public function testASchemaDocumentThatCannotBeUsedFailsTheCheckWithTheReason(string $schema, string $message): void {
		$outcome = (new JsonSchemaChecker())->check(schema: $schema, payload: ['naam' => 'De Vries']);

		$this->assertFalse($outcome->isValid());
		$this->assertSame([['path' => '/', 'message' => $message]], $outcome->errors());
	}//end testASchemaDocumentThatCannotBeUsedFailsTheCheckWithTheReason()

	public function testABooleanSchemaIsUsable(): void {
		$checker = new JsonSchemaChecker();

		$this->assertTrue($checker->check(schema: 'true', payload: ['any' => 'thing'])->isValid());
		$this->assertFalse($checker->check(schema: 'false', payload: ['any' => 'thing'])->isValid());
	}//end testABooleanSchemaIsUsable()

	public function testAReferenceCycleIsRefusedPastTheMaximumDepth(): void {
		$document = json_decode('{"components":{"schemas":{"A":{"$ref":"#/components/schemas/B"},"B":{"$ref":"#/components/schemas/A"}}}}', false);
		$resolver = new OpenApiReferenceResolver(openApi: $document);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The OpenAPI document refers to itself in a cycle deeper than ' . OpenApiReferenceResolver::MAX_DEPTH);
		$resolver->resolve(node: $document->components->schemas->A);
	}//end testAReferenceCycleIsRefusedPastTheMaximumDepth()

	public function testAChainAsDeepAsTheLimitStillResolves(): void {
		$schemas = [];
		for ($i = 0; $i < OpenApiReferenceResolver::MAX_DEPTH; $i++) {
			$schemas['S' . $i] = ['$ref' => '#/components/schemas/S' . ($i + 1)];
		}

		$schemas['S' . OpenApiReferenceResolver::MAX_DEPTH] = ['type' => 'string', 'x-escaped' => ['a/b' => 1]];
		$document = json_decode((string)json_encode(['components' => ['schemas' => $schemas]]), false);

		$resolved = (new OpenApiReferenceResolver(openApi: $document))->resolve(node: $document->components->schemas->S0);
		$this->assertEquals(json_decode('{"type":"string","x-escaped":{"a/b":1}}', false), $resolved);
	}//end testAChainAsDeepAsTheLimitStillResolves()

	public function testARemoteReferenceIsNotFetched(): void {
		$document = json_decode('{"paths":{"/x":{"$ref":"https://elsewhere.example/openapi.json#/paths/~1x"}}}', false);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The OpenAPI document refers to https://elsewhere.example/openapi.json#/paths/~1x, which is not fetched');
		(new OpenApiReferenceResolver(openApi: $document))->resolve(node: $document->paths);
	}//end testARemoteReferenceIsNotFetched()

	public function testAFailureHandedNoErrorsStillSaysTheMessageWasNotChecked(): void {
		$outcome = ValidationOutcome::failed(errors: []);

		$this->assertFalse($outcome->isValid());
		$this->assertSame([['path' => '/', 'message' => 'The message could not be checked']], $outcome->errors());
	}//end testAFailureHandedNoErrorsStillSaysTheMessageWasNotChecked()
}//end class
