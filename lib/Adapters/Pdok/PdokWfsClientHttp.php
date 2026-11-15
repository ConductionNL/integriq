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
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Pdok;

use GuzzleHttp\ClientInterface;
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
	public const DEFAULT_TIMEOUT = PdokHttpGet::DEFAULT_TIMEOUT;

	/**
	 * The GET every WFS request goes through.
	 *
	 * @var PdokHttpGet
	 */
	private readonly PdokHttpGet $get;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface $httpClient The Guzzle HTTP client.
	 * @param LoggerInterface $logger Structured logger.
	 * @param string|null $baseUri Optional override of the base URI template (must contain `{dataset}`).
	 */
	public function __construct(
		ClientInterface $httpClient,
		private readonly LoggerInterface $logger,
		?string $baseUri = null,
	) {
		$template = self::DEFAULT_BASE_URI;
		if ($baseUri !== null && $baseUri !== '') {
			$template = $baseUri;
		}

		$this->get = new PdokHttpGet(httpClient: $httpClient, logger: $logger, baseUri: $template, failureEvent: 'pdok-wfs.http.transport');

	}//end __construct()

	/**
	 * Return the GetCapabilities XML for the requested dataset.
	 *
	 * @param string $dataset PDOK WFS dataset key.
	 *
	 * @return string The raw XML capabilities document.
	 */
	public function getCapabilities(string $dataset): string {
		return $this->get->body(request: new PdokGetRequest(
			dataset: $dataset,
			query: [
				'service' => 'WFS',
				'request' => 'GetCapabilities',
				'version' => '2.0.0',
			],
			expectedAccept: 'application/xml'
		));

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
		return $this->get->body(request: new PdokGetRequest(
			dataset: $dataset,
			query: [
				'service' => 'WFS',
				'request' => 'DescribeFeatureType',
				'version' => '2.0.0',
				'typeNames' => $featureType,
			],
			expectedAccept: 'application/xml'
		));

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
	 *
	 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
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
		$query += $this->bboxParameter(bbox: $bbox, srsName: $srsName);
		if ($filterFields !== []) {
			$query['filter'] = $this->buildOgcFilter(filterFields: $filterFields);
		}

		$body = $this->get->body(request: new PdokGetRequest(dataset: $dataset, query: $query, expectedAccept: 'application/json'));

		return $this->featureCollection(body: $body, logContext: ['dataset' => $dataset, 'featureType' => $featureType]);
	}//end getFeature()

	/**
	 * The `bbox` parameter for four coordinates, suffixed with the CRS; none otherwise.
	 *
	 * @param array|null $bbox    Bounding box (minx,miny,maxx,maxy), or null.
	 * @param string     $srsName The CRS.
	 *
	 * @return array<string,string>
	 */
	private function bboxParameter(?array $bbox, string $srsName): array {
		if ($bbox === null || count($bbox) !== 4) {
			return [];
		}

		return [
			'bbox' => implode(
				',',
				[
					(string)(float)$bbox[0],
					(string)(float)$bbox[1],
					(string)(float)$bbox[2],
					(string)(float)$bbox[3],
					$srsName,
				]
			),
		];
	}//end bboxParameter()

	/**
	 * The decoded GeoJSON FeatureCollection, or an empty one marked unanswered.
	 *
	 * `unanswered` tells a caller that decides on the answer that this
	 * empty collection is a failure, not "nothing matched".
	 *
	 * @param string              $body       The raw response body.
	 * @param array<string,mixed> $logContext The dataset and feature type, for the decode warning.
	 *
	 * @return array<string,mixed>
	 */
	private function featureCollection(string $body, array $logContext): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		$this->logger->warning('pdok-wfs.http.decode', $logContext + ['bodySize' => strlen($body)]);

		return [
			'type' => 'FeatureCollection',
			'features' => [],
			'totalFeatures' => 0,
			'numberMatched' => 0,
			'numberReturned' => 0,
			'unanswered' => true,
		];
	}//end featureCollection()

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

}//end class
