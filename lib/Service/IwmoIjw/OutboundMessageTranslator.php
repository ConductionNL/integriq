<?php

/**
 * OpenConnector iWMO/iJW Outbound Bericht Translator.
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
 * {@see \OCA\OpenConnector\Service\Stuf\StufLiteralLeakGuard}, extracted
 * here as part of `stuf-zkn-bridge` so this class and the sibling
 * StUF-ZKN translator share one scan implementation) for leftover `{{`/
 * `}}`/`%%UNRESOLVED%%` markers and rejected if any survive (see
 * design.md "Literal-leak guard").
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\IwmoIjw
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\IwmoIjw;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\OpenConnector\Exception\IwmoIjwTranslationException;
use OCA\OpenConnector\Service\Stuf\StufLiteralLeakGuard;

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
		if (isset(self::REQUIRED_FIELDS[$kind]) === false) {
			throw new IwmoIjwTranslationException(message: 'Unsupported bericht kind "' . $kind . '".');
		}

		if ($domain !== 'wmo' && $domain !== 'jw') {
			throw new IwmoIjwTranslationException(message: 'Unsupported domain "' . $domain . '" — expected "wmo" or "jw".');
		}

		$this->assertRequiredFieldsPresent(caseObject: $caseObject, kind: $kind);

		$prefix = 'Jw';
		if ($domain === 'wmo') {
			$prefix = 'Wmo';
		}

		$code = self::CODE_DECLARATIE;
		if ($kind === self::KIND_TOEWIJZING) {
			$code = self::CODE_TOEWIJZING;
		}

		$berichttype = $prefix . $code;
		$ref = strtoupper($prefix) . '-' . bin2hex(random_bytes(8));

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('Bericht');
		$document->appendChild($root);

		$stuurgegevens = $document->createElement('stuurgegevens');
		$root->appendChild($stuurgegevens);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'berichtcode', value: $berichttype);

		$zender = $document->createElement('zender');
		$stuurgegevens->appendChild($zender);
		$this->appendText(
			document: $document,
			parent: $zender,
			name: 'code',
			value: (string)$caseObject['gemeentecode']
		);

		$ontvanger = $document->createElement('ontvanger');
		$stuurgegevens->appendChild($ontvanger);
		$this->appendText(
			document: $document,
			parent: $ontvanger,
			name: 'code',
			value: (string)$caseObject['aanbiederAgbCode']
		);

		$this->appendText(document: $document, parent: $stuurgegevens, name: 'referentienummer', value: $ref);
		$this->appendText(
			document: $document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('c')
		);

		$body = $document->createElement('body');
		$root->appendChild($body);

		if ($kind === self::KIND_TOEWIJZING) {
			$this->appendToewijzingBody(document: $document, body: $body, caseObject: $caseObject);
		}

		if ($kind === self::KIND_DECLARATIE) {
			$this->appendDeclaratieBody(document: $document, body: $body, caseObject: $caseObject);
		}

		$xml = (string)$document->saveXML();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return ['berichttype' => $berichttype, 'ref' => $ref, 'xml' => $xml];
	}//end translate()

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
	 * Append the Toewijzing (303) body fields.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $body The `<body>` element to append into.
	 * @param array $caseObject The OR case object fields (already validated present).
	 *
	 * @return void
	 */
	private function appendToewijzingBody(DOMDocument $document, DOMElement $body, array $caseObject): void {
		$this->appendText(document: $document, parent: $body, name: 'bsn', value: (string)$caseObject['bsn']);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'productcode',
			value: (string)$caseObject['productcode']
		);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'ingangsdatum',
			value: (string)$caseObject['ingangsdatum']
		);

		if (empty($caseObject['einddatum']) === false) {
			$this->appendText(
				document: $document,
				parent: $body,
				name: 'einddatum',
				value: (string)$caseObject['einddatum']
			);
		}

		$this->appendText(document: $document, parent: $body, name: 'omvang', value: (string)$caseObject['omvang']);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'leveringsvorm',
			value: (string)$caseObject['leveringsvorm']
		);

	}//end appendToewijzingBody()

	/**
	 * Append the Declaratie (321) body fields.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $body The `<body>` element to append into.
	 * @param array $caseObject The OR case object fields (already validated present).
	 *
	 * @return void
	 */
	private function appendDeclaratieBody(DOMDocument $document, DOMElement $body, array $caseObject): void {
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'toewijzingReferentie',
			value: (string)$caseObject['toewijzingReferentie']
		);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'productcode',
			value: (string)$caseObject['productcode']
		);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'factuurnummer',
			value: (string)$caseObject['factuurnummer']
		);
		$this->appendText(document: $document, parent: $body, name: 'bedrag', value: (string)$caseObject['bedrag']);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'periodeStart',
			value: (string)$caseObject['periodeStart']
		);
		$this->appendText(
			document: $document,
			parent: $body,
			name: 'periodeEind',
			value: (string)$caseObject['periodeEind']
		);

	}//end appendDeclaratieBody()

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
