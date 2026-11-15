<?php

/**
 * Integriq iWMO/iJW Outbound Bericht Translator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Translates an OpenRegister social-domain case object (a `toewijzing`/
 * assignment or `declaratie`/invoice) into the corresponding Wmo303/Jw303
 * (Toewijzing) or Wmo321/Jw321 (Declaratie) StUF-style XML envelope. See
 * design.md "Message-shape assumptions" for the full field table and its
 * grounding — NO live GGk/VECOZO connection was available in this
 * environment to verify the exact wire shape against.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * {@see IwmoIjwTranslationException} BEFORE any XML is built — this
 * translator MUST NEVER emit an empty tag, a null literal, or an
 * unresolved template marker for a required field. As defense in depth,
 * the fully rendered envelope is scanned (via the shared
 * {@see \OCA\Integriq\Service\Stuf\StufLiteralLeakGuard}, extracted
 * here as part of `stuf-zkn-bridge` so this class and the sibling
 * StUF-ZKN translator share one scan implementation) for leftover `{{`/
 * `}}`/`%%UNRESOLVED%%` markers and rejected if any survive (see
 * design.md "Literal-leak guard").
 *
 * @category Service
 * @package  OCA\Integriq\Service\IwmoIjw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\IwmoIjw;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\IwmoIjwTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Toewijzing/declaratie case object -> Wmo/Jw XML envelope.
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */
class OutboundMessageTranslator {

	/**
	 * Case object kind identifying a care assignment.
	 *
	 * @var string
	 */
	public const KIND_TOEWIJZING = 'toewijzing';

	/**
	 * Case object kind identifying an invoice.
	 *
	 * @var string
	 */
	public const KIND_DECLARATIE = 'declaratie';

	/**
	 * Berichtcode numeric suffix for a Toewijzing.
	 *
	 * @var string
	 */
	private const CODE_TOEWIJZING = '303';

	/**
	 * Berichtcode numeric suffix for a Declaratie.
	 *
	 * @var string
	 */
	private const CODE_DECLARATIE = '321';

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
	 * The stuurgegevens parties: element name to the case field holding its code.
	 *
	 * @var array<string, string>
	 */
	private const PARTIES = [
		'zender' => 'gemeentecode',
		'ontvanger' => 'aanbiederAgbCode',
	];

	/**
	 * The body fields per kind, in envelope order.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const BODY_FIELDS = [
		self::KIND_TOEWIJZING => ['bsn', 'productcode', 'ingangsdatum', 'einddatum', 'omvang', 'leveringsvorm'],
		self::KIND_DECLARATIE => ['toewijzingReferentie', 'productcode', 'factuurnummer', 'bedrag', 'periodeStart', 'periodeEind'],
	];

	/**
	 * Body fields left out when the case object has no value for them.
	 *
	 * @var array<int, string>
	 */
	private const OPTIONAL_BODY_FIELDS = ['einddatum'];

	/**
	 * Required fields per kind — see design.md's outbound field table.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const REQUIRED_FIELDS = [
		self::KIND_TOEWIJZING => [
			'bsn',
			'productcode',
			'ingangsdatum',
			'omvang',
			'leveringsvorm',
			'aanbiederAgbCode',
			'gemeentecode',
		],
		self::KIND_DECLARATIE => [
			'toewijzingReferentie',
			'productcode',
			'factuurnummer',
			'bedrag',
			'periodeStart',
			'periodeEind',
			'aanbiederAgbCode',
			'gemeentecode',
		],
	];

	/**
	 * Translate an OR case object into a berichttype envelope.
	 *
	 * @param array $caseObject The OR case object fields — see design.md's outbound field table
	 *                          for the full assumed vocabulary. Callers pass `domain` on the
	 *                          envelope call, not inside this array.
	 * @param string $kind `toewijzing` or `declaratie`.
	 * @param string $domain `wmo` or `jw` — selects the `Wmo`/`Jw` berichtcode prefix.
	 *
	 * @return array{berichttype: string, ref: string, xml: string} The berichtcode (e.g. `Wmo303`),
	 *                                                              the generated correlation reference, and the rendered envelope XML.
	 *
	 * @throws IwmoIjwTranslationException When a required field is missing/empty, an unsupported
	 *                                     `kind`/`domain` is given, or the rendered envelope still
	 *                                     carries an unresolved template marker.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-a-complete-toewijzing-translates-to-a-valid-wmo303-envelope
	 */
	public function translate(array $caseObject, string $kind, string $domain): array {
		$this->assertSupported(kind: $kind, domain: $domain);
		$this->assertRequiredFieldsPresent(caseObject: $caseObject, kind: $kind);

		$run = new OutboundBerichtRun(document: new DOMDocument(version: '1.0', encoding: 'UTF-8'), caseObject: $caseObject);
		$run->berichttype = $this->domainPrefix(domain: $domain) . $this->berichtCode(kind: $kind);
		$run->ref = strtoupper($this->domainPrefix(domain: $domain)) . '-' . bin2hex(random_bytes(8));

		$root = $run->document->createElement('Bericht');
		$run->document->appendChild($root);
		$this->appendStuurgegevens(run: $run, root: $root);
		$this->appendBody(run: $run, kind: $kind);

		$xml = (string)$run->document->saveXML();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return ['berichttype' => $run->berichttype, 'ref' => $run->ref, 'xml' => $xml];
	}//end translate()

	/**
	 * Refuse an unsupported bericht kind or domain.
	 *
	 * @param string $kind   `toewijzing` or `declaratie`.
	 * @param string $domain `wmo` or `jw`.
	 *
	 * @return void
	 *
	 * @throws IwmoIjwTranslationException When either is not supported.
	 */
	private function assertSupported(string $kind, string $domain): void {
		if (isset(self::REQUIRED_FIELDS[$kind]) === false) {
			throw new IwmoIjwTranslationException(message: 'Unsupported bericht kind "' . $kind . '".');
		}

		if ($domain !== 'wmo' && $domain !== 'jw') {
			throw new IwmoIjwTranslationException(message: 'Unsupported domain "' . $domain . '" — expected "wmo" or "jw".');
		}

	}//end assertSupported()

	/**
	 * The berichttype prefix of a domain: `Wmo` or `Jw`.
	 *
	 * @param string $domain `wmo` or `jw`.
	 *
	 * @return string The prefix.
	 */
	private function domainPrefix(string $domain): string {
		if ($domain === 'wmo') {
			return 'Wmo';
		}

		return 'Jw';

	}//end domainPrefix()

	/**
	 * The berichtcode of a kind: 303 for a toewijzing, 321 for a declaratie.
	 *
	 * @param string $kind `toewijzing` or `declaratie`.
	 *
	 * @return string The code.
	 */
	private function berichtCode(string $kind): string {
		if ($kind === self::KIND_TOEWIJZING) {
			return self::CODE_TOEWIJZING;
		}

		return self::CODE_DECLARATIE;

	}//end berichtCode()

	/**
	 * Append the stuurgegevens: berichtcode, zender, ontvanger, referentienummer and tijdstipBericht.
	 *
	 * @param OutboundBerichtRun $run  The bericht being built.
	 * @param DOMElement         $root The `<Bericht>` element.
	 *
	 * @return void
	 */
	private function appendStuurgegevens(OutboundBerichtRun $run, DOMElement $root): void {
		$stuurgegevens = $run->document->createElement('stuurgegevens');
		$root->appendChild($stuurgegevens);
		$this->appendText(document: $run->document, parent: $stuurgegevens, name: 'berichtcode', value: $run->berichttype);
		$this->appendParties(run: $run, stuurgegevens: $stuurgegevens);
		$this->appendText(document: $run->document, parent: $stuurgegevens, name: 'referentienummer', value: $run->ref);
		$this->appendText(
			document: $run->document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('c')
		);

	}//end appendStuurgegevens()

	/**
	 * Append the zender (the municipality) and the ontvanger (the provider's AGB code).
	 *
	 * @param OutboundBerichtRun $run           The bericht being built.
	 * @param DOMElement         $stuurgegevens The `<stuurgegevens>` element.
	 *
	 * @return void
	 */
	private function appendParties(OutboundBerichtRun $run, DOMElement $stuurgegevens): void {
		foreach (self::PARTIES as $element => $field) {
			$party = $run->document->createElement($element);
			$stuurgegevens->appendChild($party);
			$this->appendText(document: $run->document, parent: $party, name: 'code', value: (string)$run->caseObject[$field]);
		}

	}//end appendParties()

	/**
	 * Append the body with the kind's fields, in order; an optional field is left out when empty.
	 *
	 * @param OutboundBerichtRun $run  The bericht being built.
	 * @param string             $kind `toewijzing` or `declaratie`.
	 *
	 * @return void
	 */
	private function appendBody(OutboundBerichtRun $run, string $kind): void {
		$body = $run->document->createElement('body');
		$run->document->documentElement->appendChild($body);
		foreach (self::BODY_FIELDS[$kind] as $field) {
			if (in_array($field, self::OPTIONAL_BODY_FIELDS, true) === true && empty($run->caseObject[$field]) === true) {
				continue;
			}

			$this->appendText(document: $run->document, parent: $body, name: $field, value: (string)$run->caseObject[$field]);
		}

	}//end appendBody()

	/**
	 * Assert every required field for `$kind` is present and non-empty —
	 * the literal-leak guard's first line of defence.
	 *
	 * @param array $caseObject The OR case object fields.
	 * @param string $kind `toewijzing` or `declaratie`.
	 *
	 * @return void
	 *
	 * @throws IwmoIjwTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-xml-literal-leak-guard
	 */
	private function assertRequiredFieldsPresent(array $caseObject, string $kind): void {
		foreach (self::REQUIRED_FIELDS[$kind] as $field) {
			$value = ($caseObject[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new IwmoIjwTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for a "' . $kind . '" bericht — '
						. 'refusing to build an envelope with unresolved data.'
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
	 * defense in depth beyond the required-fields pre-check. Delegates to
	 * the shared {@see StufLiteralLeakGuard}.
	 *
	 * @param string $xml The fully rendered envelope XML.
	 *
	 * @return void
	 *
	 * @throws IwmoIjwTranslationException When any marker survives.
	 */
	private function assertNoUnresolvedPlaceholder(string $xml): void {
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new IwmoIjwTranslationException(
				message: 'Rendered envelope still contains an unresolved template marker — refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
