<?php

/**
 * Integriq export envelope element.
 *
 * One element of an export envelope being built, with the document it
 * belongs to, so text children and nested elements can be added to it.
 * Text values are XML-escaped the way every export envelope translator
 * escaped them (`htmlspecialchars` with `ENT_XML1 | ENT_QUOTES`).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

use DOMDocument;
use DOMElement;

/**
 * An element of an export envelope under construction.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */
final class EnvelopeElement {

	/**
	 * Constructor.
	 *
	 * @param DOMDocument $document The envelope document.
	 * @param DOMElement  $element  The element this wraps.
	 */
	public function __construct(
		private readonly DOMDocument $document,
		private readonly DOMElement $element,
	) {

	}//end __construct()

	/**
	 * Append a text child `<name>value</name>`.
	 *
	 * @param string $name  The child element name.
	 * @param string $value The text value; it is XML-escaped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function addText(string $name, string $value): void {
		$this->element->appendChild($this->document->createElement($name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES)));

	}//end addText()

	/**
	 * Append an empty child element and return it.
	 *
	 * @param string $name The child element name.
	 *
	 * @return self The new child element.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public function addChild(string $name): self {
		$child = $this->document->createElement($name);
		$this->element->appendChild($child);

		return new self(document: $this->document, element: $child);

	}//end addChild()
}//end class
