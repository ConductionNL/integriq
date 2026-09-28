<?php

/**
 * Integriq DUO ROD Outbound Envelope Translator.
 *
 * Translates a `berichtsoort` (`inschrijving`|`uitschrijving`|
 * `verblijfsgegevens`|`schooladvies`) plus its field payload, handed over by
 * the owning app's exchange gate (`bron-rod` target), into an
 * Edukoppeling-style XML envelope. See design.md "Trade-offs": DUO's XSDs are
 * not printed in the PvE, so element names follow the PvE field names in
 * camelCase, isolated behind this one class so a correction against DUO's
 * real XSD stays local.
 *
 * PERSOONSGEBONDEN NUMMER: DUO identifies a pupil by a choice field, either a
 * `burgerservicenummer` or an `onderwijsnummer` inside `persoonsgebondenNummer`
 * (PvE ROD-PO 1.14.2, 15-4-2026, section 7.9.1). The payload carries the
 * number in `persoonsgebondenNummer` and its type in
 * `persoonsgebondenNummerType`. The legacy `bsn` key still reads as a
 * burgerservicenummer. An exception message names the field, never the value.
 *
 * SCHOOLADVIES: rendered as DUO's `AanleverenAdviesVO_Request` (contract
 * `DUO_PO_AdviesVO_V1`, PvE section 7.9.1).
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * {@see RodTranslationException} BEFORE any XML is built. This translator
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
 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-001-the-persoonsgebonden-nummer-goes-in-duos-choice-element
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
	 * The two choices of DUO's persoonsgebonden nummer.
	 *
	 * @var array<int, string>
	 */
	public const NUMBER_TYPES = ['burgerservicenummer', 'onderwijsnummer'];

	/**
	 * The DUO contract namespace of AanleverenAdviesVO_Request. Derived from
	 * the PvE's `wsa:Action` pattern (`http://duo.nl/contract/<contract>`).
	 *
	 * @var string
	 */
	public const ADVIES_VO_NAMESPACE = 'http://duo.nl/contract/DUO_PO_AdviesVO_V1';

	/**
	 * Required fields per berichtsoort besides the persoonsgebonden nummer,
	 * which every kind requires. See design.md's field table.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_FIELDS = [
		self::KIND_INSCHRIJVING => ['inschrijvingsdatum', 'leerjaar', 'groep'],
		self::KIND_UITSCHRIJVING => ['uitschrijvingsdatum', 'redenUitschrijving'],
		self::KIND_VERBLIJFSGEGEVENS => ['ingangsdatum', 'leerjaar', 'groep'],
		self::KIND_SCHOOLADVIES => ['adviesvolgnummer', 'vestigingscode', 'adviesjaar'],
	];

	/**
	 * Optional fields appended when present, per berichtsoort: the OPP
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
	 * Format rules of the AanleverenAdviesVO_Request fields (PvE 7.9.1),
	 * checked when the field has a value. Presence is checked separately.
	 *
	 * @var array<string, string>
	 */
	private const ADVIES_FORMATS = [
		'adviesvolgnummer' => '/^[A-Za-z0-9]{1,20}$/',
		'onderwijsaanbieder' => '/^\d{3}A\d{3}$/',
		'onderwijslocatie' => '/^\d{3}X\d{3}$/',
		'vestigingscode' => '/^[A-Za-z0-9]{6}$/',
		'adviesjaar' => '/^\d{4}$/',
	];

	/**
	 * Format of an AdviesVO value: DUO's value list uses upper case, digits,
	 * underscores and a slash, at most 70 characters. DUO checks the value
	 * itself (`046_waardenlijst_fout`).
	 *
	 * @var string
	 */
	private const ADVIES_VALUE_FORMAT = '/^[A-Z][A-Z0-9_\/]{0,69}$/';

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
	 * @param array $payload The field payload, see design.md's field table.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws RodTranslationException When `berichtsoort` is unsupported, a required field is
	 *                                 missing/empty or malformed, or the rendered envelope still
	 *                                 carries an unresolved template marker. The message names
	 *                                 fields, never values.
	 *
	 * @spec openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#scenario-a-complete-inschrijving-translates-to-a-valid-envelope
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-001-the-persoonsgebonden-nummer-goes-in-duos-choice-element
	 */
	public function translate(string $berichtsoort, string $kenmerk, array $payload): string {
		if (isset(self::REQUIRED_FIELDS[$berichtsoort]) === false) {
			throw new RodTranslationException(message: 'Unsupported ROD berichtsoort "'.$berichtsoort.'".');
		}

		if (trim($kenmerk) === '') {
			throw new RodTranslationException(message: 'A ROD bericht requires a non-empty kenmerk (correlation id).');
		}

		$number = $this->personalNumber(payload: $payload);
		$this->assertRequiredFieldsPresent(payload: $payload, berichtsoort: $berichtsoort);

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root     = $document->createElement('RodBericht');
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

		$this->appendBody(document: $document, body: $body, payload: $payload, number: $number, berichtsoort: $berichtsoort);

		$xml = (string) $document->saveXML();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()

	/**
	 * Resolve and check the persoonsgebonden nummer and its type.
	 *
	 * @param array $payload The field payload.
	 *
	 * @return array{type: string, value: string} The choice element name and the number.
	 *
	 * @throws RodTranslationException When the number or its type is missing or malformed.
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-001-the-persoonsgebonden-nummer-goes-in-duos-choice-element
	 */
	public function personalNumber(array $payload): array {
		$value = $this->stringOrNull(value: ($payload['persoonsgebondenNummer'] ?? null));
		$type  = $this->stringOrNull(value: ($payload['persoonsgebondenNummerType'] ?? null));

		if ($value === null) {
			// Legacy callers of POST /api/rod/send send `bsn`.
			$value = $this->stringOrNull(value: ($payload['bsn'] ?? null));
			$type  = 'burgerservicenummer';
		}

		if ($value === null) {
			throw new RodTranslationException(
				message: 'Required field "persoonsgebondenNummer" is missing or empty; refusing to build an envelope.'
			);
		}

		if ($type === null || in_array($type, self::NUMBER_TYPES, true) === false) {
			throw new RodTranslationException(
				message: 'Field "persoonsgebondenNummerType" must be burgerservicenummer or onderwijsnummer.'
			);
		}

		if (preg_match('/^\d{9}$/', $value) !== 1) {
			throw new RodTranslationException(message: 'Field "persoonsgebondenNummer" must be exactly 9 digits.');
		}

		return ['type' => $type, 'value' => $value];
	}//end personalNumber()

	/**
	 * Append the body the berichtsoort needs.
	 *
	 * @param DOMDocument                        $document     The owning document.
	 * @param DOMElement                         $body         The body element.
	 * @param array                              $payload      The field payload.
	 * @param array{type: string, value: string} $number       The persoonsgebonden nummer.
	 * @param string                             $berichtsoort The ROD berichtsoort kind.
	 *
	 * @return void
	 */
	private function appendBody(DOMDocument $document, DOMElement $body, array $payload, array $number, string $berichtsoort): void {
		if ($berichtsoort === self::KIND_SCHOOLADVIES) {
			$this->appendAdviesVo(document: $document, body: $body, payload: $payload, number: $number);
			return;
		}

		$this->appendRegistration(document: $document, body: $body, payload: $payload, number: $number, berichtsoort: $berichtsoort);
	}//end appendBody()

	/**
	 * Append the body of an inschrijving, uitschrijving or verblijfsgegevens.
	 *
	 * @param DOMDocument                       $document     The owning document.
	 * @param DOMElement                        $body         The body element.
	 * @param array                             $payload      The field payload.
	 * @param array{type: string, value: string} $number       The persoonsgebonden nummer.
	 * @param string                            $berichtsoort The ROD berichtsoort kind.
	 *
	 * @return void
	 */
	private function appendRegistration(
		DOMDocument $document,
		DOMElement $body,
		array $payload,
		array $number,
		string $berichtsoort
	): void {
		$this->appendPersonalNumber(document: $document, parent: $body, number: $number);

		foreach (self::REQUIRED_FIELDS[$berichtsoort] as $field) {
			$this->appendText(document: $document, parent: $body, name: $field, value: (string) $payload[$field]);
		}

		foreach ((self::OPTIONAL_FIELDS[$berichtsoort] ?? []) as $field) {
			if (empty($payload[$field]) === false) {
				$this->appendText(document: $document, parent: $body, name: $field, value: (string) $payload[$field]);
			}
		}
	}//end appendRegistration()

	/**
	 * Append an AanleverenAdviesVO_Request (PvE 7.9.1) to the body.
	 *
	 * @param DOMDocument                       $document The owning document.
	 * @param DOMElement                        $body     The body element.
	 * @param array                             $payload  The field payload.
	 * @param array{type: string, value: string} $number   The persoonsgebonden nummer.
	 *
	 * @return void
	 *
	 * @throws RodTranslationException When a field is malformed or no advice is given.
	 *
	 * @spec openspec/changes/rod-adapter-bsn/specs/rod-adapter/spec.md#requirement-req-002-the-school-advice-is-sent-as-aanleverenadviesvo_request
	 */
	private function appendAdviesVo(DOMDocument $document, DOMElement $body, array $payload, array $number): void {
		$this->assertAdviesFormats(payload: $payload);

		$advies1 = $this->advies(payload: $payload, adviesField: 'advies1', dateField: 'advies1Datum');
		$advies2 = $this->advies(payload: $payload, adviesField: 'advies2', dateField: 'advies2Datum');
		if ($advies1 === null && $advies2 === null) {
			throw new RodTranslationException(
				message: 'At least one of "advies1" and "advies2" is required (DUO 103_advies_ontbreekt).'
			);
		}

		$request = $document->createElementNS(self::ADVIES_VO_NAMESPACE, 'AanleverenAdviesVO_Request');
		$body->appendChild($request);

		$this->appendPersonalNumber(document: $document, parent: $request, number: $number);
		$this->appendText(document: $document, parent: $request, name: 'adviesvolgnummer', value: (string) $payload['adviesvolgnummer']);
		foreach (['onderwijsaanbieder', 'onderwijslocatie'] as $field) {
			$value = $this->stringOrNull(value: ($payload[$field] ?? null));
			if ($value !== null) {
				$this->appendText(document: $document, parent: $request, name: $field, value: $value);
			}
		}

		$this->appendText(document: $document, parent: $request, name: 'vestigingscode', value: (string) $payload['vestigingscode']);
		$this->appendText(document: $document, parent: $request, name: 'adviesjaar', value: (string) $payload['adviesjaar']);

		foreach (['advies1' => $advies1, 'advies2' => $advies2] as $name => $advies) {
			if ($advies === null) {
				continue;
			}

			$element = $document->createElement($name);
			$request->appendChild($element);
			$this->appendText(document: $document, parent: $element, name: 'advies', value: $advies['advies']);
			$this->appendText(document: $document, parent: $element, name: 'adviesdatum', value: $advies['adviesdatum']);
		}
	}//end appendAdviesVo()

	/**
	 * Check every AanleverenAdviesVO field that has a value against its DUO format.
	 *
	 * @param array $payload The field payload.
	 *
	 * @return void
	 *
	 * @throws RodTranslationException Naming the first malformed field.
	 */
	private function assertAdviesFormats(array $payload): void {
		foreach (self::ADVIES_FORMATS as $field => $pattern) {
			$value = $this->stringOrNull(value: ($payload[$field] ?? null));
			if ($value !== null && preg_match($pattern, $value) !== 1) {
				throw new RodTranslationException(message: 'Field "'.$field.'" does not match the DUO format.');
			}
		}
	}//end assertAdviesFormats()

	/**
	 * Read one combined Advies + Adviesdatum pair; both or neither.
	 *
	 * @param array  $payload     The field payload.
	 * @param string $adviesField The advice value field.
	 * @param string $dateField   The advice date field.
	 *
	 * @return array{advies: string, adviesdatum: string}|null The pair, or null when both are empty.
	 *
	 * @throws RodTranslationException When only one half is given or either is malformed.
	 */
	private function advies(array $payload, string $adviesField, string $dateField): ?array {
		$advies = $this->stringOrNull(value: ($payload[$adviesField] ?? null));
		$date   = $this->stringOrNull(value: ($payload[$dateField] ?? null));
		if ($advies === null && $date === null) {
			return null;
		}

		if ($advies === null || $date === null) {
			throw new RodTranslationException(
				message: 'Fields "'.$adviesField.'" and "'.$dateField.'" must be given together.'
			);
		}

		if (preg_match(self::ADVIES_VALUE_FORMAT, $advies) !== 1) {
			throw new RodTranslationException(message: 'Field "'.$adviesField.'" is not an AdviesVO value.');
		}

		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) !== 1
			|| checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) === false
		) {
			throw new RodTranslationException(message: 'Field "'.$dateField.'" must be a Y-m-d date.');
		}

		return ['advies' => $advies, 'adviesdatum' => $date];
	}//end advies()

	/**
	 * Append the persoonsgebonden nummer as DUO's choice element.
	 *
	 * @param DOMDocument                       $document The owning document.
	 * @param DOMElement                        $parent   The parent element.
	 * @param array{type: string, value: string} $number   The resolved number.
	 *
	 * @return void
	 */
	private function appendPersonalNumber(DOMDocument $document, DOMElement $parent, array $number): void {
		$wrapper = $document->createElement('persoonsgebondenNummer');
		$parent->appendChild($wrapper);
		$this->appendText(document: $document, parent: $wrapper, name: $number['type'], value: $number['value']);
	}//end appendPersonalNumber()

	/**
	 * Assert every required field for `$berichtsoort` is present and
	 * non-empty: the literal-leak guard's first line of defence.
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
			if ($this->stringOrNull(value: ($payload[$field] ?? null)) === null) {
				throw new RodTranslationException(
					message: 'Required field "'.$field.'" is missing or empty for a "'.$berichtsoort.'" '
						.'bericht; refusing to build an envelope with unresolved data.'
				);
			}
		}

	}//end assertRequiredFieldsPresent()

	/**
	 * A scalar as a trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$string = trim((string) $value);
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()

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
	 * Scan the rendered envelope for leftover unresolved template markers:
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
				message: 'Rendered envelope still contains an unresolved template marker; refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
