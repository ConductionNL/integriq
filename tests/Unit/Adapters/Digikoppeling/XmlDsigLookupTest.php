<?php

/**
 * Integriq — XML-DSig element and text lookup tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Digikoppeling;

use DOMDocument;
use DOMElement;
use OCA\Integriq\Adapters\Digikoppeling\XmlDsigLookup;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Lookups match the ds namespace only, and child lookups stay one level deep.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
 */
#[CoversClass(XmlDsigLookup::class)]
class XmlDsigLookupTest extends TestCase {

	/**
	 * A signature fragment with a same-named element outside the ds namespace first.
	 */
	private const XML = '<root xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:o="urn:other">'
		. '<o:SignatureValue>not-ds</o:SignatureValue>'
		. '<ds:Signature>'
		. '<ds:SignedInfo><ds:Reference><ds:DigestValue>digest-1</ds:DigestValue></ds:Reference></ds:SignedInfo>'
		. '<o:KeyName>other-key</o:KeyName>'
		. '<ds:SignatureValue>sig-1</ds:SignatureValue>'
		. '</ds:Signature>'
		. '</root>';

	/**
	 * @var XmlDsigLookup
	 */
	private XmlDsigLookup $lookup;

	/**
	 * @var DOMDocument
	 */
	private DOMDocument $doc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->lookup = new XmlDsigLookup();
		$this->doc = new DOMDocument();
		$this->doc->loadXML(self::XML);

	}//end setUp()

	/**
	 * The ds:Signature element of the fixture.
	 *
	 * @return DOMElement
	 */
	private function signature(): DOMElement {
		$signature = $this->lookup->element(doc: $this->doc, localName: 'Signature');
		$this->assertInstanceOf(DOMElement::class, $signature);

		return $signature;

	}//end signature()

	/**
	 * element() finds the ds element and ignores same-named elements in other namespaces.
	 *
	 * @return void
	 */
	public function testElementMatchesDsNamespaceOnly(): void {
		$value = $this->lookup->element(doc: $this->doc, localName: 'SignatureValue');

		$this->assertInstanceOf(DOMElement::class, $value);
		$this->assertSame('sig-1', $value->textContent);
		$this->assertNull($this->lookup->element(doc: $this->doc, localName: 'KeyName'));
		$this->assertNull($this->lookup->element(doc: $this->doc, localName: 'Missing'));

	}//end testElementMatchesDsNamespaceOnly()

	/**
	 * childText() reads a direct ds child.
	 *
	 * @return void
	 */
	public function testChildTextReadsDirectChild(): void {
		$this->assertSame('sig-1', $this->lookup->childText(parent: $this->signature(), localName: 'SignatureValue'));

	}//end testChildTextReadsDirectChild()

	/**
	 * childText() does not descend and ignores non-ds children.
	 *
	 * @return void
	 */
	public function testChildTextIgnoresGrandchildrenAndOtherNamespaces(): void {
		$signature = $this->signature();

		$this->assertNull($this->lookup->childText(parent: $signature, localName: 'DigestValue'));
		$this->assertNull($this->lookup->childText(parent: $signature, localName: 'KeyName'));

	}//end testChildTextIgnoresGrandchildrenAndOtherNamespaces()

	/**
	 * descendantText() reads the first nested ds element, or null.
	 *
	 * @return void
	 */
	public function testDescendantTextReadsNestedElement(): void {
		$signature = $this->signature();

		$this->assertSame('digest-1', $this->lookup->descendantText(parent: $signature, localName: 'DigestValue'));
		$this->assertNull($this->lookup->descendantText(parent: $signature, localName: 'KeyName'));
		$this->assertNull($this->lookup->descendantText(parent: $signature, localName: 'Missing'));

	}//end testDescendantTextReadsNestedElement()
}//end class
