<?php

/**
 * Integriq OSO Export Envelope Translator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Oso;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\Stuf\EnvelopeElement;
use OCA\Integriq\Service\Stuf\ExportEnvelope;
use OCA\Integriq\Service\Stuf\RequiredPayloadFields;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

/**
 * Export payload -> OSO XML overstapdossier envelope.
 *
 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-002-export-envelope-translation-with-a-literal-leak-guard
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
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-a-complete-export-payload-translates-to-a-valid-envelope
	 */
	public function translate(string $kenmerk, array $payload): string {
		if (trim($kenmerk) === '') {
			throw new OsoTranslationException(message: 'An OSO export requires a non-empty kenmerk (correlation id).');
		}

		$this->assertRequiredFieldsPresent(payload: $payload);

		$categories = $this->requireCategories(payload: $payload);

		$envelope = new ExportEnvelope(rootName: 'OsoOverstapdossier', kenmerk: $kenmerk);
		$envelope->stampMessageTime();
		$body = $envelope->addBody(fields: self::REQUIRED_FIELDS, payload: $payload);
		$this->appendCategories(body: $body, categories: $categories);
		$this->appendAttachmentRefs(body: $body, payload: $payload);

		$xml = $envelope->toXml();
		$this->assertNoUnresolvedPlaceholder(xml: $xml);

		return $xml;
	}//end translate()

	/**
	 * The payload's `categories`, refused when missing, not a list or empty.
	 *
	 * @param array $payload The export payload.
	 *
	 * @return array The categories.
	 *
	 * @throws OsoTranslationException When `categories` is missing, not an array or empty.
	 */
	private function requireCategories(array $payload): array {
		$categories = ($payload['categories'] ?? []);
		if (is_array($categories) === false || $categories === []) {
			throw new OsoTranslationException(
				message: 'Required field "categories" is missing or empty — an OSO export needs at least one category.'
			);
		}

		return $categories;

	}//end requireCategories()

	/**
	 * Append the `categories` element with one `category` per entry.
	 *
	 * @param EnvelopeElement $body       The envelope body.
	 * @param array           $categories The validated categories.
	 *
	 * @return void
	 */
	private function appendCategories(EnvelopeElement $body, array $categories): void {
		$categoriesElement = $body->addChild(name: 'categories');
		foreach ($categories as $category) {
			$this->appendCategory(categoriesElement: $categoriesElement, category: $category);
		}

	}//end appendCategories()

	/**
	 * Append one `category`: its name, whether it is included, and its data as JSON.
	 *
	 * @param EnvelopeElement $categoriesElement The `categories` element.
	 * @param mixed           $category          One category entry (normally an array; anything else renders as an empty category).
	 *
	 * @return void
	 */
	private function appendCategory(EnvelopeElement $categoriesElement, mixed $category): void {
		$categoryElement = $categoriesElement->addChild(name: 'category');
		$categoryElement->addText(name: 'name', value: (string)($category['category'] ?? ''));
		$included = 'false';
		if (($category['included'] ?? false) === true) {
			$included = 'true';
		}

		$categoryElement->addText(name: 'included', value: $included);
		$categoryElement->addText(name: 'data', value: (string)json_encode($category['data'] ?? []));

	}//end appendCategory()

	/**
	 * Append the `attachmentRefs` element when the payload carries any.
	 *
	 * @param EnvelopeElement $body    The envelope body.
	 * @param array           $payload The export payload.
	 *
	 * @return void
	 */
	private function appendAttachmentRefs(EnvelopeElement $body, array $payload): void {
		if (empty($payload['attachmentRefs']) === true) {
			return;
		}

		$attachmentsElement = $body->addChild(name: 'attachmentRefs');
		foreach ((array)$payload['attachmentRefs'] as $ref) {
			$attachmentsElement->addText(name: 'ref', value: (string)$ref);
		}

	}//end appendAttachmentRefs()

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
	 * @spec openspec/specs/oso-adapter/spec.md#scenario-a-missing-required-field-never-reaches-the-envelope
	 */
	private function assertRequiredFieldsPresent(array $payload): void {
		$missing = RequiredPayloadFields::firstMissing(fields: self::REQUIRED_FIELDS, payload: $payload);
		if ($missing !== null) {
			throw new OsoTranslationException(
				message: 'Required field "' . $missing . '" is missing or empty for an OSO export — refusing '
					. 'to build an envelope with unresolved data.'
			);
		}

	}//end assertRequiredFieldsPresent()

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
