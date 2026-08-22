<?php

/**
 * Integriq PDOK WFS Client (HTTPS).
 *
 * Active implementation of {@see PdokWfsClient}. Issues real outbound
 * GetCapabilities / DescribeFeatureType / GetFeature requests against
 * `service.pdok.nl/{dataset}/wfs/v2_0`.
 *
 * DI binds this class behind the `pdok.feature_flag === '1'` (or `'true'`)
 * app-config flag; while the flag is dormant the abstract resolves to
 * {@see PdokWfsClientMock} instead.
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
 * @link https://www.openconnector.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Pdok;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * HTTPS PDOK WFS client — live default once `pdok.feature_flag` flips.
 */
final class PdokWfsClientHttp extends PdokWfsClient {

	/**
	 * Default PDOK WFS endpoint pattern.
	 *
	 * The dataset slug is interpolated at request time.
	 */
	public const DEFAULT_BASE_URI = 'https://service.pdok.nl/lv/{dataset}/wfs/v2_0';

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 15;

	/**
	 * Base URI template used for all WFS requests.
	 *
	 * @var string
	 */
	private string $baseUri;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface $httpClient The Guzzle HTTP client.
	 * @param LoggerInterface $logger Structured logger.
	 * @param string|null $baseUri Optional override of the base URI template (must contain `{dataset}`).
	 */
	public function __construct(
		private readonly ClientInterface $httpClient,
		private readonly LoggerInterface $logger,
		?string $baseUri = null,
	) {
		if ($baseUri !== null && $baseUri !== '') {
			$this->baseUri = $baseUri;
		} else {
			$this->baseUri = self::DEFAULT_BASE_URI;
		}

	}//end __construct()

	/**
	 * Return the GetCapabilities XML for the requested dataset.
	 *
	 * @param string $dataset PDOK WFS dataset key.
	 *
	 * @return string The raw XML capabilities document.
	 */
	public function getCapabilities(string $dataset): string {
		return $this->requestRaw(
			dataset: $dataset,
			query: [
				'service' => 'WFS',
				'request' => 'GetCapabilities',
				'version' => '2.0.0',
			],
			expectedAccept: 'application/xml'
		);

	}//end getCapabilities()

	/**
	 * Return the DescribeFeatureType XML for a specific feature type.
	 *
	 * @param string $dataset PDOK WFS dataset key.
	 * @param string $featureType Feature type name.
	 *
	 * @return string The raw XML feature-type schema.
	 */
	public function describeFeatureType(string $dataset, string $featureType): string {
		return $this->requestRaw(
			dataset: $dataset,
			query: [
				'service' => 'WFS',
				'request' => 'DescribeFeatureType',
				'version' => '2.0.0',
				'typeNames' => $featureType,
			],
			expectedAccept: 'application/xml'
		);

	}//end describeFeatureType()

	/**
	 * Execute a GetFeature request and return the result as a GeoJSON FeatureCollection.
	 *
	 * @param string $dataset PDOK WFS dataset key.
	 * @param string $featureType Feature type name.
	 * @param array|null $bbox Bounding box (minx,miny,maxx,maxy) — optional.
	 * @param int $count Maximum features.
	 * @param string $srsName CRS.
	 * @param array $filterFields Optional name=value filter pairs.
	 *
	 * @return array<string,mixed> Parsed GeoJSON FeatureCollection.
	 */
	public function getFeature(
		string $dataset,
		string $featureType,
		?array $bbox = null,
		int $count = 10,
		string $srsName = 'EPSG:28992',
		array $filterFields = [],
	): array {
		$query = [
			'service' => 'WFS',
			'request' => 'GetFeature',
			'version' => '2.0.0',
			'typeNames' => $featureType,
			'outputFormat' => 'application/json',
			'count' => max(1, $count),
			'srsName' => $srsName,
		];

		if ($bbox !== null && count($bbox) === 4) {
			$query['bbox'] = implode(
				',',
				[
					(string)(float)$bbox[0],
					(string)(float)$bbox[1],
					(string)(float)$bbox[2],
					(string)(float)$bbox[3],
					$srsName,
				]
			);
		}

		if ($filterFields !== []) {
			$query['filter'] = $this->buildOgcFilter(filterFields: $filterFields);
		}

		$body = $this->requestRaw(dataset: $dataset, query: $query, expectedAccept: 'application/json');
		$decoded = json_decode($body, true);

		if (is_array($decoded) === false) {
			$this->logger->warning(
				'pdok-wfs.http.decode',
				[
					'dataset' => $dataset,
					'featureType' => $featureType,
					'bodySize' => strlen($body),
				]
			);
			return [
				'type' => 'FeatureCollection',
				'features' => [],
				'totalFeatures' => 0,
				'numberMatched' => 0,
				'numberReturned' => 0,
			];
		}

		return $decoded;
	}//end getFeature()

	/**
	 * Flavour identifier.
	 *
	 * @return string
	 */
	public function flavour(): string {
		return 'http';
	}//end flavour()

	/**
	 * Build a minimal OGC Filter Encoding 2.0 fragment for the given equality pairs.
	 *
	 * @param array<string,string> $filterFields name=value equality pairs.
	 *
	 * @return string Compact XML filter fragment.
	 */
	private function buildOgcFilter(array $filterFields): string {
		$parts = [];
		foreach ($filterFields as $name => $value) {
			$safeName = htmlspecialchars((string)$name, ENT_XML1 | ENT_COMPAT, 'UTF-8');
			$safeValue = htmlspecialchars((string)$value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
			$parts[] = '<fes:PropertyIsEqualTo xmlns:fes="http://www.opengis.net/fes/2.0">'
				. '<fes:ValueReference>' . $safeName . '</fes:ValueReference>'
				. '<fes:Literal>' . $safeValue . '</fes:Literal>'
				. '</fes:PropertyIsEqualTo>';
		}

		if (count($parts) === 1) {
			return '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0">' . $parts[0] . '</fes:Filter>';
		}

		return '<fes:Filter xmlns:fes="http://www.opengis.net/fes/2.0"><fes:And>' . implode('', $parts) . '</fes:And></fes:Filter>';
	}//end buildOgcFilter()

	/**
	 * Issue a WFS GET request and return the raw response body.
	 *
	 * @param string $dataset Dataset slug to interpolate.
	 * @param array<string,mixed> $query Query-string parameters.
	 * @param string $expectedAccept The expected response MIME type.
	 *
	 * @return string The raw response body (empty on transport failure).
	 */
	private function requestRaw(string $dataset, array $query, string $expectedAccept): string {
		$uri = str_replace('{dataset}', rawurlencode($dataset), $this->baseUri);

		try {
			$response = $this->httpClient->request(
				'GET',
				$uri,
				[
					'query' => $query,
					'timeout' => self::DEFAULT_TIMEOUT,
					'headers' => ['Accept' => $expectedAccept],
				]
			);
		} catch (GuzzleException $e) {
			$this->logger->error(
				'pdok-wfs.http.transport',
				[
					'uri' => $uri,
					'dataset' => $dataset,
					'request' => ($query['request'] ?? null),
					'message' => $e->getMessage(),
				]
			);
			return '';
		}//end try

		return (string)$response->getBody();
	}//end requestRaw()
}//end class
