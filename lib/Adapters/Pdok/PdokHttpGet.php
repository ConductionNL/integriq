<?php

/**
 * Integriq PDOK HTTP GET.
 *
 * The GET the PDOK WFS and WMS clients share: interpolate the dataset into
 * the endpoint pattern, send the query with the default timeout and the
 * expected Accept header, and answer the raw body, or an empty body after
 * logging a transport failure.
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
 * Sends one PDOK GET and answers its raw body.
 *
 * @spec openspec/specs/pdok-adapter/spec.md#requirement-pdok-connector-class
 */
final class PdokHttpGet {

	/**
	 * Default request timeout in seconds.
	 */
	public const DEFAULT_TIMEOUT = 15;

	/**
	 * Constructor.
	 *
	 * @param ClientInterface $httpClient   The Guzzle HTTP client.
	 * @param LoggerInterface $logger       Structured logger.
	 * @param string          $baseUri      Endpoint pattern containing `{dataset}`.
	 * @param string          $failureEvent The log event of a transport failure.
	 */
	public function __construct(
		private readonly ClientInterface $httpClient,
		private readonly LoggerInterface $logger,
		private readonly string $baseUri,
		private readonly string $failureEvent,
	) {
	}//end __construct()

	/**
	 * Issue the GET and return the raw response body.
	 *
	 * @param PdokGetRequest $request The dataset, query and expected response type.
	 *
	 * @return string The raw response body (empty on transport failure).
	 *
	 * @spec openspec/specs/pdok-adapter/spec.md#requirement-pdok-connector-class
	 */
	public function body(PdokGetRequest $request): string {
		$uri = str_replace('{dataset}', rawurlencode($request->dataset), $this->baseUri);

		try {
			$response = $this->httpClient->request(
				'GET',
				$uri,
				[
					'query' => $request->query,
					'timeout' => self::DEFAULT_TIMEOUT,
					'headers' => ['Accept' => $request->expectedAccept],
				]
			);
		} catch (GuzzleException $e) {
			$this->logger->error(
				$this->failureEvent,
				[
					'uri' => $uri,
					'dataset' => $request->dataset,
					'request' => ($request->query['request'] ?? null),
					'message' => $e->getMessage(),
				]
			);
			return '';
		}//end try

		return (string)$response->getBody();
	}//end body()
}//end class
