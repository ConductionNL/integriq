<?php

/**
 * StUF-ZKN Service.
 *
 * Handles inbound StUF-ZKN 3.10 SOAP requests for zaak management:
 * zakLk01 (create/update) and zakLv01/zakLa01 (query).
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
 * @spec openspec/changes/stuf-adapter/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Util\SafeXmlParser;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * StUF-ZKN 3.10 zaak management service.
 *
 * Parses incoming zakLk01 (aanmaken/bijwerken) and zakLv01 (opvragen) SOAP
 * requests, interacts with OpenRegister for zaak persistence, and builds
 * zakLa01/Bv03/Fo03 responses via StUFXMLBuilder.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-8
 */
class StUFZKNService
{

    /**
     * ZKN register slug.
     *
     * @var string
     */
    private const REGISTER_ZKN = 'zaken';

    /**
     * ZKN zaak schema slug.
     *
     * @var string
     */
    private const SCHEMA_ZAAK = 'zaak';

    /**
     * ZKN field mapping from StUF to OpenRegister properties.
     *
     * @var array<string,string>
     */
    private const ZAAK_FIELD_MAP = [
        'identificatie' => 'zaakidentificatie',
        'omschrijving'  => 'omschrijving',
        'startdatum'    => 'startdatum',
        'einddatum'     => 'einddatum',
        'zaaktype'      => 'zaaktype',
        'status'        => 'status',
    ];

    /**
     * StUFZKNService constructor.
     *
     * @param LoggerInterface $logger          PSR-3 logger.
     * @param ORObjectService $orObjectService OpenRegister object service.
     * @param StUFXMLBuilder  $xmlBuilder      StUF XML response builder.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ORObjectService $orObjectService,
        private readonly StUFXMLBuilder $xmlBuilder,
    ) {

    }//end __construct()

    /**
     * Handle an inbound zakLk01 (zaak aanmaken/bijwerken) SOAP request.
     *
     * Parses the message, creates or updates the zaak in OpenRegister, and
     * returns a Bv03 bevestiging or Fo03 foutmelding.
     *
     * @param string $soapXml       The raw incoming SOAP XML string.
     * @param array  $stuurgegevens Stuurgegevens for the response.
     *
     * @return string The complete Bv03 or Fo03 SOAP XML response.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    public function handleZakLk01(string $soapXml, array $stuurgegevens): string
    {
        $xml = SafeXmlParser::parse(data: $soapXml);
        if ($xml === false) {
            $this->logger->warning(message: 'StUF-ZKN: malformed XML in zakLk01 request.');
            return $this->xmlBuilder->buildFo03(
                code: 'StUF046',
                omschrijving: 'Malformed XML: unable to parse SOAP request.',
                stuurgegevens: $stuurgegevens
            );
        }

        $namespaces = $xml->getNamespaces(recursive: true);
        $zknNs      = $this->resolveNamespace(namespaces: $namespaces, hint: 'zkn');
        $body       = $this->extractSoapBody(xml: $xml, namespaces: $namespaces);

        if ($body === null) {
            return $this->xmlBuilder->buildFo03(
                code: 'StUF046',
                omschrijving: 'Malformed XML: missing SOAP body.',
                stuurgegevens: $stuurgegevens
            );
        }

        $zaakData = $this->extractZaakData(body: $body, zknNs: $zknNs);
        if (empty($zaakData) === true) {
            return $this->xmlBuilder->buildFo03(
                code: 'StUF046',
                omschrijving: 'Missing required zaak data in zakLk01.',
                stuurgegevens: $stuurgegevens
            );
        }

        if (isset($zaakData['zaaktype']) === false) {
            return $this->xmlBuilder->buildFo03(
                code: 'StUF050',
                omschrijving: 'Required field zaaktype is missing.',
                stuurgegevens: $stuurgegevens
            );
        }

        $zaakIdentificatie = $this->persistZaak(zaakData: $zaakData);

        $crossRef = $this->extractCrossRefnummer(body: $body, stufNs: $this->resolveNamespace(namespaces: $namespaces, hint: 'stuf'));
        if ($crossRef !== null) {
            $stuurgegevens['crossRefnummer'] = $crossRef;
        }

        return $this->xmlBuilder->buildBv03(zaakIdentificatie: $zaakIdentificatie, stuurgegevens: $stuurgegevens);

    }//end handleZakLk01()

    /**
     * Handle an inbound zakLv01 (zaak opvragen) SOAP request.
     *
     * Parses the query, searches OpenRegister, and returns a zakLa01 response
     * or Fo03 foutmelding.
     *
     * @param string $soapXml       The raw incoming SOAP XML string.
     * @param array  $stuurgegevens Stuurgegevens for the response.
     *
     * @return string The complete zakLa01 or Fo03 SOAP XML response.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    public function handleZakLv01(string $soapXml, array $stuurgegevens): string
    {
        $xml = SafeXmlParser::parse(data: $soapXml);
        if ($xml === false) {
            $this->logger->warning(message: 'StUF-ZKN: malformed XML in zakLv01 request.');
            return $this->xmlBuilder->buildFo03(
                code: 'StUF046',
                omschrijving: 'Malformed XML: unable to parse SOAP request.',
                stuurgegevens: $stuurgegevens
            );
        }

        $namespaces = $xml->getNamespaces(recursive: true);
        $zknNs      = $this->resolveNamespace(namespaces: $namespaces, hint: 'zkn');
        $stufNs     = $this->resolveNamespace(namespaces: $namespaces, hint: 'stuf');
        $body       = $this->extractSoapBody(xml: $xml, namespaces: $namespaces);

        if ($body === null) {
            return $this->xmlBuilder->buildFo03(
                code: 'StUF046',
                omschrijving: 'Malformed XML: missing SOAP body.',
                stuurgegevens: $stuurgegevens
            );
        }

        $criteria = $this->extractZakLv01Criteria(body: $body, zknNs: $zknNs, stufNs: $stufNs);
        $crossRef = $this->extractCrossRefnummer(body: $body, stufNs: $stufNs);
        if ($crossRef !== null) {
            $stuurgegevens['crossRefnummer'] = $crossRef;
        }

        $zaken  = $this->searchZaken(criteria: $criteria);
        $mapped = array_map(callback: fn(array $z) => $this->mapZaakToStuf(zaak: $z), array: $zaken);

        return $this->xmlBuilder->buildZakLa01(zaken: $mapped, stuurgegevens: $stuurgegevens);

    }//end handleZakLv01()

    /**
     * Extract zaak data fields from a zakLk01 body element.
     *
     * @param SimpleXMLElement $body  The SOAP body element.
     * @param string           $zknNs The ZKN namespace URI.
     *
     * @return array Zaak property array for OpenRegister storage.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    private function extractZaakData(SimpleXMLElement $body, string $zknNs): array
    {
        $zaakData = [];
        if ($zknNs === '') {
            return $zaakData;
        }

        $zknBodyKids = $body->children($zknNs);
        if (isset($zknBodyKids->zakLk01) === false) {
            return $zaakData;
        }

        $zakLk01     = $zknBodyKids->zakLk01;
        $zakLk01Kids = $zakLk01->children($zknNs);
        if (isset($zakLk01Kids->object) === false) {
            return $zaakData;
        }

        $obj = $zakLk01Kids->object;
        foreach ($obj->children($zknNs) as $elName => $el) {
            if (isset(self::ZAAK_FIELD_MAP[$elName]) === true && (string) $el !== '') {
                $zaakData[self::ZAAK_FIELD_MAP[$elName]] = (string) $el;
            }
        }

        return $zaakData;

    }//end extractZaakData()

    /**
     * Extract query criteria from a zakLv01 body element.
     *
     * @param SimpleXMLElement $body   The SOAP body element.
     * @param string           $zknNs  The ZKN namespace URI.
     * @param string           $stufNs The StUF namespace URI (reserved for future multi-namespace criteria).
     *
     * @return array Associative array of OpenRegister filter criteria.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    private function extractZakLv01Criteria(SimpleXMLElement $body, string $zknNs, string $stufNs): array
    {
        $criteria = [];
        if ($zknNs === '') {
            return $criteria;
        }

        $zknBodyKids = $body->children($zknNs);
        if (isset($zknBodyKids->zakLv01) === false) {
            return $criteria;
        }

        $zakLv01     = $zknBodyKids->zakLv01;
        $zakLv01Kids = $zakLv01->children($zknNs);
        if (isset($zakLv01Kids->gelijk) === false) {
            return $criteria;
        }

        $gelijk = $zakLv01Kids->gelijk;
        foreach ($gelijk->children($zknNs) as $elName => $el) {
            if (isset(self::ZAAK_FIELD_MAP[$elName]) === true && (string) $el !== '') {
                $criteria[self::ZAAK_FIELD_MAP[$elName]] = (string) $el;
            }
        }

        return $criteria;

    }//end extractZakLv01Criteria()

    /**
     * Map an OpenRegister zaak array to StUF-ZKN field names for XML building.
     *
     * @param array $zaak The OpenRegister zaak property array.
     *
     * @return array StUF field name to value pairs.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    private function mapZaakToStuf(array $zaak): array
    {
        $stufData = [];
        foreach (array_values(self::ZAAK_FIELD_MAP) as $registerField) {
            if (isset($zaak[$registerField]) === true) {
                $stufData[$registerField] = $zaak[$registerField];
            }
        }

        return $stufData;

    }//end mapZaakToStuf()

    /**
     * Persist a zaak in OpenRegister (create or update).
     *
     * Checks for an existing zaak by zaakidentificatie and updates it, or creates a new one.
     *
     * @param array $zaakData The zaak data to persist.
     *
     * @return string The zaakidentificatie of the persisted zaak.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    private function persistZaak(array $zaakData): string
    {
        $identificatie = $zaakData['zaakidentificatie'] ?? '';

        if ($identificatie !== '') {
            $existing = $this->orObjectService->findAll(
                    config: [
                        'filters' => [
                            'register'          => self::REGISTER_ZKN,
                            'schema'            => self::SCHEMA_ZAAK,
                            'zaakidentificatie' => $identificatie,
                        ],
                    ]
                    );

            $results = $existing['results'] ?? [];
            if (empty($results) === false) {
                if (is_array($results[0]) === true) {
                    $existingData = $results[0];
                } else {
                    $existingData = $results[0]->getObject();
                }

                $merged = array_merge($existingData, $zaakData);
                $this->orObjectService->saveObject(
                    register: self::REGISTER_ZKN,
                    schema: self::SCHEMA_ZAAK,
                    object: $merged
                );
                return $identificatie;
            }
        }//end if

        $saved = $this->orObjectService->saveObject(
            register: self::REGISTER_ZKN,
            schema: self::SCHEMA_ZAAK,
            object: $zaakData
        );
        if (is_array($saved) === true) {
            $savedData = $saved;
        } else {
            $savedData = $saved->getObject();
        }

        return (string) ($savedData['zaakidentificatie'] ?? $identificatie);

    }//end persistZaak()

    /**
     * Search OpenRegister ZKN for zaken matching the given criteria.
     *
     * @param array $criteria Filter criteria (field => value pairs).
     *
     * @return array Array of zaak property arrays.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    private function searchZaken(array $criteria): array
    {
        $filters = array_merge(
            [
                'register' => self::REGISTER_ZKN,
                'schema'   => self::SCHEMA_ZAAK,
            ],
            $criteria
        );

        $result = $this->orObjectService->findAll(config: ['filters' => $filters]);
        return $result['results'] ?? [];

    }//end searchZaken()

    /**
     * Extract the referentienummer from stuurgegevens for use as crossRefnummer.
     *
     * @param SimpleXMLElement $body   The SOAP body element.
     * @param string           $stufNs The StUF namespace URI.
     *
     * @return string|null The referentienummer, or null if not present.
     *
     * @spec openspec/specs/stuf-adapter/spec.md
     */
    private function extractCrossRefnummer(SimpleXMLElement $body, string $stufNs): ?string
    {
        if ($stufNs === '') {
            return null;
        }

        foreach ($body->children() as $child) {
            $stufKids = $child->children($stufNs);
            if (isset($stufKids->stuurgegevens) === false) {
                continue;
            }

            $sg     = $stufKids->stuurgegevens;
            $sgKids = $sg->children($stufNs);
            if (isset($sgKids->referentienummer) === true && (string) $sgKids->referentienummer !== '') {
                return (string) $sgKids->referentienummer;
            }
        }

        return null;

    }//end extractCrossRefnummer()

    /**
     * Extract the SOAP Body element from a parsed SOAP Envelope.
     *
     * @param SimpleXMLElement $xml        The parsed SOAP envelope.
     * @param array            $namespaces The namespace map.
     *
     * @return SimpleXMLElement|null The SOAP body element, or null if not found.
     *
     * @spec openspec/changes/stuf-adapter/tasks.md#task-8
     */
    private function extractSoapBody(SimpleXMLElement $xml, array $namespaces): ?SimpleXMLElement
    {
        $soapNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'soap');
        if ($soapNs !== '') {
            $soapKids = $xml->children($soapNs);
            // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
            if (isset($soapKids->{'Body'}) === true) {
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
                return $soapKids->{'Body'};
            }
        }

        $candidates = [
            'http://schemas.xmlsoap.org/soap/envelope/',
            'http://www.w3.org/2003/05/soap-envelope',
        ];
        foreach ($candidates as $ns) {
            $kids = $xml->children($ns);
            // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
            if (isset($kids->{'Body'}) === true) {
                // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
                return $kids->{'Body'};
            }
        }

        return null;

    }//end extractSoapBody()

    /**
     * Resolve a namespace URI from the namespace map by hint.
     *
     * @param array  $namespaces The namespace map (prefix => URI).
     * @param string $hint       Lowercase hint string (e.g. 'zkn', 'stuf', 'soap').
     *
     * @return string The namespace URI, or empty string if not found.
     *
     * @spec openspec/changes/stuf-adapter/tasks.md#task-8
     */
    private function resolveNamespace(array $namespaces, string $hint): string
    {
        foreach ($namespaces as $prefix => $uri) {
            if (str_contains(haystack: strtolower($prefix), needle: $hint) === true) {
                return (string) $uri;
            }

            if (str_contains(haystack: strtolower($uri), needle: $hint) === true) {
                return (string) $uri;
            }
        }

        return '';

    }//end resolveNamespace()
}//end class
