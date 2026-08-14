<?php

/**
 * StUF-BG Service.
 *
 * Handles inbound StUF-BG 3.10 SOAP requests (npsLv01/npsLa01, adrLv01/adrLa01)
 * and outbound StUF-BG queries via the existing SOAPService infrastructure.
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
 * @spec openspec/changes/stuf-adapter/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Util\SafeXmlParser;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * StUF-BG inbound and outbound service.
 *
 * Parses incoming npsLv01/adrLv01 SOAP requests, queries OpenRegister
 * for matching person or address objects, and builds StUF-BG XML responses.
 * Also supports outbound npsLv01 queries to external StUF-BG sources.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-1
 */
class StUFBGService {

	/**
	 * BRP register slug.
	 *
	 * @var string
	 */
	private const REGISTER_BRP = 'brp';

	/**
	 * BRP persoon schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_PERSOON = 'ingeschreven-persoon';

	/**
	 * BAG register slug.
	 *
	 * @var string
	 */
	private const REGISTER_BAG = 'bag';

	/**
	 * BAG nummeraanduiding schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_NUMMERAANDUIDING = 'nummeraanduiding';

	/**
	 * StUFBGService constructor.
	 *
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param ORObjectService $orObjectService OpenRegister object service.
	 * @param StUFFieldMapper $fieldMapper StUF-BG field mapper.
	 * @param StUFXMLBuilder $xmlBuilder StUF XML response builder.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ORObjectService $orObjectService,
		private readonly StUFFieldMapper $fieldMapper,
		private readonly StUFXMLBuilder $xmlBuilder,
	) {

	}//end __construct()

	/**
	 * Handle an inbound npsLv01 (persoon opvragen) SOAP request.
	 *
	 * Parses the SOAP XML, extracts query parameters, searches OpenRegister BRP,
	 * and returns a StUF-BG npsLa01 response or Fo01 fault.
	 *
	 * @param string $soapXml The raw incoming SOAP XML string.
	 * @param array $stuurgegevens Stuurgegevens for the response (zender/ontvanger config).
	 *
	 * @return string The complete npsLa01 or Fo01 SOAP XML response.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function handleNpsLv01(string $soapXml, array $stuurgegevens): string {
		$xml = SafeXmlParser::parse(data: $soapXml);
		if ($xml === false) {
			$this->logger->warning(message: 'StUF-BG: malformed XML in npsLv01 request.');
			return $this->xmlBuilder->buildFo01(
				code: 'StUF046',
				omschrijving: 'Malformed XML: unable to parse SOAP request.',
				stuurgegevens: $stuurgegevens
			);
		}

		$namespaces = $xml->getNamespaces(recursive: true);
		$bgNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'bg');
		$stufNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'stuf');

		$body = $this->extractSoapBody(xml: $xml, namespaces: $namespaces);
		if ($body === null) {
			return $this->xmlBuilder->buildFo01(
				code: 'StUF046',
				omschrijving: 'Malformed XML: missing SOAP body.',
				stuurgegevens: $stuurgegevens
			);
		}

		$criteria = $this->extractNpsCriteria(body: $body, bgNs: $bgNs);
		$scope = $this->extractScope(body: $body, bgNs: $bgNs, stufNs: $stufNs);
		$maximumCount = $this->extractMaximumCount(body: $body, stufNs: $stufNs);
		$crossRef = $this->extractCrossRefnummer(body: $body, stufNs: $stufNs);

		if (empty($criteria) === true) {
			return $this->xmlBuilder->buildFo01(
				code: 'StUF046',
				omschrijving: 'Missing required search criteria in npsLv01.',
				stuurgegevens: $stuurgegevens
			);
		}

		if ($crossRef !== null) {
			$stuurgegevens['crossRefnummer'] = $crossRef;
		}

		$persons = $this->searchPersons(criteria: $criteria, limit: $maximumCount);
		$mapped = array_map(
			callback: fn (array $p) => $this->fieldMapper->mapPersonToStUF(person: $p),
			array: $persons
		);

		return $this->xmlBuilder->buildNpsLa01(
			persons: $mapped,
			stuurgegevens: $stuurgegevens,
			scope: $scope
		);

	}//end handleNpsLv01()

	/**
	 * Handle an inbound adrLv01 (adres opvragen) SOAP request.
	 *
	 * Parses the SOAP XML, extracts query parameters, searches OpenRegister BAG,
	 * and returns a StUF-BG adrLa01 response or Fo01 fault.
	 *
	 * @param string $soapXml The raw incoming SOAP XML string.
	 * @param array $stuurgegevens Stuurgegevens for the response.
	 *
	 * @return string The complete adrLa01 or Fo01 SOAP XML response.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function handleAdrLv01(string $soapXml, array $stuurgegevens): string {
		$xml = SafeXmlParser::parse(data: $soapXml);
		if ($xml === false) {
			$this->logger->warning(message: 'StUF-BG: malformed XML in adrLv01 request.');
			return $this->xmlBuilder->buildFo01(
				code: 'StUF046',
				omschrijving: 'Malformed XML: unable to parse SOAP request.',
				stuurgegevens: $stuurgegevens
			);
		}

		$namespaces = $xml->getNamespaces(recursive: true);
		$bgNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'bg');

		$body = $this->extractSoapBody(xml: $xml, namespaces: $namespaces);
		if ($body === null) {
			return $this->xmlBuilder->buildFo01(
				code: 'StUF046',
				omschrijving: 'Malformed XML: missing SOAP body.',
				stuurgegevens: $stuurgegevens
			);
		}

		$criteria = $this->extractAdrCriteria(body: $body, bgNs: $bgNs);
		$crossRef = $this->extractCrossRefnummer(
			body: $body,
			stufNs: $this->resolveNamespace(namespaces: $namespaces, hint: 'stuf')
		);

		if ($crossRef !== null) {
			$stuurgegevens['crossRefnummer'] = $crossRef;
		}

		$addresses = $this->searchAddresses(criteria: $criteria);
		$mapped = array_map(
			callback: fn (array $a) => $this->fieldMapper->mapAddressToStUF(address: $a),
			array: $addresses
		);

		return $this->xmlBuilder->buildAdrLa01(addresses: $mapped, stuurgegevens: $stuurgegevens);
	}//end handleAdrLv01()

	/**
	 * Parse a npsLa01 SOAP response from an external StUF-BG source.
	 *
	 * Extracts all person objects from the response and maps them to
	 * OpenRegister-compatible property arrays.
	 *
	 * @param string $soapXml The raw npsLa01 SOAP XML string.
	 *
	 * @return array Array of OpenRegister-mapped person property arrays.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	public function parseNpsLa01Response(string $soapXml): array {
		$xml = SafeXmlParser::parse(data: $soapXml);
		if ($xml === false) {
			$this->logger->warning(message: 'StUF-BG: could not parse npsLa01 response.');
			return [];
		}

		$namespaces = $xml->getNamespaces(recursive: true);
		$bgNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'bg');

		$body = $this->extractSoapBody(xml: $xml, namespaces: $namespaces);
		if ($body === null) {
			return [];
		}

		$persons = [];
		$bgBodyKids = $body->children($bgNs);
		if ($bgNs !== '' && isset($bgBodyKids->npsLa01) === true) {
			$la01 = $bgBodyKids->npsLa01;
			$la01Kids = $la01->children($bgNs);
			if (isset($la01Kids->antwoord) === true) {
				$answer = $la01Kids->antwoord;
				$answerKids = $answer->children($bgNs);
				foreach ($answerKids->object as $obj) {
					$persons[] = $this->parsePersonObject(
						obj: $obj,
						bgNs: $bgNs,
						stufNs: $this->resolveNamespace(namespaces: $namespaces, hint: 'stuf')
					);
				}
			}
		}

		return $persons;
	}//end parseNpsLa01Response()

	/**
	 * Extract person criteria from a npsLv01 body element.
	 *
	 * @param SimpleXMLElement $body The SOAP body element.
	 * @param string $bgNs The BG namespace URI.
	 *
	 * @return array Associative array of OpenRegister filter criteria.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function extractNpsCriteria(SimpleXMLElement $body, string $bgNs): array {
		$criteria = [];
		if ($bgNs === '') {
			return $criteria;
		}

		$bgBodyChildren = $body->children($bgNs);
		if (isset($bgBodyChildren->npsLv01) === false) {
			return $criteria;
		}

		$npsLv01 = $bgBodyChildren->npsLv01;
		$npsLv01BgKids = $npsLv01->children($bgNs);
		if (isset($npsLv01BgKids->gelijk) === false) {
			return $criteria;
		}

		$gelijk = $npsLv01BgKids->gelijk;

		$stufFieldMap = [
			'inp.bsn' => 'burgerservicenummer',
			'geslachtsnaam' => 'geslachtsnaam',
			'voorvoegselGeslachtsnaam' => 'voorvoegsel',
			'voornamen' => 'voornamen',
		];

		foreach ($gelijk->children($bgNs) as $elName => $el) {
			if (isset($stufFieldMap[$elName]) === true && (string)$el !== '') {
				$criteria[$stufFieldMap[$elName]] = (string)$el;
			}
		}

		return $criteria;
	}//end extractNpsCriteria()

	/**
	 * Extract address criteria from an adrLv01 body element.
	 *
	 * @param SimpleXMLElement $body The SOAP body element.
	 * @param string $bgNs The BG namespace URI.
	 *
	 * @return array Associative array of filter criteria.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function extractAdrCriteria(SimpleXMLElement $body, string $bgNs): array {
		$criteria = [];
		if ($bgNs === '') {
			return $criteria;
		}

		$bgBodyKids = $body->children($bgNs);
		if (isset($bgBodyKids->adrLv01) === false) {
			return $criteria;
		}

		$adrLv01 = $bgBodyKids->adrLv01;
		$adrBgKids = $adrLv01->children($bgNs);
		if (isset($adrBgKids->gelijk) === false) {
			return $criteria;
		}

		$gelijk = $adrBgKids->gelijk;
		$fieldMap = [
			'postcode' => 'postcode',
			'huisnummer' => 'huisnummer',
			'straatnaam' => 'straatnaam',
		];

		foreach ($gelijk->children($bgNs) as $elName => $el) {
			if (isset($fieldMap[$elName]) === true && (string)$el !== '') {
				$criteria[$fieldMap[$elName]] = (string)$el;
			}
		}

		return $criteria;
	}//end extractAdrCriteria()

	/**
	 * Extract scope field list from a npsLv01 request.
	 *
	 * @param SimpleXMLElement $body The SOAP body element.
	 * @param string $bgNs The BG namespace URI.
	 * @param string $stufNs The StUF namespace URI (reserved for future multi-namespace scope handling).
	 *
	 * @return array|null Array of requested StUF field names, or null if no scope specified.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function extractScope(SimpleXMLElement $body, string $bgNs, string $stufNs): ?array {
		if ($bgNs === '') {
			return null;
		}

		$bgBodyKids = $body->children($bgNs);
		if (isset($bgBodyKids->npsLv01) === false) {
			return null;
		}

		$npsLv01 = $bgBodyKids->npsLv01;
		$npsLv01Kids = $npsLv01->children($bgNs);
		if (isset($npsLv01Kids->scope) === false) {
			return null;
		}

		$scope = $npsLv01Kids->scope;
		$fields = [];
		$scopeKids = $scope->children($bgNs);
		if (isset($scopeKids->object) === true) {
			$objectChildren = $scopeKids->object->children($bgNs);
			for ($objectChildren->rewind(); $objectChildren->valid() === true; $objectChildren->next()) {
				$fields[] = $objectChildren->key();
			}
		}

		if (empty($fields) === false) {
			return $fields;
		}

		return null;
	}//end extractScope()

	/**
	 * Extract the maximumAantal parameter from a StUF request.
	 *
	 * @param SimpleXMLElement $body The SOAP body element.
	 * @param string $stufNs The StUF namespace URI.
	 *
	 * @return int Maximum number of records, defaulting to 100.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function extractMaximumCount(SimpleXMLElement $body, string $stufNs): int {
		if ($stufNs === '') {
			return 100;
		}

		$params = null;
		foreach ($body->children() as $child) {
			$stufKids = $child->children($stufNs);
			if (isset($stufKids->parameters) === true) {
				$params = $stufKids->parameters;
				break;
			}
		}

		if ($params === null) {
			return 100;
		}

		$stufParamKids = $params->children($stufNs);
		if (isset($stufParamKids->maximumAantal) === false || (string)$stufParamKids->maximumAantal === '') {
			return 100;
		}

		$max = $stufParamKids->maximumAantal;

		return max(1, (int)(string)$max);
	}//end extractMaximumAantal()

	/**
	 * Extract the referentienummer from stuurgegevens for use as crossRefnummer.
	 *
	 * @param SimpleXMLElement $body The SOAP body element.
	 * @param string $stufNs The StUF namespace URI.
	 *
	 * @return string|null The referentienummer, or null if not present.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function extractCrossRefnummer(SimpleXMLElement $body, string $stufNs): ?string {
		if ($stufNs === '') {
			return null;
		}

		foreach ($body->children() as $child) {
			$stufKids = $child->children($stufNs);
			if (isset($stufKids->stuurgegevens) === false) {
				continue;
			}

			$sg = $stufKids->stuurgegevens;
			$sgKids = $sg->children($stufNs);
			if (isset($sgKids->referentienummer) === true && (string)$sgKids->referentienummer !== '') {
				return (string)$sgKids->referentienummer;
			}
		}

		return null;
	}//end extractCrossRefnummer()

	/**
	 * Parse a single BG:object element from a npsLa01 response.
	 *
	 * @param SimpleXMLElement $obj The BG:object element.
	 * @param string $bgNs The BG namespace URI.
	 * @param string $stufNs The StUF namespace URI.
	 *
	 * @return array OpenRegister-mapped person properties.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function parsePersonObject(SimpleXMLElement $obj, string $bgNs, string $stufNs): array {
		$stufData = [];
		foreach ($obj->children($bgNs) as $name => $el) {
			$noValue = (string)($el->attributes($stufNs)['noValue'] ?? '');
			if ($noValue === 'nietOndersteund') {
				continue;
			}

			if ($name === 'verblijfsadres') {
				$adres = [];
				foreach ($el->children($bgNs) as $aName => $aEl) {
					$adres[$aName] = (string)$aEl;
				}

				$stufData['verblijfsadres'] = $adres;
			} else {
				if ($noValue !== '') {
					$stufData[$name] = null;
				} else {
					$stufData[$name] = (string)$el;
				}
			}
		}//end foreach

		return $this->fieldMapper->mapStUFToPerson(stufData: $stufData);
	}//end parsePersonObject()

	/**
	 * Search OpenRegister BRP for persons matching the given criteria.
	 *
	 * @param array $criteria Filter criteria (field => value pairs).
	 * @param int $limit Maximum number of results.
	 *
	 * @return array Array of person property arrays.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function searchPersons(array $criteria, int $limit = 100): array {
		$filters = array_merge(
			[
				'register' => self::REGISTER_BRP,
				'schema' => self::SCHEMA_PERSOON,
				'_limit' => $limit,
			],
			$criteria
		);

		$result = $this->orObjectService->findAll(config: ['filters' => $filters]);
		return $result['results'] ?? [];
	}//end searchPersons()

	/**
	 * Search OpenRegister BAG for addresses matching the given criteria.
	 *
	 * @param array $criteria Filter criteria (field => value pairs).
	 *
	 * @return array Array of address property arrays.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function searchAddresses(array $criteria): array {
		$filters = array_merge(
			[
				'register' => self::REGISTER_BAG,
				'schema' => self::SCHEMA_NUMMERAANDUIDING,
			],
			$criteria
		);

		$result = $this->orObjectService->findAll(config: ['filters' => $filters]);
		return $result['results'] ?? [];
	}//end searchAddresses()

	/**
	 * Extract the SOAP Body element from a parsed SOAP Envelope.
	 *
	 * @param SimpleXMLElement $xml The parsed SOAP envelope.
	 * @param array $namespaces The namespace map.
	 *
	 * @return SimpleXMLElement|null The SOAP body element, or null if not found.
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-1
	 */
	private function extractSoapBody(SimpleXMLElement $xml, array $namespaces): ?SimpleXMLElement {
		$soapNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'soap');
		if ($soapNs !== '') {
			$soapKids = $xml->children($soapNs);
			// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
			if (isset($soapKids->{'Body'}) === true) {
				// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
				return $soapKids->{'Body'};
			}
		}

		// Fallback: try common namespace URIs.
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
	 * Matches the namespace map value whose key or URI contains the hint string.
	 *
	 * @param array $namespaces The namespace map (prefix => URI).
	 * @param string $hint Lowercase hint string (e.g. 'bg', 'stuf', 'soap').
	 *
	 * @return string The namespace URI, or empty string if not found.
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-1
	 */
	private function resolveNamespace(array $namespaces, string $hint): string {
		foreach ($namespaces as $prefix => $uri) {
			if (str_contains(haystack: strtolower($prefix), needle: $hint) === true) {
				return (string)$uri;
			}

			if (str_contains(haystack: strtolower($uri), needle: $hint) === true) {
				return (string)$uri;
			}
		}

		return '';
	}//end resolveNamespace()
}//end class
