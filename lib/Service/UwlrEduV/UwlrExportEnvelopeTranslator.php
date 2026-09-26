<?php

/**
 * Integriq UWLR Export Envelope Translator.
 *
 * Translates a `uwlr` export payload (`eckId`, `schoolBrin`) for exactly
 * one of three subtypes — `pupil`, `group`, `teacher` — supplied by
 * learniq's `DataExchangeRunHandler` for the `uwlr` job type, into a
 * UWLR-style XML envelope. See design.md "Open Questions" — the exact
 * UWLR wire format was not in the corpus; the shape follows the same
 * Edukoppeling/StUF convention as the other three adapters in this lane,
 * isolated behind this one class.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field, or an unknown
 * subtype, raises UwlrEduVTranslationException BEFORE any XML is built.
 *
 * @category Service
 * @package  OCA\Integriq\Service\UwlrEduV
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * UWLR export payload -> UWLR XML envelope.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-002-uwlr-export-envelope-translation-across-three-subtypes
 */
class UwlrExportEnvelopeTranslator {

	/**
	 * The three UWLR export subtypes.
	 *
	 * @var array<int, string>
	 */
	public const SUBTYPES = ['pupil', 'group', 'teacher'];

	/**
	 * Required fields for every UWLR export payload.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FIELDS = ['eckId', 'schoolBrin'];

	/**
	 * Constructor.
	 *
	 * @param StufLiteralLeakGuard $leakGuard Shared literal-leak scan.
	 */
	public function __construct(
		private readonly StufLiteralLeakGuard $leakGuard = new StufLiteralLeakGuard(),
	) {

	}//end __construct()

	/**
	 * Translate a UWLR export payload into a wire envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param string $subtype One of `pupil`, `group`, `teacher`.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws UwlrEduVTranslationException When the subtype is unknown, a required field is
	 *                                      missing/empty, or the rendered envelope still carries
	 *                                      an unresolved template marker.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-pupil-export-payload-translates-to-a-valid-envelope
	 */
	public function translate(string $kenmerk, string $subtype, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new UwlrEduVTranslationException(message: 'A UWLR export requires a non-empty kenmerk (correlation id).');
		}

		if (in_array($subtype, self::SUBTYPES, true) === false) {
			throw new UwlrEduVTranslationException(
				message: 'Unknown UWLR subtype "' . $subtype . '" — expected one of: ' . implode(', ', self::SUBTYPES) . '.'
			);
		}

		$this->assertRequiredFieldsPresent(payload: $payload);

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('UwlrExport');
		$document->appendChild($root);

		$stuurgegevens = $document->createElement('stuurgegevens');
		$root->appendChild($stuurgegevens);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'kenmerk', value: $kenmerk);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'subtype', value: $subtype);
		$this->appendText(
			document: $document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('c')
		);

		$body = $document->createElement('body');
		$root->appendChild($body);
		foreach (self::REQUIRED_FIELDS as $field) {
			$this->appendText(document: $document, parent: $body, name: $field, value: (string)$payload[$field]);
		}

		$xml = (string)$document->saveXML();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()

	/**
	 * Assert every required field is present and non-empty — the
	 * literal-leak guard's first line of defence.
	 *
	 * @param array $payload The field payload.
	 *
	 * @return void
	 *
	 * @throws UwlrEduVTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-missing-eckid-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload): void {
		foreach (self::REQUIRED_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new UwlrEduVTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for a UWLR export — refusing '
						. 'to build an envelope with unresolved data.'
				);
			}
		}

	}//end assertRequiredFieldsPresent()

	/**
	 * Append a text-valued child element.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The child element name.
	 * @param string $value The text value.
	 *
	 * @return void
	 */
	private function appendText(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		$parent->appendChild($document->createElement($name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES)));

	}//end appendText()

	/**
	 * Scan the rendered envelope for leftover unresolved template markers.
	 *
	 * @param string $xml The fully rendered envelope XML.
	 *
	 * @return void
	 *
	 * @throws UwlrEduVTranslationException When any marker survives.
	 */
	private function assertNoUnresolvedPlaceholder(string $xml): void {
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new UwlrEduVTranslationException(
				message: 'Rendered envelope still contains an unresolved template marker — refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
