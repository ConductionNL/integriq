<?php

/**
 * Integriq StUF-ZKN Outbound Kennisgeving Translator.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * {@see \OCA\Integriq\Service\Stuf\StufLiteralLeakGuard} for leftover
 * `{{`/`}}`/`%%UNRESOLVED%%` markers and rejected if any survive (mirrors
 * `IwmoIjw\OutboundMessageTranslator`).
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/stuf-zkn-bridge/spec.md#requirement-outbound-zaklk01-kennisgeving-translation-with-a-literal-leak-guard-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use DateTime;
use DOMDocument;
use DOMElement;
use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\Stuf\StufLiteralLeakGuard;

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
	 * @param string $ontvangerOrg The subscribed StUF consumer's organisatie code
	 *                             (`stuurgegevens.ontvanger`).
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
		string $ontvangerOrg,
	): array {
		$kennisgeving = new CaseKennisgeving(case: $case, processingKind: $processingKind);
		$kennisgeving->zender = $zenderOrganisation;
		$kennisgeving->ontvanger = $ontvangerOrg;

		return $this->render(kennisgeving: $kennisgeving);
	}//end translate()

	/**
	 * Validate, build and scan one kennisgeving envelope.
	 *
	 * @param CaseKennisgeving $kennisgeving The zaak, verwerkingssoort and organisations.
	 *
	 * @return array{referentienummer: string, xml: string} The generated reference and the envelope XML.
	 *
	 * @throws StufZknTranslationException When the verwerkingssoort or a required field is unusable,
	 *                                     or the envelope still carries a template marker.
	 */
	private function render(CaseKennisgeving $kennisgeving): array {
		$this->assertProcessingKind(processingKind: $kennisgeving->processingKind);
		$this->assertRequiredFieldsPresent(case: $kennisgeving->case);

		$referenceNumber = 'ZKN-' . bin2hex(random_bytes(8));

		$zakLk01 = $this->zakLk01Element();
		$this->appendStuurgegevens(parent: $zakLk01, header: $this->header(kennisgeving: $kennisgeving, referenceNumber: $referenceNumber));
		$this->appendParameters(zakLk01: $zakLk01, processingKind: $kennisgeving->processingKind);
		$this->appendCaseObject(zakLk01: $zakLk01, kennisgeving: $kennisgeving);

		return ['referentienummer' => $referenceNumber, 'xml' => $this->scannedXml(document: $zakLk01->ownerDocument)];
	}//end render()

	/**
	 * Refuse a verwerkingssoort this translator cannot emit.
	 *
	 * @param string $processingKind The verwerkingssoort.
	 *
	 * @return void
	 *
	 * @throws StufZknTranslationException When it is not T, W or V.
	 */
	private function assertProcessingKind(string $processingKind): void {
		if (in_array($processingKind, self::VERWERKINGSSOORTEN, true) === false) {
			throw new StufZknTranslationException(
				message: 'Unsupported outbound verwerkingssoort "' . $processingKind . '" (expected T, W, or V).'
			);
		}

	}//end assertProcessingKind()

	/**
	 * A new SOAP envelope document with its body and an empty `zakLk01`.
	 *
	 * @return DOMElement The `zakLk01` element; its owner document is the envelope.
	 */
	private function zakLk01Element(): DOMElement {
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

		return $zakLk01;
	}//end zakLk01Element()

	/**
	 * The `Lk01` stuurgegevens of a kennisgeving.
	 *
	 * @param CaseKennisgeving $kennisgeving The organisations.
	 * @param string $referenceNumber The generated referentienummer.
	 *
	 * @return Stuurgegevens The header.
	 */
	private function header(CaseKennisgeving $kennisgeving, string $referenceNumber): Stuurgegevens {
		return new Stuurgegevens(
			berichtcode: 'Lk01',
			referentienummer: $referenceNumber,
			zender: ['organisatie' => $kennisgeving->zender],
			ontvanger: ['organisatie' => $kennisgeving->ontvanger],
			tail: ['entiteittype' => 'ZAK']
		);
	}//end header()

	/**
	 * Append the `parameters` block: the mutatiesoort and `indicatorOvername`.
	 *
	 * @param DOMElement $zakLk01 The `zakLk01` element.
	 * @param string $processingKind The verwerkingssoort.
	 *
	 * @return void
	 */
	private function appendParameters(DOMElement $zakLk01, string $processingKind): void {
		$parameters = new StufChildWriter(parent: (new StufChildWriter(parent: $zakLk01))->zknChild(name: 'parameters'));
		$parameters->stufText(name: 'mutatiesoort', value: $processingKind);
		$parameters->stufText(name: 'indicatorOvername', value: 'V');
	}//end appendParameters()

	/**
	 * Append the ZAK `object` with its entiteittype, verwerkingssoort and fields.
	 *
	 * @param DOMElement $zakLk01 The `zakLk01` element.
	 * @param CaseKennisgeving $kennisgeving The zaak and verwerkingssoort.
	 *
	 * @return void
	 */
	private function appendCaseObject(DOMElement $zakLk01, CaseKennisgeving $kennisgeving): void {
		$object = $zakLk01->ownerDocument->createElementNS(StufZknNamespaces::ZKN, 'zkn:object');
		$object->setAttributeNS(StufZknNamespaces::STUF, 'StUF:entiteittype', 'ZAK');
		$object->setAttributeNS(StufZknNamespaces::STUF, 'StUF:verwerkingssoort', $kennisgeving->processingKind);
		$zakLk01->appendChild($object);
		$this->appendCaseObjectFields(object: $object, case: $kennisgeving->case);
	}//end appendCaseObject()

	/**
	 * The rendered envelope, refused when a template marker survived.
	 *
	 * @param DOMDocument $document The envelope.
	 *
	 * @return string The XML.
	 *
	 * @throws StufZknTranslationException When an unresolved marker is left.
	 */
	private function scannedXml(DOMDocument $document): string {
		$xml = (string)$document->saveXML();
		if ($this->leakGuard->hasUnresolvedPlaceholder(xml: $xml) === true) {
			throw new StufZknTranslationException(
				message: 'Rendered kennisgeving still contains an unresolved template marker — refusing to send.'
			);
		}

		return $xml;
	}//end scannedXml()

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
	 * @param DOMElement    $parent The `zakLk01` element to append into.
	 * @param Stuurgegevens $header What the block carries.
	 *
	 * @return void
	 */
	private function appendStuurgegevens(DOMElement $parent, Stuurgegevens $header): void {
		$stuurgegevens = (new StufChildWriter(parent: $parent))->zknChild(name: 'stuurgegevens');
		$fields = new StufChildWriter(parent: $stuurgegevens);

		$fields->stufText(name: 'berichtcode', value: $header->berichtcode);
		$this->appendOrganisations(stuurgegevens: $stuurgegevens, header: $header);

		$fields->stufText(name: 'referentienummer', value: $header->referentienummer);
		$fields->stufText(name: 'tijdstipBericht', value: (new DateTime())->format('YmdHis'));
		foreach ($header->tail as $name => $value) {
			$fields->stufText(name: $name, value: $value);
		}
	}//end appendStuurgegevens()

	/**
	 * Append zender and ontvanger, each with its organisatie code, empty or not.
	 *
	 * @param DOMElement    $stuurgegevens The stuurgegevens element.
	 * @param Stuurgegevens $header        What the block carries.
	 *
	 * @return void
	 */
	private function appendOrganisations(DOMElement $stuurgegevens, Stuurgegevens $header): void {
		$document = $stuurgegevens->ownerDocument;
		foreach (['zender' => $header->zender, 'ontvanger' => $header->ontvanger] as $role => $system) {
			$element = $document->createElementNS(StufZknNamespaces::STUF, 'StUF:' . $role);
			$stuurgegevens->appendChild($element);
			(new StufChildWriter(parent: $element))->stufText(name: 'organisatie', value: (string)$system['organisatie']);
		}
	}//end appendOrganisations()

	/**
	 * Append the ZAK object's domain fields.
	 *
	 * @param DOMElement $object The `object` element to append into.
	 * @param array $case The OR/ZGW zaak object fields (already validated present).
	 *
	 * @return void
	 */
	private function appendCaseObjectFields(DOMElement $object, array $case): void {
		$fields = new StufChildWriter(parent: $object);
		$fields->zknText(name: 'identificatie', value: (string)$case['identificatie']);
		$fields->zknText(name: 'omschrijving', value: (string)$case['omschrijving']);
		$this->appendExplanation(fields: $fields, case: $case);
		$this->appendCaseType(fields: $fields, case: $case);
		$fields->zknText(name: 'registratiedatum', value: (string)$case['registratiedatum']);
		$fields->zknText(name: 'startdatum', value: (string)$case['startdatum']);

		foreach (['einddatum', 'status'] as $name) {
			if (empty($case[$name]) === false) {
				$fields->zknText(name: $name, value: (string)$case[$name]);
			}
		}

	}//end appendCaseObjectFields()

	/**
	 * Append the `toelichting`: its text, or an explicit nil when it is empty.
	 *
	 * @param StufChildWriter $fields The object's writer.
	 * @param array $case The zaak fields.
	 *
	 * @return void
	 */
	private function appendExplanation(StufChildWriter $fields, array $case): void {
		if (empty($case['toelichting']) === true) {
			$fields->zknNil(name: 'toelichting');
			return;
		}

		$fields->zknText(name: 'toelichting', value: (string)$case['toelichting']);

	}//end appendExplanation()

	/**
	 * Append the `zaaktype` with its code and, when set, its omschrijving.
	 *
	 * @param StufChildWriter $fields The object's writer.
	 * @param array $case The zaak fields.
	 *
	 * @return void
	 */
	private function appendCaseType(StufChildWriter $fields, array $case): void {
		$caseType = new StufChildWriter(parent: $fields->zknChild(name: 'zaaktype'));
		$caseType->zknText(name: 'code', value: (string)$case['zaaktypeCode']);
		if (empty($case['zaaktypeOmschrijving']) === false) {
			$caseType->zknText(name: 'omschrijving', value: (string)$case['zaaktypeOmschrijving']);
		}

	}//end appendCaseType()
}//end class
