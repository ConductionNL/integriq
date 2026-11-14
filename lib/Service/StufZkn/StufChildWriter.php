<?php

/**
 * Integriq StUF child element writer.
 *
 * Appends StUF-ZKN child elements under one parent element of an envelope
 * being built: an escaped text element in the zkn or StUF namespace, an
 * explicitly nil zkn field, or an empty zkn element to fill next. Used by
 * {@see OutboundNotificationTranslator}.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use DOMElement;

/**
 * Writes the children of one envelope element.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */
final class StufChildWriter {

	/**
	 * Constructor.
	 *
	 * @param DOMElement $parent The element the children are appended to.
	 */
	public function __construct(
		private readonly DOMElement $parent,
	) {
	}//end __construct()

	/**
	 * Append a zkn-namespaced text-valued child element.
	 *
	 * @param string $name The child element local name.
	 * @param string $value The text value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
	 */
	public function zknText(string $name, string $value): void {
		$this->parent->appendChild(
			$this->parent->ownerDocument->createElementNS(StufZknNamespaces::ZKN, 'zkn:' . $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES))
		);

	}//end zknText()

	/**
	 * Append a StUF-namespaced text-valued child element.
	 *
	 * @param string $name The child element local name.
	 * @param string $value The text value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
	 */
	public function stufText(string $name, string $value): void {
		$this->parent->appendChild(
			$this->parent->ownerDocument->createElementNS(StufZknNamespaces::STUF, 'StUF:' . $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES))
		);

	}//end stufText()

	/**
	 * Append an explicitly-nil zkn-namespaced field per StUF's `noValue`/`xsi:nil` convention.
	 *
	 * @param string $name The child element local name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-complete-zaak-create-translates-to-a-valid-zaklk01-toevoeging
	 */
	public function zknNil(string $name): void {
		$field = $this->parent->ownerDocument->createElementNS(StufZknNamespaces::ZKN, 'zkn:' . $name);
		$field->setAttributeNS(StufZknNamespaces::STUF, 'StUF:noValue', 'geenWaarde');
		$field->setAttributeNS(StufZknNamespaces::XSI, 'xsi:nil', 'true');
		$this->parent->appendChild($field);

	}//end zknNil()

	/**
	 * Append an empty zkn-namespaced child element and return it, to fill next.
	 *
	 * @param string $name The child element local name.
	 *
	 * @return DOMElement The appended child.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
	 */
	public function zknChild(string $name): DOMElement {
		$child = $this->parent->ownerDocument->createElementNS(StufZknNamespaces::ZKN, 'zkn:' . $name);
		$this->parent->appendChild($child);

		return $child;

	}//end zknChild()
}//end class
