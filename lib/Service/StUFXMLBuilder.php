<?php

/**
 * StUF XML Builder Service.
 *
 * Builds StUF-BG 3.10 and StUF-ZKN 3.10 compliant SOAP XML messages for both
 * inbound responses (npsLa01, adrLa01, zakLa01, Bv03) and outbound queries (npsLv01).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DOMDocument;
use DOMElement;
use Symfony\Component\Uid\Uuid;

/**
 * Builds StUF-BG 3.10 and StUF-ZKN 3.10 XML responses.
 *
 * Handles proper namespace declarations, stuurgegevens population,
 * noValue attribute semantics, and Fo01/Fo03 fault messages.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-4
 */
class StUFXMLBuilder {

	/**
	 * SOAP 1.1 envelope namespace.
	 *
	 * @var string
	 */
	public const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';

	/**
	 * StUF base namespace (StUF 3.01).
	 *
	 * @var string
	 */
	public const NS_STUF = 'http://www.egem.nl/StUF/StUF0301';

	/**
	 * StUF-BG 3.10 namespace.
	 *
	 * @var string
	 */
	public const NS_BG = 'http://www.egem.nl/StUF/sector/bg/0310';

	/**
	 * StUF-ZKN 3.10 namespace.
	 *
	 * @var string
	 */
	public const NS_ZKN = 'http://www.egem.nl/StUF/sector/zkn/0310';

	/**
	 * StUFXMLBuilder constructor.
	 */
	public function __construct() {

	}//end __construct()

	/**
	 * Create a new DOMDocument with SOAP envelope containing the given body element.
	 *
	 * @param DOMDocument $doc The DOMDocument to use.
	 * @param DOMElement $bodyContent The element to place inside SOAP body.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-4
	 */
	private function wrapInEnvelope(DOMDocument $doc, DOMElement $bodyContent): void {
		$envelope = $doc->createElementNS(self::NS_SOAP, 'SOAP-ENV:Envelope');
		$envelope->setAttribute('xmlns:StUF', self::NS_STUF);
		$envelope->setAttribute('xmlns:BG', self::NS_BG);
		$envelope->setAttribute('xmlns:ZKN', self::NS_ZKN);

		$body = $doc->createElementNS(self::NS_SOAP, 'SOAP-ENV:Body');
		$body->appendChild($bodyContent);
		$envelope->appendChild($body);
		$doc->appendChild($envelope);

	}//end wrapInEnvelope()

	/**
	 * Build a stuurgegevens element for StUF messages.
	 *
	 * Stuurgegevens include zender, ontvanger, referentienummer, tijdstipBericht
	 * and optionally crossRefnummer.
	 *
	 * @param DOMDocument $doc The DOMDocument to create elements in.
	 * @param string $berichtcode The StUF berichtcode (e.g. La01, Fo01, Bv03).
	 * @param array $stuurgegevens Stuurgegevens configuration values.
	 *
	 * @return DOMElement The populated stuurgegevens element.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function buildStuurgegevens(DOMDocument $doc, string $berichtcode, array $stuurgegevens): DOMElement {
		$sg = $doc->createElementNS(self::NS_STUF, 'StUF:stuurgegevens');
		$this->appendStufTexts(parent: $sg, texts: ['StUF:berichtcode' => $berichtcode]);

		// Deliberately still 'OpenConnector': StUF zender/applicatie is this app's
		// identity as the municipal zaaksysteem has it provisioned. Renaming it here
		// does not rename it there — messages are rejected until the municipality
		// re-provisions. Moves in a separate coordinated pass.
		$zender = $doc->createElementNS(self::NS_STUF, 'StUF:zender');
		$this->appendStufTexts(
			parent: $zender,
			texts: [
				'StUF:organisatie' => (string)($stuurgegevens['zenderOrganisatie'] ?? ''),
				'StUF:applicatie' => (string)($stuurgegevens['zenderApplicatie'] ?? 'OpenConnector'),
			]
		);
		$sg->appendChild($zender);

		$ontvanger = $doc->createElementNS(self::NS_STUF, 'StUF:ontvanger');
		$this->appendStufTexts(
			parent: $ontvanger,
			texts: [
				'StUF:organisatie' => (string)($stuurgegevens['ontvangerOrganisatie'] ?? ''),
				'StUF:applicatie' => (string)($stuurgegevens['ontvangerApplicatie'] ?? ''),
			]
		);
		$sg->appendChild($ontvanger);
		$this->appendStufTexts(
			parent: $sg,
			texts: ['StUF:referentienummer' => (string)Uuid::v4(), 'StUF:tijdstipBericht' => date(format: 'YmdHis') . '000']
		);

		if (isset($stuurgegevens['crossRefnummer']) === true) {
			$this->appendStufTexts(parent: $sg, texts: ['StUF:crossRefnummer' => (string)$stuurgegevens['crossRefnummer']]);
		}

		return $sg;
	}//end buildStuurgegevens()

	/**
	 * Build a BG:object element for a person in npsLa01 responses.
	 *
	 * @param DOMDocument $doc The DOMDocument to create elements in.
	 * @param array $fields StUF-BG field name to value map.
	 * @param array|null $scope List of requested field names (null = all fields).
	 *
	 * @return DOMElement The populated BG:object element.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function buildPersonObject(DOMDocument $doc, array $fields, ?array $scope): DOMElement {
		$obj = $doc->createElementNS(self::NS_BG, 'BG:object');
		$obj->setAttributeNS(self::NS_STUF, 'StUF:entiteittype', 'NPS');

		$fieldMap = [
			'inp.bsn' => 'inp.bsn',
			'geslachtsnaam' => 'geslachtsnaam',
			'voorvoegselGeslachtsnaam' => 'voorvoegselGeslachtsnaam',
			'voornamen' => 'voornamen',
			'geboortedatum' => 'geboortedatum',
			'geslachtsaanduiding' => 'geslachtsaanduiding',
		];

		foreach ($fieldMap as $key => $xmlName) {
			if ($scope !== null && in_array(needle: $key, haystack: $scope) === false) {
				continue;
			}

			$el = $doc->createElementNS(self::NS_BG, 'BG:' . $xmlName);
			if (isset($fields[$key]) === true) {
				$el->nodeValue = (string)$fields[$key];
			} else {
				$el->setAttributeNS(self::NS_STUF, 'StUF:noValue', 'geenWaarde');
			}

			$obj->appendChild($el);
		}

		if (isset($fields['verblijfsadres']) === true && is_array($fields['verblijfsadres']) === true) {
			if ($scope === null || in_array(needle: 'verblijfsadres', haystack: $scope) === true) {
				$obj->appendChild($this->buildVerblijfsadresElement(doc: $doc, adres: $fields['verblijfsadres']));
			}
		}

		return $obj;
	}//end buildPersonObject()

	/**
	 * Build a BG:verblijfsadres element.
	 *
	 * @param DOMDocument $doc The DOMDocument to create elements in.
	 * @param array $adres Address fields.
	 *
	 * @return DOMElement The populated verblijfsadres element.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function buildVerblijfsadresElement(DOMDocument $doc, array $adres): DOMElement {
		$adresEl = $doc->createElementNS(self::NS_BG, 'BG:verblijfsadres');

		$adresFields = [
			'gor.straatnaam' => 'gor.straatnaam',
			'aoa.huisnummer' => 'aoa.huisnummer',
			'aoa.postcode' => 'aoa.postcode',
			'wpl.woonplaatsNaam' => 'wpl.woonplaatsNaam',
		];

		foreach ($adresFields as $key => $xmlName) {
			if (isset($adres[$key]) === true) {
				$el = $doc->createElementNS(self::NS_BG, 'BG:' . $xmlName);
				$el->nodeValue = (string)$adres[$key];
				$adresEl->appendChild($el);
			}
		}

		return $adresEl;
	}//end buildVerblijfsadresElement()

	/**
	 * Build a BG:object element for an address in adrLa01 responses.
	 *
	 * @param DOMDocument $doc The DOMDocument to create elements in.
	 * @param array $fields StUF-BG address field name to value map.
	 *
	 * @return DOMElement The populated BG:object element.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function buildAddressObject(DOMDocument $doc, array $fields): DOMElement {
		$obj = $doc->createElementNS(self::NS_BG, 'BG:object');
		$obj->setAttributeNS(self::NS_STUF, 'StUF:entiteittype', 'ADR');

		$fieldMap = [
			'gor.straatnaam' => 'gor.straatnaam',
			'aoa.huisnummer' => 'aoa.huisnummer',
			'aoa.postcode' => 'aoa.postcode',
			'wpl.woonplaatsNaam' => 'wpl.woonplaatsNaam',
		];

		foreach ($fieldMap as $key => $xmlName) {
			if (isset($fields[$key]) === true) {
				$el = $doc->createElementNS(self::NS_BG, 'BG:' . $xmlName);
				$el->nodeValue = (string)$fields[$key];
				$obj->appendChild($el);
			}
		}

		return $obj;
	}//end buildAddressObject()

	/**
	 * Build npsLa01 (persoon antwoord) SOAP response XML.
	 *
	 * @param array $persons Array of StUF-mapped person field arrays.
	 * @param array $stuurgegevens Stuurgegevens configuration for the response.
	 * @param array|null $scope Requested field names (null = return all fields).
	 *
	 * @return string The complete SOAP XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildNpsLa01(array $persons, array $stuurgegevens, ?array $scope = null): string {
		$doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$doc->formatOutput = true;

		$response = $doc->createElementNS(self::NS_BG, 'BG:npsLa01');
		$response->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'La01', stuurgegevens: $stuurgegevens));

		$params = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
		$count = $doc->createElementNS(self::NS_STUF, 'StUF:aantalVoorkomens');
		$count->nodeValue = (string)count($persons);
		$params->appendChild($count);
		$response->appendChild($params);

		$answer = $doc->createElementNS(self::NS_BG, 'BG:antwoord');
		foreach ($persons as $person) {
			$answer->appendChild($this->buildPersonObject(doc: $doc, fields: $person, scope: $scope));
		}

		$response->appendChild($answer);
		$this->wrapInEnvelope(doc: $doc, bodyContent: $response);

		return (string)$doc->saveXML();
	}//end buildNpsLa01()

	/**
	 * Build adrLa01 (adres antwoord) SOAP response XML.
	 *
	 * @param array $addresses Array of StUF-BG address field arrays.
	 * @param array $stuurgegevens Stuurgegevens configuration.
	 *
	 * @return string The complete SOAP XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildAdrLa01(array $addresses, array $stuurgegevens): string {
		$doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$doc->formatOutput = true;

		$response = $doc->createElementNS(self::NS_BG, 'BG:adrLa01');
		$response->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'La01', stuurgegevens: $stuurgegevens));

		$params = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
		$count = $doc->createElementNS(self::NS_STUF, 'StUF:aantalVoorkomens');
		$count->nodeValue = (string)count($addresses);
		$params->appendChild($count);
		$response->appendChild($params);

		$answer = $doc->createElementNS(self::NS_BG, 'BG:antwoord');
		foreach ($addresses as $address) {
			$answer->appendChild($this->buildAddressObject(doc: $doc, fields: $address));
		}

		$response->appendChild($answer);
		$this->wrapInEnvelope(doc: $doc, bodyContent: $response);

		return (string)$doc->saveXML();
	}//end buildAdrLa01()

	/**
	 * Build Fo01 SOAP fault message for StUF-BG errors.
	 *
	 * @param string $code The StUF error code (e.g. StUF046).
	 * @param string $omschrijving Human-readable error description.
	 * @param array $stuurgegevens Stuurgegevens configuration.
	 * @param string $plek Where the fault lies: client (default) or server.
	 *
	 * @return string The complete SOAP fault XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildFo01(string $code, string $omschrijving, array $stuurgegevens, string $plek='client'): string {
		$fault = new StufFault(berichtcode: 'Fo01', code: $code);
		$fault->omschrijving = $omschrijving;
		$fault->plek = $plek;

		return $this->buildFault(fault: $fault, stuurgegevens: $stuurgegevens);
	}//end buildFo01()

	/**
	 * Build a Fo02 SOAP fault: the foutbericht that answers a vraagbericht (Lv01).
	 *
	 * @param string $code         The StUF error code (e.g. StUF055).
	 * @param string $omschrijving Human-readable error description.
	 * @param array  $stuurgegevens Stuurgegevens configuration.
	 * @param string $plek         Where the fault lies: client or server.
	 *
	 * @return string The complete SOAP fault XML string.
	 *
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-an-unanswered-query-is-a-fault-never-an-empty-answer
	 */
	public function buildFo02(string $code, string $omschrijving, array $stuurgegevens, string $plek='server'): string {
		$fault = new StufFault(berichtcode: 'Fo02', code: $code);
		$fault->omschrijving = $omschrijving;
		$fault->plek = $plek;

		return $this->buildFault(fault: $fault, stuurgegevens: $stuurgegevens);
	}//end buildFo02()

	/**
	 * Build a foutbericht inside a SOAP fault.
	 *
	 * A fault that names where it lies (Fo01, Fo02) reports `SOAP-ENV:Server`
	 * for `server` and carries a StUF:plek; one that does not (Fo03) is a
	 * client fault without it.
	 *
	 * @param StufFault $fault         The foutbericht.
	 * @param array     $stuurgegevens Stuurgegevens configuration.
	 *
	 * @return string The complete SOAP fault XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function buildFault(StufFault $fault, array $stuurgegevens): string {
		$doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$doc->formatOutput = true;

		$soapFault = $doc->createElementNS(self::NS_SOAP, 'SOAP-ENV:Fault');
		$faultCode = 'SOAP-ENV:Client';
		if ($fault->plek === 'server') {
			$faultCode = 'SOAP-ENV:Server';
		}

		$this->appendPlainTexts(parent: $soapFault, texts: ['faultcode' => $faultCode, 'faultstring' => $fault->omschrijving]);

		$detail = $doc->createElement('detail');
		$message = $doc->createElementNS(self::NS_STUF, 'StUF:' . $fault->berichtcode . 'Bericht');
		$message->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: $fault->berichtcode, stuurgegevens: $stuurgegevens));

		$body = $doc->createElementNS(self::NS_STUF, 'StUF:body');
		$this->appendStufTexts(parent: $body, texts: $this->faultBody(fault: $fault));
		$message->appendChild($body);
		$detail->appendChild($message);
		$soapFault->appendChild($detail);

		$this->wrapInEnvelope(doc: $doc, bodyContent: $soapFault);

		return (string)$doc->saveXML();
	}//end buildFault()

	/**
	 * The texts of a foutbericht's body, in order: code, plek when named, omschrijving.
	 *
	 * @param StufFault $fault The foutbericht.
	 *
	 * @return array<string, string> Text per qualified tag.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function faultBody(StufFault $fault): array {
		$texts = ['StUF:code' => $fault->code];
		if ($fault->plek !== null) {
			$texts['StUF:plek'] = $fault->plek;
		}

		$texts['StUF:omschrijving'] = $fault->omschrijving;

		return $texts;
	}//end faultBody()

	/**
	 * Build zakLa01 (zaak antwoord) SOAP response XML.
	 *
	 * @param array $cases Array of zaak field arrays.
	 * @param array $stuurgegevens Stuurgegevens configuration.
	 *
	 * @return string The complete SOAP XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildZakLa01(array $cases, array $stuurgegevens): string {
		$doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$doc->formatOutput = true;

		$response = $doc->createElementNS(self::NS_ZKN, 'ZKN:zakLa01');
		$response->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'La01', stuurgegevens: $stuurgegevens));

		$params = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
		$count = $doc->createElementNS(self::NS_STUF, 'StUF:aantalVoorkomens');
		$count->nodeValue = (string)count($cases);
		$params->appendChild($count);
		$response->appendChild($params);

		$answer = $doc->createElementNS(self::NS_ZKN, 'ZKN:antwoord');
		foreach ($cases as $case) {
			$answer->appendChild($this->buildCaseObject(doc: $doc, fields: $case));
		}

		$response->appendChild($answer);
		$this->wrapInEnvelope(doc: $doc, bodyContent: $response);

		return (string)$doc->saveXML();
	}//end buildZakLa01()

	/**
	 * Build a ZKN:object element for a zaak.
	 *
	 * @param DOMDocument $doc The DOMDocument to create elements in.
	 * @param array $fields Zaak field name to value map.
	 *
	 * @return DOMElement The populated ZKN:object element.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function buildCaseObject(DOMDocument $doc, array $fields): DOMElement {
		$obj = $doc->createElementNS(self::NS_ZKN, 'ZKN:object');
		$obj->setAttributeNS(self::NS_STUF, 'StUF:entiteittype', 'ZAK');

		$fieldMap = [
			'zaakidentificatie' => 'identificatie',
			'omschrijving' => 'omschrijving',
			'startdatum' => 'startdatum',
			'einddatum' => 'einddatum',
			'zaaktype' => 'zaaktype',
			'status' => 'status',
		];

		foreach ($fieldMap as $prop => $xmlName) {
			if (isset($fields[$prop]) === true) {
				$el = $doc->createElementNS(self::NS_ZKN, 'ZKN:' . $xmlName);
				$el->nodeValue = (string)$fields[$prop];
				$obj->appendChild($el);
			}
		}

		return $obj;
	}//end buildZaakObject()

	/**
	 * Build Bv03 bevestiging SOAP response for zakLk01 operations.
	 *
	 * @param string $caseIdentification The zaak identifier being confirmed; '' for a Bv03 without a body.
	 * @param array $stuurgegevens Stuurgegevens configuration.
	 *
	 * @return string The complete SOAP XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildBv03(string $caseIdentification, array $stuurgegevens): string {
		$doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$doc->formatOutput = true;

		$bv03 = $doc->createElementNS(self::NS_STUF, 'StUF:Bv03Bericht');
		$bv03->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'Bv03', stuurgegevens: $stuurgegevens));

		if ($caseIdentification !== '') {
			$body = $doc->createElementNS(self::NS_STUF, 'StUF:body');
			$case = $doc->createElementNS(self::NS_ZKN, 'ZKN:zaakidentificatie');
			$case->nodeValue = $caseIdentification;
			$body->appendChild($case);
			$bv03->appendChild($body);
		}


		$this->wrapInEnvelope(doc: $doc, bodyContent: $bv03);

		return (string)$doc->saveXML();
	}//end buildBv03()

	/**
	 * Build Fo03 foutmelding SOAP fault for StUF-ZKN errors.
	 *
	 * @param string $code The StUF error code.
	 * @param string $omschrijving Human-readable error description.
	 * @param array $stuurgegevens Stuurgegevens configuration.
	 *
	 * @return string The complete SOAP XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildFo03(string $code, string $omschrijving, array $stuurgegevens): string {
		$fault = new StufFault(berichtcode: 'Fo03', code: $code);
		$fault->omschrijving = $omschrijving;

		return $this->buildFault(fault: $fault, stuurgegevens: $stuurgegevens);
	}//end buildFo03()

	/**
	 * Build outbound npsLv01 (persoon opvragen) SOAP request XML.
	 *
	 * @param array $criteria Search criteria (field => value pairs).
	 * @param array $stuurgegevens Stuurgegevens for the outbound request.
	 * @param int $maximumCount Maximum number of results to request.
	 *
	 * @return string The complete SOAP XML string.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function buildNpsLv01(array $criteria, array $stuurgegevens, int $maximumCount = 100): string {
		$doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$doc->formatOutput = true;

		$npsLv01 = $doc->createElementNS(self::NS_BG, 'BG:npsLv01');
		$npsLv01->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'Lv01', stuurgegevens: $stuurgegevens));

		$params = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
		$max = $doc->createElementNS(self::NS_STUF, 'StUF:maximumAantal');
		$max->nodeValue = (string)$maximumCount;
		$params->appendChild($max);
		$npsLv01->appendChild($params);

		$gelijk = $doc->createElementNS(self::NS_BG, 'BG:gelijk');
		foreach ($criteria as $field => $value) {
			$el = $doc->createElementNS(self::NS_BG, 'BG:' . $field);
			$el->nodeValue = (string)$value;
			$gelijk->appendChild($el);
		}

		$npsLv01->appendChild($gelijk);
		$this->wrapInEnvelope(doc: $doc, bodyContent: $npsLv01);

		return (string)$doc->saveXML();
	}//end buildNpsLv01()

	/**
	 * Append a StUF-namespaced text-content element per tag, in order.
	 *
	 * @param DOMElement            $parent The parent to append to.
	 * @param array<string, string> $texts  Text content per qualified tag.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-4
	 */
	private function appendStufTexts(DOMElement $parent, array $texts): void {
		foreach ($texts as $tag => $text) {
			$el = $parent->ownerDocument->createElementNS(self::NS_STUF, $tag);
			$el->nodeValue = $text;
			$parent->appendChild($el);
		}

	}//end appendStufTexts()

	/**
	 * Append an un-namespaced text-content element per tag, in order.
	 *
	 * @param DOMElement            $parent The parent to append to.
	 * @param array<string, string> $texts  Text content per tag.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-4
	 */
	private function appendPlainTexts(DOMElement $parent, array $texts): void {
		foreach ($texts as $tag => $text) {
			$el = $parent->ownerDocument->createElement($tag);
			$el->nodeValue = $text;
			$parent->appendChild($el);
		}

	}//end appendPlainTexts()
}//end class
