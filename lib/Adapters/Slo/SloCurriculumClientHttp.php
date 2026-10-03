<?php

/**
 * Integriq SLO curriculum client (live).
 *
 * Live flavour of {@see SloCurriculumClient}. Calls SLO through integriq's
 * `CallService` with the seeded `slo-curriculum` source, so the source's
 * credentials (the registered e-mail as `username`, the API key as the
 * write-only `password`, or a credential broker `credentialRef`), its rate
 * limit, the call log and the circuit breaker all apply. DI binds this class
 * only when `slo.curriculum.feature_flag` is `1` or `true`.
 *
 * The body is kept on the call log (`logBody`): SLO's curriculum is public
 * reference data, and the adapter needs the body.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * Live SLO client through CallService and the seeded source.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */
final class SloCurriculumClientHttp extends SloCurriculumClient {
	/**
	 * The resolved source, cached for the request.
	 *
	 * @var ObjectEntity|null
	 */
	private ?ObjectEntity $source = null;

	/**
	 * Constructor.
	 *
	 * @param CallService $callService Integriq's outbound HTTP surface.
	 * @param OrObjectService $orObjectService OpenRegister object service, to resolve the seeded source.
	 */
	public function __construct(
		private readonly CallService $callService,
		private readonly OrObjectService $orObjectService,
	) {
	}//end __construct()

	/**
	 * Flavour identifier.
	 *
	 * @return string Always `https`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function flavour(): string {
		return 'https';
	}//end flavour()

	/**
	 * GET one path through the seeded source.
	 *
	 * @param string $path Path under the API base.
	 * @param array<string,scalar> $query Query parameters.
	 * @param string $accept The Accept header.
	 *
	 * @return string The response body.
	 *
	 * @throws SloCurriculumException When the source is missing, the call fails or SLO answers an error status.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function fetch(string $path, array $query = [], string $accept = 'application/json'): string {
		$source = $this->source();
		$endpoint = '/' . ltrim($path, '/');

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: $endpoint,
				method: 'GET',
				config: ['query' => $query, 'headers' => ['Accept' => $accept], 'logBody' => true]
			);
		} catch (Throwable $exception) {
			throw new SloCurriculumException(
				message: sprintf('The call to SLO %s failed: %s', $endpoint, $exception->getMessage()),
				previous: $exception
			);
		}

		$data = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? ($data['response']['statusCode'] ?? 0));
		if ($status < 200 || $status >= 300) {
			throw new SloCurriculumException(
				message: sprintf('SLO answered %s with status %d.', $endpoint, $status),
				status: $status
			);
		}

		$body = ($data['response']['body'] ?? null);
		if (is_array($body) === true) {
			return (string)json_encode($body, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		if (is_string($body) === false) {
			throw new SloCurriculumException(message: sprintf('SLO answered %s without a body.', $endpoint), status: $status);
		}

		return $body;
	}//end fetch()

	/**
	 * Resolve the seeded `slo-curriculum` source once.
	 *
	 * @return ObjectEntity The source.
	 *
	 * @throws SloCurriculumException When no such source exists.
	 */
	private function source(): ObjectEntity {
		if ($this->source !== null) {
			return $this->source;
		}

		$slug = SloCurriculumPresetRegistry::SOURCE_SLUG;
		$result = $this->orObjectService->findAll(
			config: ['filters' => ['register' => 'integriq', 'schema' => 'source', 'slug' => $slug]]
		);
		$items = ($result['results'] ?? $result);

		foreach ((array)$items as $item) {
			if ($item instanceof ObjectEntity && ($item->getObject()['slug'] ?? '') === $slug) {
				$this->source = $item;
				return $item;
			}
		}

		throw new SloCurriculumException(
			message: sprintf('The %s source is not in register integriq. Re-run the app install so register.d seeds it.', $slug)
		);
	}//end source()
}//end class
