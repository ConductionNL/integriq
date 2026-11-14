<?php

/**
 * StUF-BG Service.
 *
 * Handles inbound StUF-BG 3.10 SOAP requests (npsLv01/npsLa01, adrLv01/adrLa01)
 * and outbound StUF-BG queries via the existing SOAPService infrastructure.
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
 * @spec openspec/changes/stuf-adapter/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Util\SafeXmlParser;
use OCA\Integriq\Service\Stuf\StufNamespaces;
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
 * @SuppressWarnings(PHPMD.TooManyMethods) -- each StUF-BG reading is its own named step
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
	 * The SOAP 1.1 and 1.2 envelope namespaces, tried after the one the document names soap.
	 *
	 * @var list<string>
	 */
	private const SOAP_ENVELOPE_NAMESPACES = [
		'http://schemas.xmlsoap.org/soap/envelope/',
		'http://www.w3.org/2003/05/soap-envelope',
	];

	/**
	 * The search fields each request reads from its `gelijk` element (StUF name => OpenRegister filter).
	 *
	 * @var array<string,array<string,string>>
	 */
	private const CRITERIA_FIELDS = [
		'npsLv01' => [
			'inp.bsn' => 'burgerservicenummer',
			'geslachtsnaam' => 'geslachtsnaam',
			'voorvoegselGeslachtsnaam' => 'voorvoegsel',
			'voornamen' => 'voornamen',
		],
		'adrLv01' => [
			'postcode' => 'postcode',
			'huisnummer' => 'huisnummer',
			'straatnaam' => 'straatnaam',
		],
	];

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
		$message = $this->readMessage(soapXml: $soapXml, malformedWarning: 'StUF-BG: malformed XML in npsLv01 request.');
		if (is_string($message) === true) {
			return $this->fault(omschrijving: $message, stuurgegevens: $stuurgegevens);
		}

		$criteria = $this->criteria(message: $message, request: 'npsLv01');
		if (empty($criteria) === true) {
			return $this->fault(omschrijving: 'Missing required search criteria in npsLv01.', stuurgegevens: $stuurgegevens);
		}

		return $this->xmlBuilder->buildNpsLa01(
			persons: $this->mappedPersons(criteria: $criteria, message: $message),
			stuurgegevens: $this->withCrossRef(stuurgegevens: $stuurgegevens, message: $message),
			scope: $this->scope(message: $message)
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
		$message = $this->readMessage(soapXml: $soapXml, malformedWarning: 'StUF-BG: malformed XML in adrLv01 request.');
		if (is_string($message) === true) {
			return $this->fault(omschrijving: $message, stuurgegevens: $stuurgegevens);
		}

		$addresses = $this->searchAddresses(criteria: $this->criteria(message: $message, request: 'adrLv01'));
		return $this->xmlBuilder->buildAdrLa01(
			addresses: array_map(
				callback: fn (array $a) => $this->fieldMapper->mapAddressToStUF(address: $a),
				array: $addresses
			),
			stuurgegevens: $this->withCrossRef(stuurgegevens: $stuurgegevens, message: $message)
		);
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
		$message = $this->readMessage(soapXml: $soapXml, malformedWarning: 'StUF-BG: could not parse npsLa01 response.');
		if (is_string($message) === true) {
			return [];
		}

		$answer = $this->npsLa01Answer(message: $message);
		if ($answer === null) {
			return [];
		}

		$persons = [];
		foreach ($answer->children($message->bgNs)->object as $obj) {
			$persons[] = $this->parsePersonObject(obj: $obj, message: $message);
		}

		return $persons;
	}//end parseNpsLa01Response()

	/**
	 * The persons matching a npsLv01 request, up to its maximum count, mapped to StUF.
	 *
	 * @param array         $criteria The request's search criteria.
	 * @param StufBgMessage $message  The request.
	 *
	 * @return array The mapped persons.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function mappedPersons(array $criteria, StufBgMessage $message): array {
		$persons = $this->searchPersons(criteria: $criteria, limit: $this->maximumCount(message: $message));
		return array_map(
			callback: fn (array $p) => $this->fieldMapper->mapPersonToStUF(person: $p),
			array: $persons
		);
	}//end mappedPersons()

	/**
	 * Parse a StUF-BG message down to its SOAP body.
	 *
	 * @param string $soapXml          The raw SOAP XML string.
	 * @param string $malformedWarning What to log when it is not XML at all.
	 *
	 * @return StufBgMessage|string The message, or why it cannot be read (the Fo01 omschrijving).
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function readMessage(string $soapXml, string $malformedWarning): StufBgMessage|string {
		$xml = SafeXmlParser::parse(data: $soapXml);
		if ($xml === false) {
			$this->logger->warning(message: $malformedWarning);
			return 'Malformed XML: unable to parse SOAP request.';
		}

		$namespaces = $xml->getNamespaces(recursive: true);
		$body = $this->extractSoapBody(xml: $xml, namespaces: $namespaces);
		if ($body === null) {
			return 'Malformed XML: missing SOAP body.';
		}

		return $this->messageFor(body: $body, namespaces: $namespaces);
	}//end readMessage()

	/**
	 * The message for a SOAP body, with its BG and StUF namespaces resolved.
	 *
	 * @param SimpleXMLElement $body       The SOAP body element.
	 * @param array            $namespaces The document's namespace map.
	 *
	 * @return StufBgMessage The message.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function messageFor(SimpleXMLElement $body, array $namespaces): StufBgMessage {
		$message = new StufBgMessage(body: $body, namespaces: $namespaces);
		$message->bgNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'bg');
		$message->stufNs = $this->resolveNamespace(namespaces: $namespaces, hint: 'stuf');
		return $message;
	}//end messageFor()

	/**
	 * A StUF046 (client) fault.
	 *
	 * @param string $omschrijving  What is wrong with the request.
	 * @param array  $stuurgegevens Stuurgegevens for the response.
	 *
	 * @return string The Fo01 SOAP XML response.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function fault(string $omschrijving, array $stuurgegevens): string {
		return $this->xmlBuilder->buildFo01(
			code: 'StUF046',
			omschrijving: $omschrijving,
			stuurgegevens: $stuurgegevens
		);
	}//end fault()

	/**
	 * The search criteria a request's `gelijk` element carries.
	 *
	 * @param StufBgMessage $message The request.
	 * @param string        $request The request element, `npsLv01` or `adrLv01`.
	 *
	 * @return array Associative array of OpenRegister filter criteria; only filled, known fields.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function criteria(StufBgMessage $message, string $request): array {
		$requestKids = $this->requestChildren(message: $message, request: $request);
		if ($requestKids === null || isset($requestKids->gelijk) === false) {
			return [];
		}

		return $this->filledFields(fields: $requestKids->gelijk->children($message->bgNs), map: self::CRITERIA_FIELDS[$request]);
	}//end criteria()

	/**
	 * The filled fields that the map knows, renamed.
	 *
	 * @param SimpleXMLElement      $fields The fields.
	 * @param array<string, string> $map    StUF name => OpenRegister filter.
	 *
	 * @return array<string, string> The filters.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function filledFields(SimpleXMLElement $fields, array $map): array {
		$criteria = [];
		foreach ($fields as $elName => $el) {
			if (isset($map[$elName]) === true && (string)$el !== '') {
				$criteria[$map[$elName]] = (string)$el;
			}
		}

		return $criteria;
	}//end filledFields()

	/**
	 * The BG children of the request element in the body.
	 *
	 * @param StufBgMessage $message The request.
	 * @param string        $request The request element, e.g. `npsLv01`.
	 *
	 * @return SimpleXMLElement|null Its children, or null without a BG namespace or that element.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function requestChildren(StufBgMessage $message, string $request): ?SimpleXMLElement {
		if ($message->bgNs === '') {
			return null;
		}

		$bodyKids = $message->body->children($message->bgNs);
		if (isset($bodyKids->{$request}) === false) {
			return null;
		}

		return $bodyKids->{$request}->children($message->bgNs);
	}//end requestChildren()

	/**
	 * The scope field list of a npsLv01 request.
	 *
	 * @param StufBgMessage $message The request.
	 *
	 * @return array|null Array of requested StUF field names, or null if no scope specified.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function scope(StufBgMessage $message): ?array {
		$requestKids = $this->requestChildren(message: $message, request: 'npsLv01');
		if ($requestKids === null || isset($requestKids->scope) === false) {
			return null;
		}

		$fields = $this->scopeFields(scope: $requestKids->scope, bgNs: $message->bgNs);
		if (empty($fields) === false) {
			return $fields;
		}

		return null;
	}//end scope()

	/**
	 * The names of the fields under the scope's object.
	 *
	 * @param SimpleXMLElement $scope The scope element.
	 * @param string           $bgNs  The BG namespace URI.
	 *
	 * @return array The field names, in order.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function scopeFields(SimpleXMLElement $scope, string $bgNs): array {
		$scopeKids = $scope->children($bgNs);
		if (isset($scopeKids->object) === false) {
			return [];
		}

		$fields = [];
		$objectChildren = $scopeKids->object->children($bgNs);
		for ($objectChildren->rewind(); $objectChildren->valid() === true; $objectChildren->next()) {
			$fields[] = $objectChildren->key();
		}

		return $fields;
	}//end scopeFields()

	/**
	 * The maximumAantal parameter of a StUF request.
	 *
	 * Read from the first StUF parameters element under the body's children.
	 *
	 * @param StufBgMessage $message The request.
	 *
	 * @return int Maximum number of records (at least 1), defaulting to 100.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function maximumCount(StufBgMessage $message): int {
		$params = $this->firstParameters(message: $message);
		if ($params === null) {
			return 100;
		}

		$stufParamKids = $params->children($message->stufNs);
		if (isset($stufParamKids->maximumAantal) === false || (string)$stufParamKids->maximumAantal === '') {
			return 100;
		}

		return max(1, (int)(string)$stufParamKids->maximumAantal);
	}//end maximumCount()

	/**
	 * The first StUF parameters element under the body's children.
	 *
	 * @param StufBgMessage $message The request.
	 *
	 * @return SimpleXMLElement|null The parameters, or null without a StUF namespace or parameters.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function firstParameters(StufBgMessage $message): ?SimpleXMLElement {
		if ($message->stufNs === '') {
			return null;
		}

		foreach ($message->body->children() as $child) {
			$stufKids = $child->children($message->stufNs);
			if (isset($stufKids->parameters) === true) {
				return $stufKids->parameters;
			}
		}

		return null;
	}//end firstParameters()

	/**
	 * The stuurgegevens with the request's referentienummer as crossRefnummer, when it has one.
	 *
	 * @param array         $stuurgegevens Stuurgegevens for the response.
	 * @param StufBgMessage $message       The request.
	 *
	 * @return array The stuurgegevens.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function withCrossRef(array $stuurgegevens, StufBgMessage $message): array {
		$crossRef = $this->crossRefnummer(message: $message);
		if ($crossRef !== null) {
			$stuurgegevens['crossRefnummer'] = $crossRef;
		}

		return $stuurgegevens;
	}//end withCrossRef()

	/**
	 * The first filled referentienummer in the stuurgegevens under the body's children.
	 *
	 * @param StufBgMessage $message The request.
	 *
	 * @return string|null The referentienummer, or null if not present.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function crossRefnummer(StufBgMessage $message): ?string {
		if ($message->stufNs === '') {
			return null;
		}

		foreach ($message->body->children() as $child) {
			$reference = $this->referentienummerIn(element: $child, stufNs: $message->stufNs);
			if ($reference !== null) {
				return $reference;
			}
		}

		return null;
	}//end crossRefnummer()

	/**
	 * The filled referentienummer of an element's stuurgegevens.
	 *
	 * @param SimpleXMLElement $element One of the body's children.
	 * @param string           $stufNs  The StUF namespace URI.
	 *
	 * @return string|null The referentienummer, or null when there is none or it is empty.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function referentienummerIn(SimpleXMLElement $element, string $stufNs): ?string {
		$stufKids = $element->children($stufNs);
		if (isset($stufKids->stuurgegevens) === false) {
			return null;
		}

		$sgKids = $stufKids->stuurgegevens->children($stufNs);
		if (isset($sgKids->referentienummer) === true && (string)$sgKids->referentienummer !== '') {
			return (string)$sgKids->referentienummer;
		}

		return null;
	}//end referentienummerIn()

	/**
	 * The antwoord element of a npsLa01 response.
	 *
	 * @param StufBgMessage $message The response.
	 *
	 * @return SimpleXMLElement|null The antwoord, or null without a BG namespace, npsLa01 or antwoord.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function npsLa01Answer(StufBgMessage $message): ?SimpleXMLElement {
		$la01Kids = $this->requestChildren(message: $message, request: 'npsLa01');
		if ($la01Kids === null || isset($la01Kids->antwoord) === false) {
			return null;
		}

		return $la01Kids->antwoord;
	}//end npsLa01Answer()

	/**
	 * Parse a single BG:object element from a npsLa01 response.
	 *
	 * A field StUF does not support (`noValue="nietOndersteund"`) is left out.
	 *
	 * @param SimpleXMLElement $obj     The BG:object element.
	 * @param StufBgMessage    $message The response.
	 *
	 * @return array OpenRegister-mapped person properties.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function parsePersonObject(SimpleXMLElement $obj, StufBgMessage $message): array {
		$stufData = [];
		foreach ($obj->children($message->bgNs) as $name => $el) {
			$noValue = (string)($el->attributes($message->stufNs)['noValue'] ?? '');
			if ($noValue === 'nietOndersteund') {
				continue;
			}

			if ($name === 'verblijfsadres') {
				$stufData['verblijfsadres'] = $this->addressFields(address: $el, bgNs: $message->bgNs);
				continue;
			}

			$stufData[$name] = $this->plainValue(element: $el, noValue: $noValue);
		}

		return $this->fieldMapper->mapStUFToPerson(stufData: $stufData);
	}//end parsePersonObject()

	/**
	 * The fields of a verblijfsadres, as strings.
	 *
	 * @param SimpleXMLElement $address The verblijfsadres element.
	 * @param string           $bgNs    The BG namespace URI.
	 *
	 * @return array<string, string> Field name => value.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function addressFields(SimpleXMLElement $address, string $bgNs): array {
		$adres = [];
		foreach ($address->children($bgNs) as $aName => $aEl) {
			$adres[$aName] = (string)$aEl;
		}

		return $adres;
	}//end addressFields()

	/**
	 * A field's value: null when it carries a noValue reason, else its text.
	 *
	 * @param SimpleXMLElement $element The field.
	 * @param string           $noValue Its noValue attribute, or empty.
	 *
	 * @return string|null The value.
	 *
	 * @spec openspec/specs/stuf-adapter/spec.md
	 */
	private function plainValue(SimpleXMLElement $element, string $noValue): ?string {
		if ($noValue !== '') {
			return null;
		}

		return (string)$element;
	}//end plainValue()

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
	 * Tries the namespace the document names soap first, then the SOAP 1.1
	 * and 1.2 envelope namespaces.
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
		$candidates = self::SOAP_ENVELOPE_NAMESPACES;
		if ($soapNs !== '') {
			array_unshift($candidates, $soapNs);
		}

		foreach ($candidates as $ns) {
			$body = $this->soapBodyIn(xml: $xml, soapNs: $ns);
			if ($body !== null) {
				return $body;
			}
		}

		return null;
	}//end extractSoapBody()

	/**
	 * The envelope's Body element in one SOAP namespace.
	 *
	 * @param SimpleXMLElement $xml    The parsed SOAP envelope.
	 * @param string           $soapNs The SOAP namespace URI to look in.
	 *
	 * @return SimpleXMLElement|null The Body, or null when the envelope has none in that namespace.
	 *
	 * @spec openspec/changes/stuf-adapter/tasks.md#task-1
	 */
	private function soapBodyIn(SimpleXMLElement $xml, string $soapNs): ?SimpleXMLElement {
		$kids = $xml->children($soapNs);
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
		if (isset($kids->{'Body'}) === true) {
			// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- SOAP XML element.
			return $kids->{'Body'};
		}

		return null;
	}//end soapBodyIn()

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
		return StufNamespaces::byHint(namespaces: $namespaces, hint: $hint);
	}//end resolveNamespace()
}//end class
