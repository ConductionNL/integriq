<?php

/**
 * Integriq DUO ROD Outbound Envelope Translator.
 *
 * Translates a `berichtsoort` (`inschrijving`|`uitschrijving`|
 * `verblijfsgegevens`|`schooladvies`) plus its field payload — supplied by
 * learniq's `DataExchangePayloadBuilder` (`bron-rod` job type) — into an
 * Edukoppeling-style XML envelope. See design.md "Trade-offs" — the exact
 * DUO ROD berichtdefinitie/XSD was not in the corpus read for this change;
 * the envelope shape follows the Edukoppeling/StUF convention already used
 * by DigikoppelingAdapter and iwmo-ijw-adapter's OutboundMessageTranslator
 * as the best-evidenced structural analogue, isolated behind this one
 * class so a future correction against DUO's real berichtdefinitie is
 * localized.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * {@see RodTranslationException} BEFORE any XML is built — this translator
 * MUST NEVER emit an empty tag, a null literal, or an unresolved template
 * marker for a required field. As defense in depth, the fully rendered
 * envelope is scanned (via the shared
 * {@see \OCA\Integriq\Service\Stuf\StufLiteralLeakGuard}) for leftover
 * `{{`/`}}`/`%%UNRESOLVED%%` markers and rejected if any survive.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Rod
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
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Rod;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * berichtsoort + field payload -> Edukoppeling XML envelope.
 *
 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#requirement-req-002-outbound-envelope-translation-with-a-literal-leak-guard
 */
class RodEnvelopeTranslator {

	/**
	 * Recognised ROD berichtsoort kinds.
	 *
	 * @var string
	 */
	public const KIND_INSCHRIJVING = 'inschrijving';

	/**
	 * @var string
	 */
	public const KIND_UITSCHRIJVING = 'uitschrijving';

	/**
	 * @var string
	 */
	public const KIND_VERBLIJFSGEGEVENS = 'verblijfsgegevens';

	/**
	 * @var string
	 */
	public const KIND_SCHOOLADVIES = 'schooladvies';

	/**
	 * Required fields per berichtsoort — see design.md's field table. `bsn`
	 * is required on every kind: DUO identifies the leerling by BSN for
	 * every ROD message type.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_FIELDS = [
		self::KIND_INSCHRIJVING => ['bsn', 'inschrijvingsdatum', 'leerjaar', 'groep'],
		self::KIND_UITSCHRIJVING => ['bsn', 'uitschrijvingsdatum', 'redenUitschrijving'],
		self::KIND_VERBLIJFSGEGEVENS => ['bsn', 'ingangsdatum', 'leerjaar', 'groep'],
		self::KIND_SCHOOLADVIES => ['bsn', 'schooladviesWaarde', 'schooladviesDatum'],
	];

	/**
	 * Optional fields appended when present, per berichtsoort — the OPP
	 * (ontwikkelingsperspectiefplan) dates named in M3-integrations.md row
	 * I1's field list.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const OPTIONAL_FIELDS = [
		self::KIND_INSCHRIJVING => ['oppStartdatum', 'oppEinddatum'],
		self::KIND_VERBLIJFSGEGEVENS => ['oppStartdatum', 'oppEinddatum'],
	];

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
	 * Translate a berichtsoort + payload into an Edukoppeling envelope.
	 *
	 * @param string $berichtsoort One of the recognised ROD berichtsoort kinds.
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — see design.md's field table.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws RodTranslationException When `berichtsoort` is unsupported, a required field is
	 *                                 missing/empty, or the rendered envelope still carries an
	 *                                 unresolved template marker.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
	 */
	public function translate(string $berichtsoort, string $kenmerk, array $payload): string {
		if (isset(self::REQUIRED_FIELDS[$berichtsoort]) === false) {
			throw new RodTranslationException(message: 'Unsupported ROD berichtsoort "' . $berichtsoort . '".');
		}

		if (trim($kenmerk) === '') {
			throw new RodTranslationException(message: 'A ROD bericht requires a non-empty kenmerk (correlation id).');
		}

		$this->assertRequiredFieldsPresent(payload: $payload, berichtsoort: $berichtsoort);

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('RodBericht');
		$document->appendChild($root);

		$stuurgegevens = $document->createElement('stuurgegevens');
		$root->appendChild($stuurgegevens);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'berichtsoort', value: $berichtsoort);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'kenmerk', value: $kenmerk);
		$this->appendText(
			document: $document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('c')
		);

		$body = $document->createElement('body');
		$root->appendChild($body);

		foreach (self::REQUIRED_FIELDS[$berichtsoort] as $field) {
			$this->appendText(document: $document, parent: $body, name: $field, value: (string)$payload[$field]);
		}

		foreach ((self::OPTIONAL_FIELDS[$berichtsoort] ?? []) as $field) {
			if (empty($payload[$field]) === false) {
				$this->appendText(document: $document, parent: $body, name: $field, value: (string)$payload[$field]);
			}
		}

		$xml = (string)$document->saveXML();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()

	/**
	 * Assert every required field for `$berichtsoort` is present and
	 * non-empty — the literal-leak guard's first line of defence.
	 *
	 * @param array $payload The field payload.
	 * @param string $berichtsoort The ROD berichtsoort kind.
	 *
	 * @return void
	 *
	 * @throws RodTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload, string $berichtsoort): void {
		foreach (self::REQUIRED_FIELDS[$berichtsoort] as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new RodTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for a "' . $berichtsoort . '" '
						. 'bericht — refusing to build an envelope with unresolved data.'
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
	 * Scan the rendered envelope for leftover unresolved template markers —
	 * defense in depth beyond the required-fields pre-check.
	 *
	 * @param string $xml The fully rendered envelope XML.
	 *
	 * @return void
	 *
	 * @throws RodTranslationException When any marker survives.
	 */
	private function assertNoUnresolvedPlaceholder(string $xml): void {
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new RodTranslationException(
				message: 'Rendered envelope still contains an unresolved template marker — refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
