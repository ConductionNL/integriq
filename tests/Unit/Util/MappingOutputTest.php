<?php

/**
 * Unit tests for MappingOutput and the ArrayToXml writer it shares with XMLResponse.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Util;

use OCA\Integriq\Util\ArrayToXml;
use OCA\Integriq\Util\MappingOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A mapping result written as JSON (no body), XML or CSV.
 */
#[CoversClass(MappingOutput::class)]
#[UsesClass(ArrayToXml::class)]
#[UsesClass(\OCA\Integriq\Controller\XMLResponse::class)]
#[UsesClass(\OCA\Integriq\Util\CsvCodec::class)]
class MappingOutputTest extends TestCase {

	/**
	 * Without outputFormat, or with an unknown one, the mapping stays JSON and no text is rendered.
	 *
	 * @return void
	 */
	public function testAMappingWithoutAFormatStaysJson(): void {
		$output = new MappingOutput();

		self::assertSame(['format' => 'json', 'contentType' => 'application/json', 'body' => null], $output->render(mapping: [], result: ['a' => 1]));
		self::assertSame('json', $output->render(mapping: ['outputFormat' => 'yaml'], result: [])['format']);
		self::assertSame(['format' => 'json', 'options' => []], $output->settings(mapping: ['outputOptions' => 'not an object']));
	}//end testAMappingWithoutAFormatStaysJson()

	/**
	 * XML is written under the configured root, with the xml-response attribute and text conventions.
	 *
	 * @return void
	 */
	public function testXmlUsesTheRootElementAndTheEndpointConventions(): void {
		$rendered = (new MappingOutput())->render(
			mapping: ['outputFormat' => 'XML', 'outputOptions' => ['rootElement' => 'persoon']],
			result: ['@root' => 'ignored', 'naam' => 'Jan & Piet', 'adres' => ['@attributes' => ['soort' => 'woon'], '#text' => 'Dorpsstraat 1']]
		);

		self::assertSame('xml', $rendered['format']);
		self::assertSame('application/xml', $rendered['contentType']);
		self::assertSame(
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<persoon>\n  <naam>Jan &amp; Piet</naam>\n  <adres soort=\"woon\">Dorpsstraat 1</adres>\n</persoon>\n",
			$rendered['body']
		);
	}//end testXmlUsesTheRootElementAndTheEndpointConventions()

	/**
	 * Without a root element option the result's own @root is used, else `root`.
	 *
	 * @return void
	 */
	public function testXmlFallsBackToTheResultsRoot(): void {
		$output = new MappingOutput();

		self::assertStringContainsString('<zaak>', (string)$output->render(mapping: ['outputFormat' => 'xml'], result: ['@root' => 'zaak', 'id' => 1])['body']);
		self::assertStringContainsString("<root>\n  <id>1</id>\n</root>", (string)$output->render(mapping: ['outputFormat' => 'xml', 'outputOptions' => ['rootElement' => ' ']], result: ['id' => 1])['body']);
	}//end testXmlFallsBackToTheResultsRoot()

	/**
	 * CSV for two rows with `;` and a header is three lines; a nested value is its JSON text.
	 *
	 * @return void
	 */
	public function testCsvWritesAHeaderAndOneLinePerRow(): void {
		$rendered = (new MappingOutput())->render(
			mapping: ['outputFormat' => 'csv', 'outputOptions' => ['csvDelimiter' => ';', 'csvHeader' => true]],
			result: [['code' => '0363', 'naam' => 'Amsterdam', 'tags' => ['a', 'b']], ['code' => '0599', 'naam' => 'Rotterdam', 'tags' => []]]
		);

		self::assertSame('text/csv', $rendered['contentType']);
		self::assertSame("code;naam;tags\n0363;Amsterdam;\"[\"\"a\"\",\"\"b\"\"]\"\n0599;Rotterdam;[]\n", $rendered['body']);
	}//end testCsvWritesAHeaderAndOneLinePerRow()

	/**
	 * One object is one row; the header can be switched off; booleans read true and false.
	 *
	 * @return void
	 */
	public function testCsvOfOneObjectWithoutHeader(): void {
		$rendered = (new MappingOutput())->render(
			mapping: ['outputFormat' => 'csv', 'outputOptions' => ['csvHeader' => false]],
			result: ['code' => '0363', 'actief' => true, 'oud' => false]
		);

		self::assertSame("0363,true,false\n", $rendered['body']);
	}//end testCsvOfOneObjectWithoutHeader()

	/**
	 * The extracted writer answers what XMLResponse answered for the same array.
	 *
	 * @return void
	 */
	public function testTheWriterMatchesTheEndpointSerializer(): void {
		$data = ['@root' => 'lijst', 'item' => [['n' => 1], ['n' => 2]], 'leeg' => []];
		$response = new \OCA\Integriq\Controller\XMLResponse(['value' => $data]);

		self::assertSame($response->arrayToXml(data: $data), (new ArrayToXml())->convert(data: $data));
		self::assertStringContainsString("<item>\n    <n>1</n>\n  </item>\n  <item>\n    <n>2</n>\n  </item>\n  <leeg />", (new ArrayToXml())->convert(data: $data));
	}//end testTheWriterMatchesTheEndpointSerializer()
}//end class
