<?php

/**
 * Integriq ArrayToXml utility.
 *
 * Writes an array as an XML document with the `@root`, `@attributes` and
 * `#text` conventions of the xml-response spec. Moved here from XMLResponse,
 * which now calls it, so a mapping's XML output and an endpoint's XML response
 * are written by the same code (mapping-formats-and-lookups D3).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Format
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
 * @spec openspec/specs/xml-response/spec.md
 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
 */

namespace OCA\Integriq\Service\Format;

use DOMDocument;

/**
 * An array written as XML.
 *
 * @spec openspec/specs/xml-response/spec.md
 */
class ArrayToXml implements XmlEncoderInterface {

	/**
	 * Convert an array to XML.
	 *
	 * @param array<string, mixed> $data The data to convert.
	 * @param string|null $rootTag Optional root tag name (overrides @root in data).
	 *
	 * @return string The XML string or empty string on failure.
	 *
	 * @psalm-param  array<string, mixed> $data
	 * @psalm-return string
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	public function convert(array $data, ?string $rootTag = null): string {
		// Extract root tag from data or use provided root tag.
		$rootName = ($rootTag ?? ($data['@root'] ?? 'root'));

		// Remove @root if it exists in data since we have extracted it.
		if (isset($data['@root']) === true) {
			unset($data['@root']);
		}

		// Create new DOM document.
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		// Create root element.
		$root = $dom->createElement($rootName);
		if ($root === false) {
			// Failed to create root element.
			return '';
		}

		$dom->appendChild($root);

		// Build XML structure.
		(new XmlElementBuilder())->buildXmlElement(element: $root, data: $data);

		// Get XML output.
		$xmlOutput = $dom->saveXML();
		if ($xmlOutput === false) {
			$xmlOutput = '';
		}

		// Directly replace decimal CR entities with hexadecimal.
		$xmlOutput = str_replace('&#13;', '&#xD;', $xmlOutput);

		// Format empty tags to have a space before the closing bracket.
		$xmlOutput = preg_replace('/<([^>]*)\/>/', '<$1 />', $xmlOutput);

		return $xmlOutput;
	}//end convert()
}//end class
