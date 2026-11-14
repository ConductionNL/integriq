<?php

/**
 * Integriq supplier source call.
 *
 * The one HTTP path the live supplier clients share: find the supplier's
 * source row by slug, call it through CallService (so its address,
 * brokered credential, egress checks and call log apply), refuse a non-2xx
 * answer, and decode the JSON body. Onboarding a supplier stays
 * configuration: a source row with the supplier's address and credential.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Support
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
 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Support;

use OCA\Integriq\Service\CallServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * Calls a supplier's source row and returns its decoded JSON answer.
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
 */
class SupplierSourceCall {

	/**
	 * Constructor.
	 *
	 * @param CallServiceInterface $callService     Integriq's outbound HTTP surface.
	 * @param OrObjectService      $orObjectService Finds the supplier's source row.
	 */
	public function __construct(
		private readonly CallServiceInterface $callService,
		private readonly OrObjectService $orObjectService,
	) {
	}//end __construct()

	/**
	 * Call one endpoint of a supplier source and decode its JSON answer.
	 *
	 * @param string              $slug           The supplier source's slug.
	 * @param string              $endpoint       The path below the source's location.
	 * @param string              $method         The HTTP method.
	 * @param array<string,mixed> $config         Request options (query, json, headers).
	 * @param bool                $withholdBodies True to keep the request and answer bodies out of the call log.
	 *
	 * @return array<mixed> The decoded answer.
	 *
	 * @throws SupplierCallException When the source is missing, the call fails, the status is not 2xx or the body is not JSON.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
	 */
	public function json(string $slug, string $endpoint, string $method = 'GET', array $config = [], bool $withholdBodies = false): array {
		$source = $this->source(slug: $slug);
		$endpoint = '/' . ltrim($endpoint, '/');
		$headers = ($config['headers'] ?? []);
		if (is_array($headers) === false) {
			$headers = [];
		}

		$config['headers'] = array_merge(['Accept' => 'application/json'], $headers);
		$config['logBody'] = ($withholdBodies === false);

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $method,
				config: $config,
				withholdBodies: $withholdBodies
			);
		} catch (Throwable $exception) {
			throw new SupplierCallException(
				message: sprintf('The call to %s %s failed: %s', $slug, $endpoint, $exception->getMessage()),
				previous: $exception
			);
		}

		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? ($data['response']['statusCode'] ?? 0));
		if ($status < 200 || $status >= 300) {
			throw new SupplierCallException(
				message: sprintf('%s answered %s with status %d.', $slug, $endpoint, $status),
				status: $status
			);
		}

		$body = ($data['response']['body'] ?? null);
		if (is_string($body) === true) {
			$body = json_decode($body, true);
		}

		if (is_array($body) === false) {
			throw new SupplierCallException(message: sprintf('%s answered %s without a JSON body.', $slug, $endpoint), status: $status);
		}

		return $body;
	}//end json()

	/**
	 * The supplier's source row.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return ObjectEntity The source.
	 *
	 * @throws SupplierCallException When no source has the slug.
	 */
	private function source(string $slug): ObjectEntity {
		$result = $this->orObjectService->findAll(
			config: ['filters' => ['register' => 'integriq', 'schema' => 'source', 'slug' => $slug]]
		);

		foreach ((array)($result['results'] ?? $result) as $item) {
			if ($item instanceof ObjectEntity && ($item->getObject()['slug'] ?? '') === $slug) {
				return $item;
			}
		}

		throw new SupplierCallException(
			message: sprintf('No source with slug %s is in register integriq. Add one with the supplier\'s address and credential.', $slug)
		);
	}//end source()
}//end class
