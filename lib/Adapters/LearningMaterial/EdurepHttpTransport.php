<?php

/**
 * Integriq Edurep HTTP transport.
 *
 * Sends SRU requests to Edurep's public endpoint with a ten second timeout.
 * Edurep is free and keyless, so the request carries no credentials.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Live Edurep SRU transport.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
 */
class EdurepHttpTransport implements SruTransport {
	public const ENDPOINT = 'https://wszoeken.edurep.kennisnet.nl/edurep/sruns';
	public const TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The HTTP client factory.
	 * @param EdurepStatusStore $status Remembers the last success and failure.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly EdurepStatusStore $status,
	) {
	}//end __construct()

	/**
	 * Send one request.
	 *
	 * @param array<string,string> $parameters The SRU query parameters.
	 *
	 * @return string The response body.
	 *
	 * @throws SourceUnavailableException On a timeout, a transport error or a status other than 200.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function fetch(array $parameters): string {
		$url = self::ENDPOINT . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
		try {
			$response = $this->clientService->newClient()->get($url, ['timeout' => self::TIMEOUT_SECONDS, 'http_errors' => false]);
		} catch (Throwable $error) {
			throw $this->unavailable(reason: $error->getMessage());
		}

		if ($response->getStatusCode() !== 200) {
			throw $this->unavailable(reason: 'HTTP ' . $response->getStatusCode());
		}

		$this->status->markSuccess();

		return (string)$response->getBody();
	}//end fetch()

	/**
	 * When the last request was answered.
	 *
	 * @return string|null ISO 8601 time, or null when none is known.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-search-maps-filters-to-cql-and-asks-for-facets
	 */
	public function lastSuccess(): ?string {
		return $this->status->lastSuccess();
	}//end lastSuccess()

	/**
	 * Record the failure and build the exception.
	 *
	 * @param string $reason Why the request failed.
	 *
	 * @return SourceUnavailableException The exception to throw.
	 */
	private function unavailable(string $reason): SourceUnavailableException {
		$this->status->markFailure(reason: $reason);

		return new SourceUnavailableException(source: LomRecordReader::SOURCE, lastSuccess: $this->status->lastSuccess());
	}//end unavailable()
}//end class
