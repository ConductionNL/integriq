<?php

/**
 * The message validator and its three checkers, run on real schema documents.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\MessageValidation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-xml-is-validated-without-network-access-req-msv-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\MessageValidation;

use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\ValidationOutcome;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Each checker is reached through MessageValidationService::validate(), the
 * method the endpoint and synchronization paths will call.
 */
class MessageValidationServiceTest extends TestCase {

	/**
	 * URIs the libxml entity loader was asked for while a test's loader was installed.
	 *
	 * @var list<string>
	 */
	private array $loaderCalls = [];

	/**
	 * Restore libxml's default loader after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		libxml_set_external_entity_loader(null);

	}//end tearDown()

	// ---------------------------------------------------------------------
	// JSON Schema.
	// ---------------------------------------------------------------------

	/**
	 * A payload without the required `bsn` lists `/bsn`.
	 *
	 * @return void
	 */
	public function testAMissingRequiredPropertyIsListedByItsPath(): void {
		$outcome = $this->service()->validate(
			messageSchema: $this->schema(kind: 'json-schema', file: 'person.schema.json'),
			payload: ['naam' => 'Jan Jansen']
		);

		$this->assertFalse($outcome->isValid());
		$this->assertContains('/bsn', $outcome->paths());
		$this->assertNotContains('/naam', $outcome->paths());

	}//end testAMissingRequiredPropertyIsListedByItsPath()

	/**
	 * A wrong value is listed under its own path with a message, and a valid
	 * payload, also as decoded JSON objects, passes.
	 *
	 * @return void
	 */
	public function testAWrongValueIsListedAndAValidPayloadPasses(): void {
		$schema = $this->schema(kind: 'json-schema', file: 'person.schema.json');

		$outcome = $this->service()->validate(messageSchema: $schema, payload: ['bsn' => '12', 'naam' => 'Jan']);
		$this->assertSame(['/bsn'], $outcome->paths());
		$this->assertNotSame('', $outcome->errors()[0]['message']);

		$this->assertTrue($this->service()->validate(messageSchema: $schema, payload: ['bsn' => '123456782', 'naam' => 'Jan'])->isValid());
		$this->assertTrue($this->service()->validate(messageSchema: $schema, payload: json_decode('{"bsn":"123456782","naam":"Jan"}'))->isValid());

	}//end testAWrongValueIsListedAndAValidPayloadPasses()

	/**
	 * A JSON Schema document that does not parse is reported, not thrown.
	 *
	 * @return void
	 */
	public function testABrokenJsonSchemaDocumentIsReported(): void {
		$outcome = $this->service()->validate(
			messageSchema: ['kind' => 'json-schema', 'document' => '{"type": "object",'],
			payload: []
		);

		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('does not parse', $outcome->errors()[0]['message']);

	}//end testABrokenJsonSchemaDocumentIsReported()

	// ---------------------------------------------------------------------
	// XSD (REQ-MSV-004).
	// ---------------------------------------------------------------------

	/**
	 * A valid XML message passes and an invalid one names the element.
	 *
	 * @return void
	 */
	public function testAnXmlMessageIsCheckedAgainstTheXsd(): void {
		$schema = $this->schema(kind: 'xsd', file: 'person.xsd');

		$valid = '<persoon><bsn>123456782</bsn><naam>Jan</naam></persoon>';
		$this->assertTrue($this->service()->validate(messageSchema: $schema, payload: $valid)->isValid());

		$outcome = $this->service()->validate(messageSchema: $schema, payload: '<persoon><bsn>12</bsn><naam>Jan</naam></persoon>');
		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('bsn', $outcome->errors()[0]['message']);

		$outcome = $this->service()->validate(messageSchema: $schema, payload: '<persoon><bsn>');
		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('does not parse', $outcome->errors()[0]['message']);

	}//end testAnXmlMessageIsCheckedAgainstTheXsd()

	/**
	 * An XSD importing a remote location is not fetched: the outcome names the
	 * location, and libxml's loader is never asked for it.
	 *
	 * @return void
	 */
	public function testARemoteImportIsNotFetchedAndIsReported(): void {
		$this->installRecordingLoader();

		$outcome = $this->service()->validate(
			messageSchema: $this->schema(kind: 'xsd', file: 'remote-import.xsd'),
			payload: '<bericht/>'
		);

		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('https://example.org/other.xsd', $outcome->errors()[0]['message']);
		$this->assertSame([], $this->loaderCalls, 'nothing is loaded from outside the message schema');

	}//end testARemoteImportIsNotFetchedAndIsReported()

	/**
	 * A relative include is not read from disk either: the validator pins the
	 * loader for the whole validation, so the include fails to resolve.
	 *
	 * @return void
	 */
	public function testARelativeIncludeIsNotReadFromDisk(): void {
		$this->installRecordingLoader();

		$outcome = $this->service()->validate(
			messageSchema: $this->schema(kind: 'xsd', file: 'local-include.xsd'),
			payload: '<bericht>tekst</bericht>'
		);

		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('other.xsd', implode(' ', array_column($outcome->errors(), 'message')));
		$this->assertSame([], $this->loaderCalls, 'the test loader is not the one in use during validation');

	}//end testARelativeIncludeIsNotReadFromDisk()

	/**
	 * A non-text payload against an XSD is refused with a message, not a TypeError.
	 *
	 * @return void
	 */
	public function testAnXsdNeedsATextMessage(): void {
		$outcome = $this->service()->validate(messageSchema: $this->schema(kind: 'xsd', file: 'person.xsd'), payload: ['bsn' => '1']);

		$this->assertFalse($outcome->isValid());

	}//end testAnXsdNeedsATextMessage()

	// ---------------------------------------------------------------------
	// OpenAPI.
	// ---------------------------------------------------------------------

	/**
	 * OpenAPI 3.0 `nullable` lets null through; a missing required field fails.
	 *
	 * @return void
	 */
	public function testAnOpenApiNullableFieldAcceptsNull(): void {
		$schema = $this->schema(kind: 'openapi', file: 'person.openapi.yaml');
		$context = ['operationId' => 'updatePersoon', 'direction' => 'request'];

		$outcome = $this->service()->validate(
			messageSchema: $schema,
			payload: ['bsn' => '123456782', 'naam' => 'Jan', 'tussenvoegsel' => null],
			context: $context
		);
		$this->assertTrue($outcome->isValid(), implode('; ', array_column($outcome->errors(), 'message')));

		$outcome = $this->service()->validate(messageSchema: $schema, payload: ['naam' => 'Jan'], context: $context);
		$this->assertContains('/bsn', $outcome->paths());

	}//end testAnOpenApiNullableFieldAcceptsNull()

	/**
	 * Without an operationId the operation is found by method and path, and
	 * the response body is checked against the answer's status.
	 *
	 * @return void
	 */
	public function testAnOperationIsFoundByMethodAndPath(): void {
		$schema = $this->schema(kind: 'openapi', file: 'person.openapi.yaml');

		$outcome = $this->service()->validate(
			messageSchema: $schema,
			payload: ['bsn' => 123],
			context: ['method' => 'PUT', 'path' => '/personen/123456782', 'direction' => 'response', 'status' => 200]
		);

		$this->assertFalse($outcome->isValid());
		$this->assertContains('/bsn', $outcome->paths());
		$this->assertContains('/naam', $outcome->paths());

	}//end testAnOperationIsFoundByMethodAndPath()

	/**
	 * An operation the document does not describe is reported by name.
	 *
	 * @return void
	 */
	public function testAnUnknownOperationIsReported(): void {
		$outcome = $this->service()->validate(
			messageSchema: $this->schema(kind: 'openapi', file: 'person.openapi.yaml'),
			payload: [],
			context: ['operationId' => 'deletePersoon', 'direction' => 'request']
		);

		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('deletePersoon', $outcome->errors()[0]['message']);

	}//end testAnUnknownOperationIsReported()

	// ---------------------------------------------------------------------
	// The service.
	// ---------------------------------------------------------------------

	/**
	 * An unknown kind is refused by name, and the outcome caps its list for a
	 * problem response.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsRefusedAndErrorsAreCapped(): void {
		$outcome = $this->service()->validate(messageSchema: ['kind' => 'relax-ng', 'document' => ''], payload: []);
		$this->assertFalse($outcome->isValid());
		$this->assertStringContainsString('relax-ng', $outcome->errors()[0]['message']);

		$many = [];
		for ($i = 0; $i < 30; $i++) {
			$many[] = ['path' => '/' . $i, 'message' => 'wrong'];
		}

		$this->assertCount(20, ValidationOutcome::failed(errors: $many)->firstErrors(limit: 20));

	}//end testAnUnknownKindIsRefusedAndErrorsAreCapped()

	/**
	 * The service over the real checkers.
	 *
	 * @return MessageValidationService
	 */
	private function service(): MessageValidationService {
		$json = new JsonSchemaChecker();

		return new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json));

	}//end service()

	/**
	 * A message schema object as the register stores it.
	 *
	 * @param string $kind The kind.
	 * @param string $file The fixture file.
	 *
	 * @return array<string, string>
	 */
	private function schema(string $kind, string $file): array {
		return [
			'name' => $file,
			'kind' => $kind,
			'document' => (string)file_get_contents(__DIR__ . '/../../../fixtures/message-schemas/' . $file),
			'version' => '1.0.0',
		];

	}//end schema()

	/**
	 * Install a libxml entity loader that records what it is asked for.
	 *
	 * @return void
	 */
	private function installRecordingLoader(): void {
		$this->loaderCalls = [];
		libxml_set_external_entity_loader(
			function (?string $public, string $system): ?string {
				$this->loaderCalls[] = $system;
				return null;
			}
		);

	}//end installRecordingLoader()

	/**
	 * The seeded JSON Schema message schema as OpenRegister hands it back: the
	 * stored text decoded to an array. A valid body passes, a body without bsn
	 * is refused naming /bsn, and the document is not reported as broken.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function testAJsonSchemaDocumentReadBackAsAnArrayIsChecked(): void {
		$schema = $this->seededSchema(slug: 'example-person-json');
		$schema['document'] = json_decode($schema['document'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($schema['document']);

		$valid = $this->service()->validate(messageSchema: $schema, payload: ['bsn' => '123456782', 'geslachtsnaam' => 'Jansen']);
		$this->assertTrue($valid->isValid(), implode('; ', array_column($valid->errors(), 'message')));

		$refused = $this->service()->validate(messageSchema: $schema, payload: ['geslachtsnaam' => 'Jansen']);
		$this->assertFalse($refused->isValid());
		$this->assertContains('/bsn', $refused->paths());

		$this->assertNull($this->service()->documentProblem(messageSchema: $schema));
	}//end testAJsonSchemaDocumentReadBackAsAnArrayIsChecked()

	/**
	 * An OpenAPI document read back as an array is checked like its text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-an-endpoint-validates-its-request-and-its-proxied-answer-req-msv-002
	 */
	public function testAnOpenApiDocumentReadBackAsAnArrayIsChecked(): void {
		$schema = $this->schema(kind: 'openapi', file: 'person.openapi.yaml');
		$schema['document'] = Yaml::parse($schema['document']);
		$context = ['operationId' => 'updatePersoon', 'direction' => 'request'];

		$valid = $this->service()->validate(messageSchema: $schema, payload: ['bsn' => '123456782', 'naam' => 'Jan'], context: $context);
		$this->assertTrue($valid->isValid(), implode('; ', array_column($valid->errors(), 'message')));

		$refused = $this->service()->validate(messageSchema: $schema, payload: ['naam' => 'Jan'], context: $context);
		$this->assertContains('/bsn', $refused->paths());
		$this->assertNull($this->service()->documentProblem(messageSchema: $schema));
	}//end testAnOpenApiDocumentReadBackAsAnArrayIsChecked()

	/**
	 * A seeded message schema object from the register fragment.
	 *
	 * @param string $slug The seed slug.
	 *
	 * @return array<string, mixed>
	 */
	private function seededSchema(string $slug): array {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/mapping-message-schema-validation.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($fragment['components']['objects'] as $object) {
			if ($object['@self']['schema'] === 'message_schema' && $object['@self']['slug'] === $slug) {
				unset($object['@self']);
				return $object;
			}
		}

		$this->fail('No seeded message schema ' . $slug);
	}//end seededSchema()
}//end class
