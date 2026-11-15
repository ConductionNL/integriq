<?php

/**
 * Integriq — XML-DSig element lookups for the WS-Security signer.
 *
 * Finds the ds:* elements of a signed SOAP envelope (namespace
 * http://www.w3.org/2000/09/xmldsig#) by local name.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Digikoppeling;

use DOMDocument;
use DOMElement;

/**
 * Lookups of ds:* elements by local name; built by WsSecuritySigner.
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
 */
final class XmlDsigLookup {

	/**
	 * XML Digital Signature namespace.
	 *
	 * @var string
	 */
	private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

	/**
	 * First ds:* element in the document by local name.
	 *
	 * @param DOMDocument $doc       The document.
	 * @param string      $localName The local name.
	 *
	 * @return DOMElement|null
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function element(DOMDocument $doc, string $localName): ?DOMElement {
		$nodes = $doc->getElementsByTagNameNS(self::NS_DS, $localName);
		$first = $nodes->item(0);
		if ($first instanceof DOMElement) {
			return $first;
		}

		return null;
	}//end element()

	/**
	 * Text of a direct ds:* child element by local name.
	 *
	 * @param DOMElement $parent    The parent element.
	 * @param string     $localName The child local name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function childText(DOMElement $parent, string $localName): ?string {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->namespaceURI === self::NS_DS && $child->localName === $localName) {
				return $child->textContent;
			}
		}

		return null;
	}//end childText()

	/**
	 * Text of the first ds:* descendant element by local name.
	 *
	 * @param DOMElement $parent    The parent element.
	 * @param string     $localName The descendant local name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/digikoppeling-adapter/spec.md — Requirement: WUS synchronous profile with WS-Security signing (REQ-DK-002)
	 */
	public function descendantText(DOMElement $parent, string $localName): ?string {
		$nodes = $parent->getElementsByTagNameNS(self::NS_DS, $localName);
		$first = $nodes->item(0);
		if ($first !== null) {
			return $first->textContent;
		}

		return null;
	}//end descendantText()
}//end class
