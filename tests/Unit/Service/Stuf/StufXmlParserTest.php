<?php

/**
 * Unit tests for the shared StufXmlParser.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/stuf-zkn-bridge/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Stuf;

use OCA\OpenConnector\Service\Stuf\StufXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared XXE-hardened XML parser used by iwmo-ijw-adapter and stuf-zkn-bridge.
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-shared-xxe-hardened-stuf-xml-parsing-req-000
 */
class StufXmlParserTest extends TestCase {

	/**
	 * @var StufXmlParser
	 */
	private StufXmlParser $parser;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parser = new StufXmlParser();

	}//end setUp()

	/**
	 * Well-formed XML parses to a usable SimpleXMLElement.
	 *
	 * @return void
	 */
	public function testParsesWellFormedXml(): void {
		$root = $this->parser->parse(xml: '<root><child>value</child></root>');

		$this->assertNotNull($root);
		$this->assertSame('value', (string)$root->child);

	}//end testParsesWellFormedXml()

	/**
	 * Empty input returns null, never throws.
	 *
	 * @return void
	 */
	public function testEmptyInputReturnsNull(): void {
		$this->assertNull($this->parser->parse(xml: ''));
		$this->assertNull($this->parser->parse(xml: '   '));

	}//end testEmptyInputReturnsNull()

	/**
	 * Malformed XML returns null, never throws.
	 *
	 * @return void
	 */
	public function testMalformedXmlReturnsNull(): void {
		$this->assertNull($this->parser->parse(xml: '<root><unclosed></root>'));
		$this->assertNull($this->parser->parse(xml: 'not xml at all'));

	}//end testMalformedXmlReturnsNull()

	/**
	 * An XXE payload attempting external entity expansion never resolves the
	 * entity — the parsed value must NOT contain the target file's content,
	 * and libxml's default (LIBXML_NONET, no LIBXML_NOENT/DTDLOAD) means the
	 * malicious entity is either rejected outright (parse fails, null) or
	 * left inert (entity reference not expanded).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#scenario-an-xxe-payload-is-rejected-or-left-unexpanded-never-resolved
	 */
	public function testXxePayloadIsNeverResolved(): void {
		$xxe = '<?xml version="1.0"?>'
			. '<!DOCTYPE root [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<root>&xxe;</root>';

		$root = $this->parser->parse(xml: $xxe);

		if ($root !== null) {
			$this->assertStringNotContainsString('root:', (string)$root);
		} else {
			$this->assertNull($root);
		}

	}//end testXxePayloadIsNeverResolved()
}//end class
