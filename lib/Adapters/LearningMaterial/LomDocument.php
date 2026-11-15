<?php

/**
 * Integriq IMS LOM 1.2 document.
 *
 * A parsed IMS LOM 1.2 record (`czp:` namespace) with XPath reads relative
 * to its `lom` root.
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
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Read access to one LOM record.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
 */
final class LomDocument {
	public const NAMESPACE_URI = 'http://www.imsglobal.org/xsd/imsmd_v1p2';

	/**
	 * XPath over the record, `c` bound to the LOM namespace.
	 *
	 * @var DOMXPath
	 */
	private readonly DOMXPath $xpath;

	/**
	 * The XML helper.
	 *
	 * @var SafeXml
	 */
	private readonly SafeXml $xml;

	/**
	 * Constructor.
	 *
	 * @param DOMElement $root The `lom` element; its document must be set.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function __construct(
		private readonly DOMElement $root,
	) {
		$this->xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument());
		$this->xpath->registerNamespace('c', self::NAMESPACE_URI);
		$this->xml = new SafeXml();
	}//end __construct()

	/**
	 * The trimmed, non-empty, distinct text values at a path.
	 *
	 * @param string $path XPath relative to the `lom` element.
	 *
	 * @return list<string> The values in document order.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function values(string $path): array {
		$values = [];
		foreach ($this->xml->nodes(xpath: $this->xpath, expression: $path, context: $this->root) as $node) {
			$values[] = trim((string)$node->textContent);
		}

		return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
	}//end values()

	/**
	 * The first text value at a path.
	 *
	 * @param string $path XPath relative to the `lom` element.
	 *
	 * @return string The value, or an empty string.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-a-learning-material-source-interface-with-one-normalised-record
	 */
	public function first(string $path): string {
		return ($this->values(path: $path)[0] ?? '');
	}//end first()
}//end class
