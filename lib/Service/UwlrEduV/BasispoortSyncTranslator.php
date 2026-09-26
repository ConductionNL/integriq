<?php

/**
 * Integriq Basispoort Sync Translator.
 *
 * Translates a `basispoort` sync payload (`eckId`, `schoolBrin`,
 * `ssoAudience`) — PO pupil/group/staff export plus an SSO hand-off token
 * for method/publisher content, per `uwlr-eduv-basispoort-contract`'s
 * `direction: sync` seed — into a Basispoort-style XML envelope.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * UwlrEduVTranslationException BEFORE any XML is built.
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Basispoort sync payload -> Basispoort XML envelope.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */
class BasispoortSyncTranslator {

	/**
	 * Required fields for every Basispoort sync payload.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FIELDS = ['eckId', 'schoolBrin', 'ssoAudience'];

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
	 * Translate a Basispoort sync payload into a wire envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty, or the
	 *                                      rendered envelope still carries an unresolved template marker.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-basispoort-sync-payload-carries-the-sso-audience
	 */
	public function translate(string $kenmerk, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new UwlrEduVTranslationException(message: 'A Basispoort sync requires a non-empty kenmerk (correlation id).');
		}

		$this->assertRequiredFieldsPresent(payload: $payload);

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('BasispoortSync');
		$document->appendChild($root);

		$stuurgegevens = $document->createElement('stuurgegevens');
		$root->appendChild($stuurgegevens);
		$this->appendText(document: $document, parent: $stuurgegevens, name: 'kenmerk', value: $kenmerk);
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
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-missing-ssoaudience-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload): void {
		foreach (self::REQUIRED_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new UwlrEduVTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for a Basispoort sync — '
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
