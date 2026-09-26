<?php

/**
 * Integriq DUO Verzuimloket Outbound Envelope Translator.
 *
 * Translates a `meldingType` (`eerste-melding`|`herhaalmelding`|
 * `langdurig-relatief-verzuim`) plus its field payload — supplied by
 * learniq's `DataExchangePayloadBuilder::composeLeerplichtFile()`
 * (`leerplicht` job type) — into an Edukoppeling-style XML envelope. See
 * design.md "Trade-offs" — the exact DUO Verzuimloket berichtdefinitie/XSD
 * was not in the corpus; the shape follows the same Edukoppeling/StUF
 * convention as `integriq-adapter-rod`'s translator, isolated behind this
 * one class.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * VerzuimloketTranslationException BEFORE any XML is built. As defense in
 * depth, the fully rendered envelope is scanned via the shared
 * StufLiteralLeakGuard.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Verzuimloket
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
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Verzuimloket;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * meldingType + field payload -> Edukoppeling XML envelope.
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */
class VerzuimloketEnvelopeTranslator {

	/**
	 * The initial 16-uur/4-weken (Leerplichtwet art. 21a) melding.
	 *
	 * @var string
	 */
	public const MELDING_EERSTE = 'eerste-melding';

	/**
	 * A repeat melding for the same pupil.
	 *
	 * @var string
	 */
	public const MELDING_HERHAAL = 'herhaalmelding';

	/**
	 * Langdurig relatief verzuim (LRV).
	 *
	 * @var string
	 */
	public const MELDING_LRV = 'langdurig-relatief-verzuim';

	/**
	 * Required fields per meldingType — see design.md's field table.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_FIELDS = [
		self::MELDING_EERSTE => ['bsn', 'windowStart', 'windowEnd', 'metricValue'],
		self::MELDING_HERHAAL => ['bsn', 'windowStart', 'windowEnd', 'metricValue'],
		self::MELDING_LRV => ['bsn', 'startDate'],
	];

	/**
	 * Optional fields appended (JSON-encoded) when present, for every kind.
	 *
	 * @var array<int, string>
	 */
	private const OPTIONAL_FIELDS = ['breachingRecords', 'interventions'];

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
	 * Translate a meldingType + payload into an Edukoppeling envelope.
	 *
	 * @param string $meldingType One of the recognised Verzuimloket melding kinds.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws VerzuimloketTranslationException When `meldingType` is unsupported, a required field is
	 *                                          missing/empty, or the rendered envelope still carries an
	 *                                          unresolved template marker.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-a-complete-eerste-melding-translates-to-a-valid-envelope
	 */
	public function translate(string $meldingType, string $kenmerk, array $payload): string {
		if (isset(self::REQUIRED_FIELDS[$meldingType]) === false) {
			throw new VerzuimloketTranslationException(message: 'Unsupported Verzuimloket meldingType "' . $meldingType . '".');
		}

		if (trim($kenmerk) === '') {
			throw new VerzuimloketTranslationException(message: 'A Verzuimloket melding requires a non-empty kenmerk (correlation id).');
		}

		$this->assertRequiredFieldsPresent(payload: $payload, meldingType: $meldingType);

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('VerzuimloketMelding');
		$document->appendChild($root);

		$stuurgegevens = $document->createElement('stuurgegevens');
		$root->appendChild($stuurgegevens);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'meldingType', value: $meldingType);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'kenmerk', value: $kenmerk);
		$this->appendText(
			document: $document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('c')
		);

		$body = $document->createElement('body');
		$root->appendChild($body);

		foreach (self::REQUIRED_FIELDS[$meldingType] as $field) {
			$this->appendText(document: $document, parent: $body, name: $field, value: (string)$payload[$field]);
		}

		foreach (self::OPTIONAL_FIELDS as $field) {
			if (empty($payload[$field]) === false) {
				$encoded = (string)json_encode($payload[$field]);
				$this->appendText(document: $document, parent: $body, name: $field, value: $encoded);
			}
		}

		$xml = (string)$document->saveXML();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()

	/**
	 * Assert every required field for `$meldingType` is present and
	 * non-empty — the literal-leak guard's first line of defence.
	 *
	 * @param array $payload The field payload.
	 * @param string $meldingType The Verzuimloket melding kind.
	 *
	 * @return void
	 *
	 * @throws VerzuimloketTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload, string $meldingType): void {
		foreach (self::REQUIRED_FIELDS[$meldingType] as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new VerzuimloketTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for a "' . $meldingType . '" '
						. 'melding — refusing to build an envelope with unresolved data.'
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
	 * @throws VerzuimloketTranslationException When any marker survives.
	 */
	private function assertNoUnresolvedPlaceholder(string $xml): void {
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new VerzuimloketTranslationException(
				message: 'Rendered envelope still contains an unresolved template marker — refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
