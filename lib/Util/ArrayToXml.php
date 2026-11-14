<?php

/**
 * Integriq ArrayToXml utility.
 *
 * Writes an array as an XML document with the `@root`, `@attributes` and
 * `#text` conventions of the xml-response spec. Moved here from XMLResponse,
 * which now calls it, so a mapping's XML output and an endpoint's XML response
 * are written by the same code (mapping-formats-and-lookups D3).
 *
 * @category Util
 * @package  OCA\Integriq\Util
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
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
 */

namespace OCA\Integriq\Util;

use DOMDocument;
use DOMElement;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * An array written as XML.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 *
 * @spec openspec/specs/xml-response/spec.md
 */
class ArrayToXml {

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
		$this->buildXmlElement(element: $root, data: $data);

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

	/**
	 * Build an XML element with attributes and children in order.
	 *
	 * @param DOMElement           $element The element to populate; its owner document creates the children.
	 * @param array<string, mixed> $data    The data to convert.
	 *
	 * @return void
	 *
	 * @psalm-param DOMElement $element
	 * @psalm-param array<string, mixed> $data
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	private function buildXmlElement(DOMElement $element, array $data): void {
		$data = $this->appendAttributes(element: $element, data: $data);
		$data = $this->appendText(element: $element, data: $data);

		// Process child elements.
		foreach ($data as $key => $value) {
			$tagName = $this->tagName(key: $key);
			foreach ($this->occurrences(value: $value) as $item) {
				$this->fillElement(element: $this->addChild(parent: $element, tagName: $tagName), data: $item);
			}
		}

	}//end buildXmlElement()

	/**
	 * Set the `@attributes` of the data on the element, in order.
	 *
	 * @param DOMElement           $element The element.
	 * @param array<string, mixed> $data    The data.
	 *
	 * @return array<string, mixed> The data without its attributes.
	 */
	private function appendAttributes(DOMElement $element, array $data): array {
		if (isset($data['@attributes']) === true && is_array($data['@attributes']) === true) {
			foreach ($data['@attributes'] as $attrKey => $attrValue) {
				// Convert attribute value to string and set it.
				$element->setAttribute($attrKey, (string)$attrValue);
			}

			unset($data['@attributes']);
		}

		return $data;

	}//end appendAttributes()

	/**
	 * Append the `#text` of the data to the element.
	 *
	 * @param DOMElement           $element The element.
	 * @param array<string, mixed> $data    The data.
	 *
	 * @return array<string, mixed> The data without its text.
	 */
	private function appendText(DOMElement $element, array $data): array {
		if (isset($data['#text']) === true) {
			$element->appendChild($this->createSafeTextNode(dom: $element->ownerDocument, text: (string)$data['#text']));
			unset($data['#text']);
		}

		return $data;

	}//end appendText()

	/**
	 * The tag name of a data key: without a leading `@`, and `item{n}` for a numeric key.
	 *
	 * @param int|string $key The data key.
	 *
	 * @return string
	 */
	private function tagName($key): string {
		$key = ltrim($key, '@');
		if (is_numeric($key) === true) {
			$key = "item$key";
		}

		return $key;

	}//end tagName()

	/**
	 * The elements one value renders as: one per item of an indexed array of arrays, else one.
	 *
	 * @param mixed $value The value under a key.
	 *
	 * @return array<mixed> The data of each element.
	 */
	private function occurrences($value): array {
		if (is_array($value) === true && isset($value[0]) === true && is_array($value[0]) === true) {
			return $value;
		}

		return [$value];

	}//end occurrences()

	/**
	 * Append a child element to the parent.
	 *
	 * @param DOMElement $parent  The parent element.
	 * @param string     $tagName The tag name for the child element.
	 *
	 * @return DOMElement|null The child, or null when it could not be created.
	 */
	private function addChild(DOMElement $parent, string $tagName): ?DOMElement {
		$childElement = $parent->ownerDocument->createElement($tagName);
		if ($childElement === false) {
			return null;
		}

		$parent->appendChild($childElement);

		return $childElement;

	}//end addChild()

	/**
	 * Populate a child element: recurse into an array, else write its text.
	 *
	 * @param DOMElement|null                    $element The child element, or null to skip.
	 * @param array<string, mixed>|string|object $data    The data for the child element.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	private function fillElement(?DOMElement $element, $data): void {
		if ($element === null) {
			return;
		}

		if (is_array($data) === true) {
			$this->buildXmlElement(element: $element, data: $data);
			return;
		}

		$element->appendChild($this->createSafeTextNode(dom: $element->ownerDocument, text: $this->textOf(data: $data)));

	}//end fillElement()

	/**
	 * The text of a scalar or object value.
	 *
	 * Objects without __toString(), and QueryBuilder objects, become a placeholder.
	 *
	 * @param string|object|null|bool|int|float $data The value.
	 *
	 * @return string
	 */
	private function textOf($data): string {
		if (is_object($data) === false) {
			return (string)$data;
		}

		if (method_exists($data, '__toString') === true && ($data instanceof IQueryBuilder) === false) {
			return (string)$data;
		}

		return '[Object of class ' . get_class($data) . ']';

	}//end textOf()

	/**
	 * Process text content safely.
	 *
	 * @param DOMDocument $dom The document.
	 * @param string $text The text to create a node for.
	 *
	 * @return \DOMNode The created node.
	 *
	 * @psalm-param  DOMDocument $dom
	 * @psalm-param  string $text
	 * @psalm-return \DOMNode
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	private function createSafeTextNode(DOMDocument $dom, string $text): \DOMNode {
		// Decode any HTML entities to prevent double encoding.
		// First decode things like &amp; into &.
		$decodedText = html_entity_decode($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
		// Then decode again to handle cases like &#039; into '.
		$decodedText = html_entity_decode($decodedText, (ENT_QUOTES | ENT_HTML5), 'UTF-8');

		// Create a text node with the processed text.
		// Carriage returns will be encoded as decimal entities (&#13;) which are
		// later converted to hexadecimal (&#xD;) in the arrayToXml method.
		return $dom->createTextNode($decodedText);
	}//end createSafeTextNode()
}//end class
