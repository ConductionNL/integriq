<?php

/**
 * Integriq OSO Export Envelope Translator.
 *
 * Translates an export payload (`learnerEckId`, `targetSchoolBrin`,
 * `categories[]`, `attachmentRefs[]`) — supplied by learniq's
 * `DataExchangeRunHandler` for the `oso` job type, already cleared by
 * `OsoDossierReviewGuard`'s parent-review lifecycle and
 * `DataExchangeRunGuard::GATED_TARGETS` before the job reaches this
 * adapter — into an OSO-style XML envelope. See design.md "Trade-offs" —
 * the exact OSO overstapdossier XSD was not in the corpus; the shape
 * follows the same Edukoppeling/StUF convention as the other two adapters
 * in this lane, isolated behind this one class.
 *
 * DATA MINIMISATION IS A PASS-THROUGH (REQ-006): every entry in
 * `categories[]` is transmitted exactly as received, `included: false`
 * marked as excluded rather than omitted. This translator MUST NEVER
 * decide which categories are sent — that decision is learniq's.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * OsoTranslationException BEFORE any XML is built.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Oso
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Export payload -> OSO XML overstapdossier envelope.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
 */
class OsoExportEnvelopeTranslator {

	/**
	 * Required fields for every export payload.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FIELDS = ['learnerEckId', 'targetSchoolBrin'];

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
	 * Translate an export payload into an OSO envelope.
	 *
	 * @param string $kenmerk The caller-supplied correlation id.
	 * @param array $payload The field payload — `learnerEckId`, `targetSchoolBrin`, `categories[]`
	 *                       (each `{category, included, data}`), optional `attachmentRefs[]`.
	 *
	 * @return string The fully rendered envelope XML.
	 *
	 * @throws OsoTranslationException When a required field is missing/empty, `categories` is empty,
	 *                                 or the rendered envelope still carries an unresolved template marker.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-a-complete-export-payload-translates-to-a-valid-envelope
	 */
	public function translate(string $kenmerk, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new OsoTranslationException(message: 'An OSO export requires a non-empty kenmerk (correlation id).');
		}

		$this->assertRequiredFieldsPresent(payload: $payload);

		$categories = ($payload['categories'] ?? []);
		if (is_array($categories) === false || $categories === []) {
			throw new OsoTranslationException(
				message: 'Required field "categories" is missing or empty — an OSO export needs at least one category.'
			);
		}

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$root = $document->createElement('OsoOverstapdossier');
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

		$categoriesElement = $document->createElement('categories');
		$body->appendChild($categoriesElement);
		foreach ($categories as $category) {
			$categoryElement = $document->createElement('category');
			$categoriesElement->appendChild($categoryElement);
			$this->appendText(
				document: $document,
				parent: $categoryElement,
				name: 'name',
				value: (string)($category['category'] ?? '')
			);
			$included = 'false';
			if (($category['included'] ?? false) === true) {
				$included = 'true';
			}

			$this->appendText(document: $document, parent: $categoryElement, name: 'included', value: $included);
			$this->appendText(
				document: $document,
				parent: $categoryElement,
				name: 'data',
				value: (string)json_encode($category['data'] ?? [])
			);
		}

		if (empty($payload['attachmentRefs']) === false) {
			$attachmentsElement = $document->createElement('attachmentRefs');
			$body->appendChild($attachmentsElement);
			foreach ((array)$payload['attachmentRefs'] as $ref) {
				$this->appendText(document: $document, parent: $attachmentsElement, name: 'ref', value: (string)$ref);
			}
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
	 * @throws OsoTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload): void {
		foreach (self::REQUIRED_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new OsoTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for an OSO export — refusing '
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
	 * @throws OsoTranslationException When any marker survives.
	 */
	private function assertNoUnresolvedPlaceholder(string $xml): void {
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new OsoTranslationException(
				message: 'Rendered envelope still contains an unresolved template marker — refusing to send.'
			);
		}

	}//end assertNoUnresolvedPlaceholder()
}//end class
