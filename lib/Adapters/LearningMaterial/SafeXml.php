<?php

/**
 * Integriq safe XML loader.
 *
 * Loads an XML string into a DOM document without network access and
 * without leaking libxml errors into the request.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * XML loading and node queries that fail quietly.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
 */
final class SafeXml {
	/**
	 * The document element of an XML string, when it parses and sits in the namespace.
	 *
	 * @param string $xml The XML.
	 * @param string $namespace The namespace URI the root must carry.
	 *
	 * @return DOMElement|null The root, or null.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
	 */
	public function root(string $xml, string $namespace): ?DOMElement {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET) === true;
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$root = $document->documentElement;
		if ($loaded === false || $root === null || $root->namespaceURI !== $namespace) {
			return null;
		}

		return $root;
	}//end root()

	/**
	 * The nodes an XPath expression selects, an empty list when it selects none or fails.
	 *
	 * @param DOMXPath $xpath The XPath.
	 * @param string $expression The expression.
	 * @param DOMNode|null $context The context node.
	 *
	 * @return list<DOMNode> The nodes.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-recorded-fixtures-no-live-calls-in-tests
	 */
	public function nodes(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array {
		$nodes = $xpath->query($expression, $context);
		if ($nodes === false) {
			return [];
		}

		return array_values(iterator_to_array($nodes));
	}//end nodes()
}//end class
