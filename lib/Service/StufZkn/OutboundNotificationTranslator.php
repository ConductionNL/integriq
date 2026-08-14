<?php

/**
 * OpenConnector StUF-ZKN Outbound Kennisgeving Translator.
 *
 * Translates an OR/ZGW zaak object (a create, update, or status change) into
 * a `zakLk01` StUF-ZKN 3.10 SOAP kennisgeving XML envelope, so a subscribed
 * legacy StUF consumer (midoffice, DMS, belastingen, KCC) stays in sync
 * without the municipality having to migrate off StUF first. See
 * design.md "StUF element/attribute assumptions" — NO live municipal
 * StUF-ZKN endpoint was available in this environment to verify the exact
 * wire shape against.
 *
 * LITERAL-LEAK GUARD: a missing/empty required field raises
 * {@see StufZknTranslationException} BEFORE any XML is built — this
 * translator MUST NEVER emit an empty tag, a null literal, or an unresolved
 * template marker for a required field. As defense in depth, the fully
 * rendered envelope is scanned via the shared
 * {@see \OCA\OpenConnector\Service\Stuf\StufLiteralLeakGuard} for leftover
 * `{{`/`}}`/`%%UNRESOLVED%%` markers and rejected if any survive (mirrors
 * `IwmoIjw\OutboundMessageTranslator`).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\StufZkn
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
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\StufZkn;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\OpenConnector\Exception\StufZknTranslationException;
use OCA\OpenConnector\Service\Stuf\StufLiteralLeakGuard;

/**
 * OR/ZGW zaak object -> `zakLk01` kennisgeving XML envelope.
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */
class OutboundNotificationTranslator {

	/**
	 * Required zaak fields — see design.md's outbound field table.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_FIELDS = [
		'identificatie',
		'omschrijving',
		'zaaktypeCode',
		'registratiedatum',
		'startdatum',
	];

	/**
	 * Recognised verwerkingssoort codes (StUF 3.01 core) this translator can emit.
	 *
	 * @var array<int, string>
	 */
	private const VERWERKINGSSOORTEN = ['T', 'W', 'V'];

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
	 * Translate an OR/ZGW zaak object + change kind into a `zakLk01` kennisgeving.
	 *
	 * @param array $case The OR/ZGW zaak object fields — see design.md's outbound
	 *                    field table for the full assumed vocabulary.
	 * @param string $processingKind `T` (create), `W` (update/status change), or `V` (vervallen).
	 * @param string $zenderOrganisation This bridge's own organisatie code (`stuurgegevens.zender`).
	 * @param string $ontvangerOrganisation The subscribed StUF consumer's organisatie code
	 *                                     (`stuurgegevens.ontvanger`).
	 *
	 * @return array{referentienummer: string, xml: string} The generated correlation reference and
	 *                                                      the rendered envelope XML.
	 *
	 * @throws StufZknTranslationException When a required field is missing/empty, `verwerkingssoort`
	 *                                     is unsupported, or the rendered envelope still carries an
	 *                                     unresolved template marker.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-complete-zaak-create-translates-to-a-valid-zaklk01-toevoeging
	 */
	public function translate(
		array $case,
		string $processingKind,
		string $zenderOrganisation,
		string $ontvangerOrganisation,
	): array {
		if (in_array($processingKind, self::VERWERKINGSSOORTEN, true) === false) {
			throw new StufZknTranslationException(
				message: 'Unsupported outbound verwerkingssoort "' . $processingKind . '" (expected T, W, or V).'
			);
		}

		$this->assertRequiredFieldsPresent(case: $case);

		$referenceNumber = 'ZKN-' . bin2hex(random_bytes(8));

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$envelope = $document->createElementNS(StufZknNamespaces::SOAP, 'soap:Envelope');
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:StUF', StufZknNamespaces::STUF);
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:zkn', StufZknNamespaces::ZKN);
		$envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', StufZknNamespaces::XSI);
		$document->appendChild($envelope);

		$body = $document->createElementNS(StufZknNamespaces::SOAP, 'soap:Body');
		$envelope->appendChild($body);

		$zakLk01 = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:zakLk01');
		$body->appendChild($zakLk01);

		$this->appendStuurgegevens(
			document: $document,
			parent: $zakLk01,
			referenceNumber: $referenceNumber,
			zenderOrganisation: $zenderOrganisation,
			ontvangerOrganisation: $ontvangerOrganisation
		);

		$parameters = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:parameters');
		$zakLk01->appendChild($parameters);
		$this->appendStufText(document: $document, parent: $parameters, name: 'mutatiesoort', value: $processingKind);
		$this->appendStufText(document: $document, parent: $parameters, name: 'indicatorOvername', value: 'V');

		$object = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:object');
		$object->setAttributeNS(StufZknNamespaces::STUF, 'StUF:entiteittype', 'ZAK');
		$object->setAttributeNS(StufZknNamespaces::STUF, 'StUF:verwerkingssoort', $processingKind);
		$zakLk01->appendChild($object);
		$this->appendCaseObjectFields(document: $document, object: $object, case: $case);

		$xml = (string)$document->saveXML();
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new StufZknTranslationException(
				message: 'Rendered kennisgeving still contains an unresolved template marker — refusing to send.'
			);
		}

		return ['referentienummer' => $referenceNumber, 'xml' => $xml];
	}//end translate()

	/**
	 * Assert every required zaak field is present and non-empty — the literal-leak guard's first
	 * line of defence.
	 *
	 * @param array $case The OR/ZGW zaak object fields.
	 *
	 * @return void
	 *
	 * @throws StufZknTranslationException Naming the first missing/empty required field found.
	 *
	 * @spec openspec/specs/stuf-zkn-bridge/spec.md#scenario-a-missing-required-field-never-reaches-the-xml-literal-leak-guard
	 */
	private function assertRequiredFieldsPresent(array $case): void {
		foreach (self::REQUIRED_FIELDS as $field) {
			$value = ($case[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				throw new StufZknTranslationException(
					message: 'Required field "' . $field . '" is missing or empty for an outbound zaak kennisgeving — '
						. 'refusing to build an envelope with unresolved data.'
				);
			}
		}

	}//end assertRequiredFieldsPresent()

	/**
	 * Append the `stuurgegevens` block.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The `zakLk01` element to append into.
	 * @param string $referenceNumber The generated correlation reference.
	 * @param string $zenderOrganisation This bridge's own organisatie code.
	 * @param string $ontvangerOrganisation The subscribed consumer's organisatie code.
	 *
	 * @return void
	 */
	private function appendStuurgegevens(
		DOMDocument $document,
		DOMElement $parent,
		string $referenceNumber,
		string $zenderOrganisation,
		string $ontvangerOrganisation,
	): void {
		$stuurgegevens = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:stuurgegevens');
		$parent->appendChild($stuurgegevens);

		$this->appendStufText(document: $document, parent: $stuurgegevens, name: 'berichtcode', value: 'Lk01');

		$zender = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:zender');
		$stuurgegevens->appendChild($zender);
		$this->appendStufText(document: $document, parent: $zender, name: 'organisatie', value: $zenderOrganisation);

		$ontvanger = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:ontvanger');
		$stuurgegevens->appendChild($ontvanger);
		$this->appendStufText(document: $document, parent: $ontvanger, name: 'organisatie', value: $ontvangerOrganisation);

		$this->appendStufText(
			document: $document,
			parent: $stuurgegevens,
			name: 'referentienummer',
			value: $referenceNumber
		);
		$this->appendStufText(
			document: $document,
			parent: $stuurgegevens,
			name: 'tijdstipBericht',
			value: (new DateTime())->format('YmdHis')
		);
		$this->appendStufText(document: $document, parent: $stuurgegevens, name: 'entiteittype', value: 'ZAK');

	}//end appendStuurgegevens()

	/**
	 * Append the ZAK object's domain fields.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $object The `object` element to append into.
	 * @param array $case The OR/ZGW zaak object fields (already validated present).
	 *
	 * @return void
	 */
	private function appendCaseObjectFields(DOMDocument $document, DOMElement $object, array $case): void {
		$this->appendZknText(document: $document, parent: $object, name: 'identificatie', value: (string)$case['identificatie']);
		$this->appendZknText(document: $document, parent: $object, name: 'omschrijving', value: (string)$case['omschrijving']);

		if (empty($case['toelichting']) === true) {
			$this->appendNilField(document: $document, parent: $object, name: 'toelichting');
		}

		if (empty($case['toelichting']) === false) {
			$this->appendZknText(document: $document, parent: $object, name: 'toelichting', value: (string)$case['toelichting']);
		}

		$caseType = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:zaaktype');
		$object->appendChild($caseType);
		$this->appendZknText(document: $document, parent: $caseType, name: 'code', value: (string)$case['zaaktypeCode']);
		if (empty($case['zaaktypeOmschrijving']) === false) {
			$this->appendZknText(
				document: $document,
				parent: $caseType,
				name: 'omschrijving',
				value: (string)$case['zaaktypeOmschrijving']
			);
		}

		$this->appendZknText(
			document: $document,
			parent: $object,
			name: 'registratiedatum',
			value: (string)$case['registratiedatum']
		);
		$this->appendZknText(document: $document, parent: $object, name: 'startdatum', value: (string)$case['startdatum']);

		if (empty($case['einddatum']) === false) {
			$this->appendZknText(document: $document, parent: $object, name: 'einddatum', value: (string)$case['einddatum']);
		}

		if (empty($case['status']) === false) {
			$this->appendZknText(document: $document, parent: $object, name: 'status', value: (string)$case['status']);
		}

	}//end appendZaakObjectFields()

	/**
	 * Append a zkn-namespaced text-valued child element.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The child element local name.
	 * @param string $value The text value.
	 *
	 * @return void
	 */
	private function appendZknText(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		$parent->appendChild(
			$document->createElementNS(StufZknNamespaces::ZKN, 'zkn:' . $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES))
		);

	}//end appendZknText()

	/**
	 * Append a StUF-namespaced text-valued child element.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The child element local name.
	 * @param string $value The text value.
	 *
	 * @return void
	 */
	private function appendStufText(DOMDocument $document, DOMElement $parent, string $name, string $value): void {
		$parent->appendChild(
			$document->createElementNS(StufZknNamespaces::STUF, 'StUF:' . $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES))
		);

	}//end appendStufText()

	/**
	 * Append an explicitly-nil zkn-namespaced field per StUF's `noValue`/`xsi:nil` convention.
	 *
	 * @param DOMDocument $document The owning document.
	 * @param DOMElement $parent The parent element.
	 * @param string $name The child element local name.
	 *
	 * @return void
	 */
	private function appendNilField(DOMDocument $document, DOMElement $parent, string $name): void {
		$field = $document->createElementNS(StufZknNamespaces::ZKN, 'zkn:' . $name);
		$field->setAttributeNS(StufZknNamespaces::STUF, 'StUF:noValue', 'geenWaarde');
		$field->setAttributeNS(StufZknNamespaces::XSI, 'xsi:nil', 'true');
		$parent->appendChild($field);

	}//end appendNilField()
}//end class
