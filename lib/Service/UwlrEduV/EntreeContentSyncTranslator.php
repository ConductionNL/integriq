<?php

/**
 * Integriq Entree Content Sync Translator.
 *
 * Translates an `entree-content` sync payload (`eckId`, `schoolBrin`,
 * `ssoAudience`) — VO content-access SSO hand-off to a third-party
 * method/publisher, per `uwlr-eduv-basispoort-contract`'s
 * `direction: sync` seed — into an Entree-content-style XML envelope.
 * Kept structurally distinct from `entree-surfconext-sso-contract`'s own
 * login-federation concern (`user_saml`/`user_oidc`): this translator
 * never touches learniq's own authentication boundary, only a hand-off to
 * a third-party site.
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
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-005-entree-content-sso-hand-off-translation
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\UwlrEduV;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Entree content sync payload -> Entree content XML envelope.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-005-entree-content-sso-hand-off-translation
 */
class EntreeContentSyncTranslator {

	/**
	 * Required fields for every Entree content sync payload.
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
	 * Translate an Entree content sync payload into a wire envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `eckId`, `schoolBrin`, `ssoAudience`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws UwlrEduVTranslationException When a required field is missing/empty, or the
	 *                                      rendered envelope still carries an unresolved template marker.
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-entree-content-payload-carries-the-sso-audience
	 */
	public function translate(string $kenmerk, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new UwlrEduVTranslationException(message: 'An Entree content sync requires a non-empty kenmerk (correlation id).');
		}

		$this->assertRequiredFieldsPresent(payload: $payload);

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('EntreeContentSync');
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
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-missing-schoolbrin-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload): void {
		foreach (self::REQUIRED_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new UwlrEduVTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for an Entree content sync — '
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
