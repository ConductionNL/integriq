<?php

/**
 * OpenConnector Shared StUF XML Parser.
 *
 * XXE-hardened XML parsing shared by every StUF-family bridge that consumes
 * externally-delivered XML in this app (`iwmo-ijw-adapter`'s
 * `InboundReturnTranslator`, `stuf-zkn-bridge`'s inbound translator).
 * Extracted from `InboundReturnTranslator::parseXml()` (iwmo-ijw-adapter,
 * 2026-07-14) verbatim — same flag, same error-collection shape, same
 * "never throw a domain exception from here" contract, so both callers keep
 * choosing their own exception type (`IwmoIjwTranslationException` /
 * `StufZknTranslationException`) while sharing the actual XXE-hardening
 * logic instead of maintaining two copies that could silently drift.
 *
 * XXE hardening: parsing uses `LIBXML_NONET` and NEVER `LIBXML_NOENT`/
 * `LIBXML_DTDLOAD` — external entity expansion stays disabled (libxml's
 * default posture since 2.9). A malicious inbound envelope cannot read
 * local files or make outbound requests via XML entities.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-shared-xxe-hardened-stuf-xml-parsing-req-000
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Stuf;

use OCA\OpenConnector\Util\SafeXmlParser;
use SimpleXMLElement;
use Throwable;

/**
 * Parses a raw XML string into a {@see SimpleXMLElement}, never throwing —
 * callers decide the domain exception on a `null` result.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-shared-xxe-hardened-stuf-xml-parsing-req-000
 */
class StufXmlParser {
	/**
	 * Parse raw XML, XXE-hardened.
	 *
	 * Namespaced documents (e.g. a StUF-ZKN SOAP envelope's `xmlns:StUF`/
	 * `xmlns:zkn` prefixes) parse fine through this method unchanged —
	 * callers use `SimpleXMLElement::children($ns)`/`->xpath()` with the
	 * relevant namespace URI on the returned element, same as any
	 * `simplexml_load_string()` result.
	 *
	 * @param string $xml The raw XML string, exactly as received on the wire.
	 *
	 * @return SimpleXMLElement|null The parsed root element, or null on empty input,
	 *                               malformed XML, or any libxml error.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-shared-xxe-hardened-stuf-xml-parsing-req-000
	 */
	public function parse(string $xml): ?SimpleXMLElement {
		if (trim($xml) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			// LIBXML_NONET alone blocks network fetches but leaves whatever
			// external-entity loader happens to be installed in place. SafeXmlParser
			// pins it to null for the duration of the parse, which is the half of
			// the defence this call site was missing.
			$root = SafeXmlParser::parse($xml, SimpleXMLElement::class, LIBXML_NONET);
		} catch (Throwable) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return null;
		}

		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($root === false || $errors !== []) {
			return null;
		}

		return $root;
	}//end parse()
}//end class
