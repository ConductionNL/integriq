<?php

/**
 * The XXE / SSRF defence every XML call site is supposed to route through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Util;

use OCA\Integriq\Util\SafeXmlParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Util\SafeXmlParser
 */
class SafeXmlParserTest extends TestCase {

	/**
	 * Ordinary XML still parses — hardening must not cost functionality.
	 *
	 * @return void
	 */
	public function testItParsesOrdinaryXml(): void {
		$xml = SafeXmlParser::parse('<root><item>hello</item></root>');

		$this->assertNotFalse($xml);
		$this->assertSame('hello', (string)$xml->item);
	}//end testItParsesOrdinaryXml()

	/**
	 * A file-disclosure entity does not reach the file.
	 *
	 * The classic XXE: an external entity pointed at a local path. With the
	 * loader pinned to null the entity resolves to nothing, so whatever the
	 * parse yields, it must not contain the file's contents.
	 *
	 * @return void
	 */
	public function testAFileDisclosureEntityIsNotExpanded(): void {
		$payload = '<?xml version="1.0"?>'
			. '<!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/hostname">]>'
			. '<r><leak>&xxe;</leak></r>';

		$xml = SafeXmlParser::parse($payload);

		// Either the parse fails outright or the entity is empty; both are safe.
		if ($xml !== false) {
			$this->assertSame('', trim((string)$xml->leak), 'the external entity must not be expanded');
		} else {
			$this->assertFalse($xml);
		}
	}//end testAFileDisclosureEntityIsNotExpanded()

	/**
	 * A network entity does not cause an outbound fetch.
	 *
	 * @return void
	 */
	public function testANetworkEntityIsNotFetched(): void {
		$payload = '<?xml version="1.0"?>'
			. '<!DOCTYPE r [<!ENTITY xxe SYSTEM "http://169.254.169.254/latest/meta-data/">]>'
			. '<r><leak>&xxe;</leak></r>';

		$xml = SafeXmlParser::parse($payload);

		if ($xml !== false) {
			$this->assertSame('', trim((string)$xml->leak), 'no SSRF fetch may happen during a parse');
		} else {
			$this->assertFalse($xml);
		}
	}//end testANetworkEntityIsNotFetched()

	/**
	 * The helper restores whatever loader was installed before it ran.
	 *
	 * It is used inside SOAP flows that legitimately install a permissive
	 * loader for WSDL resolution; leaving the null loader behind would break
	 * them in a way that is very hard to trace back here.
	 *
	 * @return void
	 */
	public function testItRestoresThePreviousEntityLoader(): void {
		$sentinel = static fn (): null => null;
		libxml_set_external_entity_loader($sentinel);

		SafeXmlParser::parse('<root/>');

		$this->assertSame(
			$sentinel,
			libxml_get_external_entity_loader(),
			'the helper must be transparent to callers with their own loader'
		);

		libxml_set_external_entity_loader(null);
	}//end testItRestoresThePreviousEntityLoader()

	/**
	 * Malformed input yields false rather than throwing.
	 *
	 * @return void
	 */
	public function testMalformedXmlYieldsFalse(): void {
		$previous = libxml_use_internal_errors(true);
		$this->assertFalse(SafeXmlParser::parse('<unclosed>'));
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
	}//end testMalformedXmlYieldsFalse()
}//end class
