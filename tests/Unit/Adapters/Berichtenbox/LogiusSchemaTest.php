<?php

/**
 * Integriq — the Logius schema validator reads the vendored directory only, and leaves libxml as it found it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Berichtenbox
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Berichtenbox;

use OCA\Integriq\Adapters\Berichtenbox\LogiusSchema;
use PHPUnit\Framework\TestCase;

/**
 * REQ-DPA-010: validation against the vendored contract.
 *
 * @covers \OCA\Integriq\Adapters\Berichtenbox\LogiusSchema
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-letter-is-built-to-the-official-schema-and-its-limits-req-dpa-010
 */
class LogiusSchemaTest extends TestCase {

	private const SCHEMA = 'BerichtVerwerkService/Request/GLOBEBatchRequest.xsd';

	/**
	 * A schema written outside the vendored directory, removed in tearDown.
	 *
	 * @var string|null
	 */
	private ?string $outsideSchema = null;

	/**
	 * Remove the temporary schema.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->outsideSchema !== null && is_file($this->outsideSchema) === true) {
			unlink($this->outsideSchema);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The directory is the real, existing vendored Logius directory.
	 *
	 * @return void
	 */
	public function testTheDirectoryIsTheVendoredLogiusDirectory(): void {
		$directory = (new LogiusSchema())->directory();

		$this->assertSame('Logius', basename($directory));
		$this->assertDirectoryExists($directory);
		$this->assertFileExists($directory . '/' . self::SCHEMA);
	}//end testTheDirectoryIsTheVendoredLogiusDirectory()

	/**
	 * Empty input and input that is not XML are reported as such.
	 *
	 * @return void
	 */
	public function testANonXmlDocumentIsReportedAsNotXml(): void {
		$schema = new LogiusSchema();

		$this->assertSame(['The document is not XML.'], $schema->errors(xml: '', schema: self::SCHEMA));
		$this->assertSame(['The document is not XML.'], $schema->errors(xml: '<open', schema: self::SCHEMA));
	}//end testANonXmlDocumentIsReportedAsNotXml()

	/**
	 * Well-formed XML that breaks the schema yields libxml's trimmed messages.
	 *
	 * @return void
	 */
	public function testAnInvalidDocumentYieldsTrimmedMessages(): void {
		$errors = (new LogiusSchema())->errors(xml: '<?xml version="1.0"?><Wrong/>', schema: self::SCHEMA);

		$this->assertNotSame([], $errors);
		foreach ($errors as $error) {
			$this->assertNotSame('', $error);
			$this->assertSame(trim($error), $error);
		}
	}//end testAnInvalidDocumentYieldsTrimmedMessages()

	/**
	 * A schema outside the vendored directory is not read, even when the
	 * document would satisfy it: the path resolves to nothing.
	 *
	 * @return void
	 */
	public function testASchemaOutsideTheDirectoryIsNotRead(): void {
		$this->outsideSchema = (string)tempnam(sys_get_temp_dir(), 'xsd');
		file_put_contents(
			$this->outsideSchema,
			'<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="Ok" type="xs:string"/></xs:schema>'
		);
		$schema = new LogiusSchema();
		$depth = substr_count(trim($schema->directory(), DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR) + 1;
		$relative = str_repeat('../', $depth) . ltrim($this->outsideSchema, DIRECTORY_SEPARATOR);

		// libxml reports the unreadable schema as a PHP warning too; capture it
		// rather than let it leak into the run.
		$warnings = [];
		set_error_handler(
			static function (int $errno, string $message) use (&$warnings): bool {
				$warnings[] = $message;
				return true;
			},
			E_WARNING
		);
		try {
			$errors = $schema->errors(xml: '<?xml version="1.0"?><Ok>fine</Ok>', schema: $relative);
		} finally {
			restore_error_handler();
		}

		$this->assertNotSame([], $errors);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('Invalid Schema', $warnings[0]);

		// The same document against the same file, read directly, is valid:
		// the refusal above comes from the directory boundary, not the schema.
		$document = new \DOMDocument();
		$document->loadXML('<?xml version="1.0"?><Ok>fine</Ok>');
		$this->assertTrue($document->schemaValidate($this->outsideSchema));
	}//end testASchemaOutsideTheDirectoryIsNotRead()

	/**
	 * The previous entity loader and error mode are put back afterwards.
	 *
	 * @return void
	 */
	public function testLibxmlStateIsRestored(): void {
		$loader = static fn (?string $public, ?string $system, array $context) => null;
		libxml_set_external_entity_loader($loader);
		$previousErrors = libxml_use_internal_errors(false);

		try {
			(new LogiusSchema())->errors(xml: '<?xml version="1.0"?><Wrong/>', schema: self::SCHEMA);

			$this->assertSame($loader, libxml_get_external_entity_loader());
			$this->assertFalse(libxml_use_internal_errors(false));
			$this->assertSame([], libxml_get_errors());
		} finally {
			libxml_set_external_entity_loader(null);
			libxml_use_internal_errors($previousErrors);
		}
	}//end testLibxmlStateIsRestored()
}//end class
