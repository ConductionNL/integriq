<?php

/**
 * Contract of the ArrayToXml utility.
 *
 * @category Contract
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

declare(strict_types=1);

namespace OCA\Integriq\Service\Format;

/**
 * Writes an array as an XML document; see {@see ArrayToXml}.
 *
 * @spec openspec/specs/xml-response/spec.md
 */
interface XmlEncoderInterface {
	/**
	 * Convert an array to XML.
	 *
	 * @param array<string, mixed> $data    The data to convert.
	 * @param string|null          $rootTag Optional root tag name (overrides @root in data).
	 *
	 * @return string The XML string or empty string on failure.
	 *
	 * @spec openspec/specs/xml-response/spec.md
	 */
	public function convert(array $data, ?string $rootTag = null): string;
}//end interface
