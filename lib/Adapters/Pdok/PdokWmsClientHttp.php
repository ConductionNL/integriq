<?php

/**
 * Integriq PDOK WMS Client (HTTPS).
 *
 * Active implementation of {@see PdokWmsClient}. Issues real outbound GetMap
 * / GetCapabilities requests against `service.pdok.nl/{dataset}/wms/v2_0`.
 *
 * DI binds this class behind the `pdok.feature_flag === '1'` (or `'true'`)
 * app-config flag; while the flag is dormant the abstract resolves to
 * {@see PdokWmsClientMock} instead.
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
use Psr\Log\LoggerInterface;

/**
 * HTTPS PDOK WMS client — live default once `pdok.feature_flag` flips.
 */
final class PdokWmsClientHttp extends PdokWmsClient {

	/**
	 * Default PDOK WMS endpoint pattern.
	 *
	 * The dataset slug is interpolated at request time (PDOK hosts a separate
	 * WMS endpoint per dataset, e.g. `service.pdok.nl/lv/bag/wms/v2_0`,
	 * `service.pdok.nl/lv/bgt/wms/v1_0`).
	 */
	public const DEFAULT_BASE_URI = 'https://service.pdok.nl/lv/{dataset}/wms/v2_0';

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 15;

	/**
	 * Base URI template used for all WMS requests.
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
	 * @param string $dataset PDOK WMS dataset key.
	 *
	 * @return string The raw XML capabilities document.
	 */
	public function getCapabilities(string $dataset): string {
		return $this->request(
			dataset: $dataset,
			query: [
				'service' => 'WMS',
				'request' => 'GetCapabilities',
				'version' => '1.3.0',
			],
			expectedAccept: 'application/xml'
		);

	}//end getCapabilities()

	/**
	 * Render a GetMap PNG for the requested dataset, bbox and crs.
	 *
	 * @param string $dataset PDOK WMS dataset key.
	 * @param string $layer Layer name.
	 * @param array $bbox Bounding box (minx,miny,maxx,maxy).
	 * @param string $crs CRS identifier.
	 * @param int $width Pixel width.
	 * @param int $height Pixel height.
	 * @param string $format Image MIME type.
	 *
	 * @return string Raw image bytes.
	 */
	public function getMap(
		string $dataset,
		string $layer,
		array $bbox,
		string $crs = 'EPSG:28992',
		int $width = 512,
		int $height = 512,
		string $format = 'image/png',
	): string {
		$bboxString = implode(
			',',
			array_map(
				static fn ($coord): string => (string)(float)$coord,
				array_slice($bbox, 0, 4)
			)
		);

		return $this->request(
			dataset: $dataset,
			query: [
				'service' => 'WMS',
				'request' => 'GetMap',
				'version' => '1.3.0',
				'layers' => $layer,
				'styles' => '',
				'crs' => $crs,
				'bbox' => $bboxString,
				'width' => max(1, $width),
				'height' => max(1, $height),
				'format' => $format,
				'transparent' => 'true',
			],
			expectedAccept: $format
		);

	}//end getMap()

	/**
	 * Flavour identifier.
	 *
	 * @return string
	 */
	public function flavour(): string {
		return 'http';
	}//end flavour()

	/**
	 * Issue a WMS GET request and return the raw response body.
	 *
	 * @param string $dataset Dataset slug to interpolate.
	 * @param array<string,mixed> $query Query-string parameters.
	 * @param string $expectedAccept The expected response MIME type.
	 *
	 * @return string The raw response body (empty on transport failure).
	 */
	private function request(string $dataset, array $query, string $expectedAccept): string {
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
				'pdok-wms.http.transport',
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
	}//end request()
}//end class
