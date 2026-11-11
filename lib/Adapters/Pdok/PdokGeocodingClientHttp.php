<?php

/**
 * Integriq PDOK Geocoding Client (HTTPS).
 *
 * Active implementation of {@see PdokGeocodingClient}. Issues real outbound
 * requests to the PDOK Locatieserver v3.1 and normalises the responses into
 * the canonical PostalAddress shape documented in
 * `hydra/openspec/changes/shared-pdok-via-openconnector/design.md`.
 *
 * DI in {@see \OCA\Integriq\Adapters\AdaptersRegistrar} binds this
 * class behind the `pdok.feature_flag === '1'` (or `'true'`) app-config flag;
 * while the flag is dormant the abstract resolves to
 * {@see PdokGeocodingClientMock} instead.
 *
 * Locatieserver v3.1 endpoints (read-only, no auth, public):
 *   - GET /suggest?q=…&rows=…
 *   - GET /lookup?id=…
 *   - GET /reverse?lat=…&lon=…&rows=…
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Pdok
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Pdok;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTPS PDOK geocoding client — live default once `pdok.feature_flag` flips.
 */
final class PdokGeocodingClientHttp extends PdokGeocodingClient {

	/**
	 * Default Locatieserver base URL (public, read-only, HTTPS).
	 */
	public const DEFAULT_BASE_URI = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/';

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 10;

	/**
	 * Base URI used for all Locatieserver requests.
	 *
	 * @var string
	 */
	private string $baseUri;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface $httpClient The Guzzle HTTP client (injected for testability).
	 * @param LoggerInterface $logger Structured logger.
	 * @param string|null $baseUri Optional override of the Locatieserver base URI.
	 */
	public function __construct(
		private readonly ClientInterface $httpClient,
		private readonly LoggerInterface $logger,
		?string $baseUri = null,
	) {
		if ($baseUri !== null && $baseUri !== '') {
			$resolvedUri = $baseUri;
		} else {
			$resolvedUri = self::DEFAULT_BASE_URI;
		}

		$this->baseUri = rtrim($resolvedUri, '/') . '/';

	}//end __construct()

	/**
	 * Free-text autocomplete suggest call.
	 *
	 * @param string $query Free-text query.
	 * @param int $rows Maximum rows to return.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function suggest(string $query, int $rows = 10): array {
		$payload = $this->get(
			path: 'suggest',
			query: [
				'q' => $query,
				'rows' => max(1, min(50, $rows)),
				'fl' => 'id,weergavenaam,straatnaam,huisnummer,postcode,woonplaatsnaam,provincienaam,centroide_ll',
			]
		);

		return $this->normaliseDocs(payload: $payload);
	}//end suggest()

	/**
	 * A search that answers null when the Locatieserver gave no answer.
	 *
	 * @param string $query The query; the Locatieserver's field syntax is allowed.
	 * @param int $rows Maximum rows to return.
	 *
	 * @return array<int,array<string,mixed>>|null
	 *
	 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
	 */
	public function find(string $query, int $rows = 10): ?array {
		$payload = $this->request(
			path: 'suggest',
			query: [
				'q' => $query,
				'rows' => max(1, min(50, $rows)),
				'fl' => 'id,weergavenaam,straatnaam,huisnummer,postcode,woonplaatsnaam,'
					. 'provincienaam,centroide_ll,nummeraanduiding_id,adresseerbaarobject_id',
			]
		);

		$docs = ($payload['response']['docs'] ?? null);
		if ($payload === null || is_array($docs) === false) {
			return null;
		}

		return $this->normaliseDocs(payload: $payload);
	}//end find()

	/**
	 * Lookup a specific PDOK identifier.
	 *
	 * @param string $pdokId PDOK Locatieserver id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function lookup(string $pdokId): ?array {
		$payload = $this->get(
			path: 'lookup',
			query: [
				'id' => $pdokId,
				'fl' => 'id,weergavenaam,straatnaam,huisnummer,postcode,woonplaatsnaam,'
					. 'provincienaam,centroide_ll,nummeraanduiding_id,adresseerbaarobject_id',
			]
		);

		$docs = (array)($payload['response']['docs'] ?? []);
		$doc = ($docs[0] ?? null);
		if (is_array($doc) === false) {
			return null;
		}

		return $this->normaliseDoc(doc: $doc);
	}//end lookup()

	/**
	 * Reverse-geocode a lat/lng pair to candidate addresses.
	 *
	 * @param float $latitude Latitude (WGS84).
	 * @param float $longitude Longitude (WGS84).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function reverse(float $latitude, float $longitude): array {
		$payload = $this->get(
			path: 'reverse',
			query: [
				'lat' => $latitude,
				'lon' => $longitude,
				'fl' => 'id,weergavenaam,straatnaam,huisnummer,postcode,woonplaatsnaam,provincienaam,centroide_ll',
				'rows' => 10,
			]
		);

		return $this->normaliseDocs(payload: $payload);
	}//end reverse()

	/**
	 * Flavour identifier.
	 *
	 * @return string
	 */
	public function flavour(): string {
		return 'http';
	}//end flavour()

	/**
	 * Execute a GET request against the Locatieserver and decode the JSON body.
	 *
	 * @param string $path Relative path under the base URI.
	 * @param array<string,mixed> $query Query-string parameters.
	 *
	 * @return array<string,mixed> Decoded JSON payload.
	 */
	private function get(string $path, array $query): array {
		return ($this->request(path: $path, query: $query) ?? ['response' => ['docs' => []]]);
	}//end get()

	/**
	 * Execute a GET request against the Locatieserver, null when it gave no usable answer.
	 *
	 * @param string $path Relative path under the base URI.
	 * @param array<string,mixed> $query Query-string parameters.
	 *
	 * @return array<string,mixed>|null Decoded JSON payload, or null on a transport or decode failure.
	 */
	private function request(string $path, array $query): ?array {
		$uri = $this->baseUri . ltrim($path, '/');

		$response = $this->send(uri: $uri, query: $query);
		if ($response === null) {
			return null;
		}

		return $this->decode(uri: $uri, response: $response);
	}//end request()

	/**
	 * Send the GET request, logging and answering null on a transport failure.
	 *
	 * @param string $uri The full request URI.
	 * @param array<string,mixed> $query Query-string parameters.
	 *
	 * @return ResponseInterface|null The response, or null when it could not be fetched.
	 */
	private function send(string $uri, array $query): ?ResponseInterface {
		try {
			return $this->httpClient->request('GET', $uri, $this->requestOptions(query: $query));
		} catch (GuzzleException $e) {
			$this->logger->error(
				'pdok-geocoding.http.transport',
				[
					'uri' => $uri,
					'query' => $query,
					'message' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end send()

	/**
	 * The options of one Locatieserver request.
	 *
	 * @param array<string,mixed> $query Query-string parameters.
	 *
	 * @return array<string,mixed> The Guzzle options.
	 */
	private function requestOptions(array $query): array {
		return [
			'query' => $query,
			'timeout' => self::DEFAULT_TIMEOUT,
			'headers' => ['Accept' => 'application/json'],
		];
	}//end requestOptions()

	/**
	 * Decode the JSON body, logging and answering null when it is not a JSON object or list.
	 *
	 * @param string $uri The request URI, for the log line.
	 * @param ResponseInterface $response The response.
	 *
	 * @return array<string,mixed>|null The decoded payload, or null.
	 */
	private function decode(string $uri, ResponseInterface $response): ?array {
		$body = (string)$response->getBody();
		$decoded = json_decode($body, true);

		if (is_array($decoded) === false) {
			$this->logger->warning(
				'pdok-geocoding.http.decode',
				[
					'uri' => $uri,
					'bodySize' => strlen($body),
				]
			);
			return null;
		}

		return $decoded;
	}//end decode()

	/**
	 * Normalise every document in a Locatieserver answer, skipping entries that are not documents.
	 *
	 * @param array<string,mixed> $payload The decoded answer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function normaliseDocs(array $payload): array {
		$docs = (array)($payload['response']['docs'] ?? []);
		$out = [];
		foreach ($docs as $doc) {
			if (is_array($doc) === false) {
				continue;
			}

			$out[] = $this->normaliseDoc(doc: $doc);
		}

		return $out;
	}//end normaliseDocs()

	/**
	 * Normalise a Locatieserver `docs[]` entry into the canonical PostalAddress shape.
	 *
	 * @param array<string,mixed> $doc Raw Locatieserver document.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseDoc(array $doc): array {
		$coords = $this->parseCentroidLl(raw: ($doc['centroide_ll'] ?? null));

		$normalised = $this->addressFields(doc: $doc);

		if ($coords !== null) {
			$normalised['location'] = [
				'type' => 'Point',
				'coordinates' => $coords,
			];
		}

		return $normalised;
	}//end normaliseDoc()

	/**
	 * The PostalAddress fields of a document, without its location.
	 *
	 * @param array<string,mixed> $doc Raw Locatieserver document.
	 *
	 * @return array<string,mixed>
	 */
	private function addressFields(array $doc): array {
		return [
			'displayName' => (string)($doc['weergavenaam'] ?? ''),
			'streetAddress' => (string)($doc['straatnaam'] ?? ''),
			'houseNumber' => (string)($doc['huisnummer'] ?? ''),
			'postalCode' => (string)($doc['postcode'] ?? ''),
			'addressLocality' => (string)($doc['woonplaatsnaam'] ?? ''),
			'addressRegion' => (string)($doc['provincienaam'] ?? ''),
			'bagAddressId' => $this->optionalId(doc: $doc, key: 'nummeraanduiding_id'),
			'bagBuildingId' => $this->optionalId(doc: $doc, key: 'adresseerbaarobject_id'),
			'pdokId' => $this->optionalId(doc: $doc, key: 'id'),
			'source' => 'pdok',
		];
	}//end addressFields()

	/**
	 * An identifier of the document as a string, or null when it has none.
	 *
	 * @param array<string,mixed> $doc Raw Locatieserver document.
	 * @param string $key The identifier's field.
	 *
	 * @return string|null The identifier.
	 */
	private function optionalId(array $doc, string $key): ?string {
		if (isset($doc[$key]) === true) {
			return (string)$doc[$key];
		}

		return null;
	}//end optionalId()

	/**
	 * Parse the Locatieserver `centroide_ll` WKT POINT into `[lng, lat]` coords.
	 *
	 * Locatieserver returns the geometry as `POINT(<lng> <lat>)`.
	 *
	 * @param mixed $raw The raw `centroide_ll` value.
	 *
	 * @return array{0:float,1:float}|null Decoded `[lng, lat]`, or null if unparseable.
	 */
	private function parseCentroidLl(mixed $raw): ?array {
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		if (preg_match('/POINT\(([-+]?\d+(?:\.\d+)?)\s+([-+]?\d+(?:\.\d+)?)\)/i', $raw, $matches) !== 1) {
			return null;
		}

		return [(float)$matches[1], (float)$matches[2]];
	}//end parseCentroidLl()
}//end class
