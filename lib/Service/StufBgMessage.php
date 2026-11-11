<?php

/**
 * Integriq StufBgMessage: the SOAP body of one StUF-BG message and the
 * namespaces it is read in.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/stuf-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use SimpleXMLElement;

/**
 * A parsed StUF-BG message: its SOAP body, the document's namespace map, and
 * the BG and StUF namespace URIs resolved from it (empty when not found).
 *
 * @spec openspec/specs/stuf-adapter/spec.md
 */
final class StufBgMessage {
	/**
	 * The BG namespace URI, or empty when the document has none.
	 *
	 * @var string
	 */
	public string $bgNs = '';

	/**
	 * The StUF namespace URI, or empty when the document has none.
	 *
	 * @var string
	 */
	public string $stufNs = '';

	/**
	 * Constructor.
	 *
	 * @param SimpleXMLElement $body       The SOAP body element.
	 * @param array            $namespaces The document's namespace map (prefix => URI).
	 */
	public function __construct(
		public readonly SimpleXMLElement $body,
		public readonly array $namespaces,
	) {

	}//end __construct()
}//end class
