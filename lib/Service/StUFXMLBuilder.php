<?php

/**
 * StUF XML Builder Service.
 *
 * Builds StUF-BG 3.10 and StUF-ZKN 3.10 compliant SOAP XML messages for both
 * inbound responses (npsLa01, adrLa01, zakLa01, Bv03) and outbound queries (npsLv01).
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DOMDocument;
use DOMElement;
use Psr\Log\LoggerInterface;
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
class StUFXMLBuilder
{

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
     *
     * @param LoggerInterface $logger PSR-3 logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {

    }//end __construct()

    /**
     * Create a new DOMDocument with SOAP envelope containing the given body element.
     *
     * @param DOMDocument $doc         The DOMDocument to use.
     * @param DOMElement  $bodyContent The element to place inside SOAP body.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-adapter/tasks.md#task-4
     */
    private function wrapInEnvelope(DOMDocument $doc, DOMElement $bodyContent): void
    {
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
     * @param DOMDocument $doc           The DOMDocument to create elements in.
     * @param string      $berichtcode   The StUF berichtcode (e.g. La01, Fo01, Bv03).
     * @param array       $stuurgegevens Stuurgegevens configuration values.
     *
     * @return DOMElement The populated stuurgegevens element.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-042
     */
    private function buildStuurgegevens(DOMDocument $doc, string $berichtcode, array $stuurgegevens): DOMElement
    {
        $sg = $doc->createElementNS(self::NS_STUF, 'StUF:stuurgegevens');

        $bc            = $doc->createElementNS(self::NS_STUF, 'StUF:berichtcode');
        $bc->nodeValue = $berichtcode;
        $sg->appendChild($bc);

        $zender          = $doc->createElementNS(self::NS_STUF, 'StUF:zender');
        $orgZ            = $doc->createElementNS(self::NS_STUF, 'StUF:organisatie');
        $orgZ->nodeValue = (string) ($stuurgegevens['zenderOrganisatie'] ?? '');
        $zender->appendChild($orgZ);
        $appZ            = $doc->createElementNS(self::NS_STUF, 'StUF:applicatie');
        $appZ->nodeValue = (string) ($stuurgegevens['zenderApplicatie'] ?? 'OpenConnector');
        $zender->appendChild($appZ);
        $sg->appendChild($zender);

        $ontvanger       = $doc->createElementNS(self::NS_STUF, 'StUF:ontvanger');
        $orgO            = $doc->createElementNS(self::NS_STUF, 'StUF:organisatie');
        $orgO->nodeValue = (string) ($stuurgegevens['ontvangerOrganisatie'] ?? '');
        $ontvanger->appendChild($orgO);
        $appO            = $doc->createElementNS(self::NS_STUF, 'StUF:applicatie');
        $appO->nodeValue = (string) ($stuurgegevens['ontvangerApplicatie'] ?? '');
        $ontvanger->appendChild($appO);
        $sg->appendChild($ontvanger);

        $ref            = $doc->createElementNS(self::NS_STUF, 'StUF:referentienummer');
        $ref->nodeValue = (string) Uuid::v4();
        $sg->appendChild($ref);

        $tijd            = $doc->createElementNS(self::NS_STUF, 'StUF:tijdstipBericht');
        $tijd->nodeValue = date(format: 'YmdHis').'000';
        $sg->appendChild($tijd);

        if (isset($stuurgegevens['crossRefnummer']) === true) {
            $cross            = $doc->createElementNS(self::NS_STUF, 'StUF:crossRefnummer');
            $cross->nodeValue = (string) $stuurgegevens['crossRefnummer'];
            $sg->appendChild($cross);
        }

        return $sg;

    }//end buildStuurgegevens()

    /**
     * Build a BG:object element for a person in npsLa01 responses.
     *
     * @param DOMDocument $doc    The DOMDocument to create elements in.
     * @param array       $fields StUF-BG field name to value map.
     * @param array|null  $scope  List of requested field names (null = all fields).
     *
     * @return DOMElement The populated BG:object element.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-001
     */
    private function buildPersonObject(DOMDocument $doc, array $fields, ?array $scope): DOMElement
    {
        $obj = $doc->createElementNS(self::NS_BG, 'BG:object');
        $obj->setAttributeNS(self::NS_STUF, 'StUF:entiteittype', 'NPS');

        $fieldMap = [
            'inp.bsn'                  => 'inp.bsn',
            'geslachtsnaam'            => 'geslachtsnaam',
            'voorvoegselGeslachtsnaam' => 'voorvoegselGeslachtsnaam',
            'voornamen'                => 'voornamen',
            'geboortedatum'            => 'geboortedatum',
            'geslachtsaanduiding'      => 'geslachtsaanduiding',
        ];

        foreach ($fieldMap as $key => $xmlName) {
            if ($scope !== null && in_array(needle: $key, haystack: $scope) === false) {
                continue;
            }

            $el = $doc->createElementNS(self::NS_BG, 'BG:'.$xmlName);
            if (isset($fields[$key]) === true) {
                $el->nodeValue = (string) $fields[$key];
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
     * @param DOMDocument $doc   The DOMDocument to create elements in.
     * @param array       $adres Address fields.
     *
     * @return DOMElement The populated verblijfsadres element.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-002
     */
    private function buildVerblijfsadresElement(DOMDocument $doc, array $adres): DOMElement
    {
        $adresEl = $doc->createElementNS(self::NS_BG, 'BG:verblijfsadres');

        $adresFields = [
            'gor.straatnaam'     => 'gor.straatnaam',
            'aoa.huisnummer'     => 'aoa.huisnummer',
            'aoa.postcode'       => 'aoa.postcode',
            'wpl.woonplaatsNaam' => 'wpl.woonplaatsNaam',
        ];

        foreach ($adresFields as $key => $xmlName) {
            if (isset($adres[$key]) === true) {
                $el            = $doc->createElementNS(self::NS_BG, 'BG:'.$xmlName);
                $el->nodeValue = (string) $adres[$key];
                $adresEl->appendChild($el);
            }
        }

        return $adresEl;

    }//end buildVerblijfsadresElement()

    /**
     * Build a BG:object element for an address in adrLa01 responses.
     *
     * @param DOMDocument $doc    The DOMDocument to create elements in.
     * @param array       $fields StUF-BG address field name to value map.
     *
     * @return DOMElement The populated BG:object element.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-004
     */
    private function buildAddressObject(DOMDocument $doc, array $fields): DOMElement
    {
        $obj = $doc->createElementNS(self::NS_BG, 'BG:object');
        $obj->setAttributeNS(self::NS_STUF, 'StUF:entiteittype', 'ADR');

        $fieldMap = [
            'gor.straatnaam'     => 'gor.straatnaam',
            'aoa.huisnummer'     => 'aoa.huisnummer',
            'aoa.postcode'       => 'aoa.postcode',
            'wpl.woonplaatsNaam' => 'wpl.woonplaatsNaam',
        ];

        foreach ($fieldMap as $key => $xmlName) {
            if (isset($fields[$key]) === true) {
                $el            = $doc->createElementNS(self::NS_BG, 'BG:'.$xmlName);
                $el->nodeValue = (string) $fields[$key];
                $obj->appendChild($el);
            }
        }

        return $obj;

    }//end buildAddressObject()

    /**
     * Build npsLa01 (persoon antwoord) SOAP response XML.
     *
     * @param array      $persons       Array of StUF-mapped person field arrays.
     * @param array      $stuurgegevens Stuurgegevens configuration for the response.
     * @param array|null $scope         Requested field names (null = return all fields).
     *
     * @return string The complete SOAP XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-001
     */
    public function buildNpsLa01(array $persons, array $stuurgegevens, ?array $scope=null): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $response = $doc->createElementNS(self::NS_BG, 'BG:npsLa01');
        $response->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'La01', stuurgegevens: $stuurgegevens));

        $params           = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
        $count            = $doc->createElementNS(self::NS_STUF, 'StUF:aantalVoorkomens');
        $count->nodeValue = (string) count($persons);
        $params->appendChild($count);
        $response->appendChild($params);

        $antwoord = $doc->createElementNS(self::NS_BG, 'BG:antwoord');
        foreach ($persons as $person) {
            $antwoord->appendChild($this->buildPersonObject(doc: $doc, fields: $person, scope: $scope));
        }

        $response->appendChild($antwoord);
        $this->wrapInEnvelope(doc: $doc, bodyContent: $response);

        return (string) $doc->saveXML();

    }//end buildNpsLa01()

    /**
     * Build adrLa01 (adres antwoord) SOAP response XML.
     *
     * @param array $addresses     Array of StUF-BG address field arrays.
     * @param array $stuurgegevens Stuurgegevens configuration.
     *
     * @return string The complete SOAP XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-004
     */
    public function buildAdrLa01(array $addresses, array $stuurgegevens): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $response = $doc->createElementNS(self::NS_BG, 'BG:adrLa01');
        $response->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'La01', stuurgegevens: $stuurgegevens));

        $params           = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
        $count            = $doc->createElementNS(self::NS_STUF, 'StUF:aantalVoorkomens');
        $count->nodeValue = (string) count($addresses);
        $params->appendChild($count);
        $response->appendChild($params);

        $antwoord = $doc->createElementNS(self::NS_BG, 'BG:antwoord');
        foreach ($addresses as $address) {
            $antwoord->appendChild($this->buildAddressObject(doc: $doc, fields: $address));
        }

        $response->appendChild($antwoord);
        $this->wrapInEnvelope(doc: $doc, bodyContent: $response);

        return (string) $doc->saveXML();

    }//end buildAdrLa01()

    /**
     * Build Fo01 SOAP fault message for StUF-BG errors.
     *
     * @param string $code          The StUF error code (e.g. StUF046).
     * @param string $omschrijving  Human-readable error description.
     * @param array  $stuurgegevens Stuurgegevens configuration.
     *
     * @return string The complete SOAP fault XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-001
     */
    public function buildFo01(string $code, string $omschrijving, array $stuurgegevens): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $fault     = $doc->createElementNS(self::NS_SOAP, 'SOAP-ENV:Fault');
        $faultCode = $doc->createElement('faultcode');
        $faultCode->nodeValue = 'SOAP-ENV:Client';
        $fault->appendChild($faultCode);

        $faultStr            = $doc->createElement('faultstring');
        $faultStr->nodeValue = $omschrijving;
        $fault->appendChild($faultStr);

        $detail = $doc->createElement('detail');
        $fo01   = $doc->createElementNS(self::NS_STUF, 'StUF:Fo01Bericht');
        $fo01->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'Fo01', stuurgegevens: $stuurgegevens));

        $body = $doc->createElementNS(self::NS_STUF, 'StUF:body');
        $this->appendTextChild(doc: $doc, parent: $body, ns: self::NS_STUF, tag: 'StUF:code', text: $code);
        $this->appendTextChild(doc: $doc, parent: $body, ns: self::NS_STUF, tag: 'StUF:plek', text: 'client');
        $this->appendTextChild(doc: $doc, parent: $body, ns: self::NS_STUF, tag: 'StUF:omschrijving', text: $omschrijving);
        $fo01->appendChild($body);
        $detail->appendChild($fo01);
        $fault->appendChild($detail);

        $this->wrapInEnvelope(doc: $doc, bodyContent: $fault);

        return (string) $doc->saveXML();

    }//end buildFo01()

    /**
     * Build zakLa01 (zaak antwoord) SOAP response XML.
     *
     * @param array $zaken         Array of zaak field arrays.
     * @param array $stuurgegevens Stuurgegevens configuration.
     *
     * @return string The complete SOAP XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-020
     */
    public function buildZakLa01(array $zaken, array $stuurgegevens): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $response = $doc->createElementNS(self::NS_ZKN, 'ZKN:zakLa01');
        $response->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'La01', stuurgegevens: $stuurgegevens));

        $params           = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
        $count            = $doc->createElementNS(self::NS_STUF, 'StUF:aantalVoorkomens');
        $count->nodeValue = (string) count($zaken);
        $params->appendChild($count);
        $response->appendChild($params);

        $antwoord = $doc->createElementNS(self::NS_ZKN, 'ZKN:antwoord');
        foreach ($zaken as $zaak) {
            $antwoord->appendChild($this->buildZaakObject(doc: $doc, fields: $zaak));
        }

        $response->appendChild($antwoord);
        $this->wrapInEnvelope(doc: $doc, bodyContent: $response);

        return (string) $doc->saveXML();

    }//end buildZakLa01()

    /**
     * Build a ZKN:object element for a zaak.
     *
     * @param DOMDocument $doc    The DOMDocument to create elements in.
     * @param array       $fields Zaak field name to value map.
     *
     * @return DOMElement The populated ZKN:object element.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-020
     */
    private function buildZaakObject(DOMDocument $doc, array $fields): DOMElement
    {
        $obj = $doc->createElementNS(self::NS_ZKN, 'ZKN:object');
        $obj->setAttributeNS(self::NS_STUF, 'StUF:entiteittype', 'ZAK');

        $fieldMap = [
            'zaakidentificatie' => 'identificatie',
            'omschrijving'      => 'omschrijving',
            'startdatum'        => 'startdatum',
            'einddatum'         => 'einddatum',
            'zaaktype'          => 'zaaktype',
            'status'            => 'status',
        ];

        foreach ($fieldMap as $prop => $xmlName) {
            if (isset($fields[$prop]) === true) {
                $el            = $doc->createElementNS(self::NS_ZKN, 'ZKN:'.$xmlName);
                $el->nodeValue = (string) $fields[$prop];
                $obj->appendChild($el);
            }
        }

        return $obj;

    }//end buildZaakObject()

    /**
     * Build Bv03 bevestiging SOAP response for zakLk01 operations.
     *
     * @param string $zaakIdentificatie The zaak identifier being confirmed.
     * @param array  $stuurgegevens     Stuurgegevens configuration.
     *
     * @return string The complete SOAP XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-020
     */
    public function buildBv03(string $zaakIdentificatie, array $stuurgegevens): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $bv03 = $doc->createElementNS(self::NS_STUF, 'StUF:Bv03Bericht');
        $bv03->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'Bv03', stuurgegevens: $stuurgegevens));

        $body            = $doc->createElementNS(self::NS_STUF, 'StUF:body');
        $zaak            = $doc->createElementNS(self::NS_ZKN, 'ZKN:zaakidentificatie');
        $zaak->nodeValue = $zaakIdentificatie;
        $body->appendChild($zaak);
        $bv03->appendChild($body);

        $this->wrapInEnvelope(doc: $doc, bodyContent: $bv03);

        return (string) $doc->saveXML();

    }//end buildBv03()

    /**
     * Build Fo03 foutmelding SOAP fault for StUF-ZKN errors.
     *
     * @param string $code          The StUF error code.
     * @param string $omschrijving  Human-readable error description.
     * @param array  $stuurgegevens Stuurgegevens configuration.
     *
     * @return string The complete SOAP XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-020
     */
    public function buildFo03(string $code, string $omschrijving, array $stuurgegevens): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $fault     = $doc->createElementNS(self::NS_SOAP, 'SOAP-ENV:Fault');
        $faultCode = $doc->createElement('faultcode');
        $faultCode->nodeValue = 'SOAP-ENV:Client';
        $fault->appendChild($faultCode);
        $faultStr            = $doc->createElement('faultstring');
        $faultStr->nodeValue = $omschrijving;
        $fault->appendChild($faultStr);

        $detail = $doc->createElement('detail');
        $fo03   = $doc->createElementNS(self::NS_STUF, 'StUF:Fo03Bericht');
        $fo03->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'Fo03', stuurgegevens: $stuurgegevens));

        $body = $doc->createElementNS(self::NS_STUF, 'StUF:body');
        $this->appendTextChild(doc: $doc, parent: $body, ns: self::NS_STUF, tag: 'StUF:code', text: $code);
        $this->appendTextChild(doc: $doc, parent: $body, ns: self::NS_STUF, tag: 'StUF:omschrijving', text: $omschrijving);
        $fo03->appendChild($body);
        $detail->appendChild($fo03);
        $fault->appendChild($detail);

        $this->wrapInEnvelope(doc: $doc, bodyContent: $fault);

        return (string) $doc->saveXML();

    }//end buildFo03()

    /**
     * Build outbound npsLv01 (persoon opvragen) SOAP request XML.
     *
     * @param array $criteria      Search criteria (field => value pairs).
     * @param array $stuurgegevens Stuurgegevens for the outbound request.
     * @param int   $maximumAantal Maximum number of results to request.
     *
     * @return string The complete SOAP XML string.
     *
     * @spec openspec/changes/stuf-adapter/specs/stuf-adapter/spec.md#REQ-STUF-010
     */
    public function buildNpsLv01(array $criteria, array $stuurgegevens, int $maximumAantal=100): string
    {
        $doc = new DOMDocument(version: '1.0', encoding: 'UTF-8');
        $doc->formatOutput = true;

        $npsLv01 = $doc->createElementNS(self::NS_BG, 'BG:npsLv01');
        $npsLv01->appendChild($this->buildStuurgegevens(doc: $doc, berichtcode: 'Lv01', stuurgegevens: $stuurgegevens));

        $params         = $doc->createElementNS(self::NS_STUF, 'StUF:parameters');
        $max            = $doc->createElementNS(self::NS_STUF, 'StUF:maximumAantal');
        $max->nodeValue = (string) $maximumAantal;
        $params->appendChild($max);
        $npsLv01->appendChild($params);

        $gelijk = $doc->createElementNS(self::NS_BG, 'BG:gelijk');
        foreach ($criteria as $field => $value) {
            $el            = $doc->createElementNS(self::NS_BG, 'BG:'.$field);
            $el->nodeValue = (string) $value;
            $gelijk->appendChild($el);
        }

        $npsLv01->appendChild($gelijk);
        $this->wrapInEnvelope(doc: $doc, bodyContent: $npsLv01);

        return (string) $doc->saveXML();

    }//end buildNpsLv01()

    /**
     * Append a text-content element to a parent element.
     *
     * @param DOMDocument $doc    The DOMDocument to create elements in.
     * @param DOMElement  $parent The parent to append to.
     * @param string      $ns     Namespace URI.
     * @param string      $tag    Qualified element tag name.
     * @param string      $text   Text content.
     *
     * @return void
     *
     * @spec openspec/changes/stuf-adapter/tasks.md#task-4
     */
    private function appendTextChild(DOMDocument $doc, DOMElement $parent, string $ns, string $tag, string $text): void
    {
        $el            = $doc->createElementNS($ns, $tag);
        $el->nodeValue = $text;
        $parent->appendChild($el);

    }//end appendTextChild()
}//end class
