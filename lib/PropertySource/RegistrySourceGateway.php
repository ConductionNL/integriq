<?php

/**
 * The one way a property-source binding reaches its registry.
 *
 * @category Service
 * @package  OCA\Integriq\PropertySource
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

namespace OCA\Integriq\PropertySource;

use OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException;
use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A binding never opens its own HTTP connection. It asks here, and this goes
 * through the configured source and the shared call service, per ADR-005 and
 * ADR-011.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-bag-brp-and-kvk-bind-to-sources-that-already-exist-req-rfs-006
 */
class RegistrySourceGateway {
	/**
	 * Constructor.
	 *
	 * @param ConnectionStore $connectionStore Source lookup by slug.
	 * @param CallService $callService The shared outbound call engine.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly ConnectionStore $connectionStore,
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read from a configured source.
	 *
	 * @param string $providerId Provider id asking, for the failure message.
	 * @param string $sourceSlug Slug of the configured source.
	 * @param string $endpoint Endpoint on that source.
	 * @param array<string,mixed> $query Query parameters.
	 *
	 * @return array<string,mixed> Decoded response body.
	 *
	 * @throws MissingSourceConfigurationException When no source is configured under the slug.
	 * @throws SourceUnreachableException When the source did not answer with a usable body.
	 */
	public function read(string $providerId, string $sourceSlug, string $endpoint, array $query = []): array {
		$source = $this->connectionStore->findSourceBySlug(slug: $sourceSlug);
		if ($source instanceof ObjectEntity === false) {
			throw new MissingSourceConfigurationException($providerId, $sourceSlug);
		}

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: 'GET',
				config: ['query' => $query]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'property-source.gateway.transport',
				[
					'provider' => $providerId,
					'source' => $sourceSlug,
					'error' => $e->getMessage(),
				]
			);
			throw new SourceUnreachableException($providerId, sprintf('Source "%s" did not answer: %s', $sourceSlug, $e->getMessage()));
		}

		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? 0);
		if ($status < 200 || $status > 299) {
			throw new SourceUnreachableException(
				$providerId,
				sprintf('Source "%s" answered HTTP %d.', $sourceSlug, $status)
			);
		}

		$body = ($data['response']['body'] ?? null);
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			throw new SourceUnreachableException(
				$providerId,
				sprintf('Source "%s" answered a body this binding cannot read.', $sourceSlug)
			);
		}

		return $body;
	}//end read()
}//end class
