<?php

/**
 * Integriq Service Fetch Catalogue.
 *
 * Reads service fetches and their sources in system context, and answers
 * the questions about a fetch that do not need a call: is it enabled, may
 * this app call it, and which fetches may an app list.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ServiceFetch;

use OCA\Integriq\Event\ServiceFetchRequestedEvent;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * The service fetches integriq holds.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001
 */
class ServiceFetchCatalogue {

	/**
	 * The register every object involved lives in.
	 */
	private const REGISTER = 'integriq';

	/**
	 * The schema of a service fetch.
	 */
	public const SCHEMA = 'serviceFetch';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The enabled fetches an app may call, without source, path, query or mappings.
	 *
	 * @param string $app The app id.
	 *
	 * @return list<array{slug: string, title: string, description: string, inputs: array, outputs: array}>
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003
	 */
	public function listFor(string $app): array {
		$listed = [];
		foreach ($this->allFetches() as $fetch) {
			if ($this->isEnabled(fetch: $fetch) === false || $this->allows(fetch: $fetch, app: $app) === false) {
				continue;
			}

			$listed[] = [
				'slug' => (string)($fetch['slug'] ?? ''),
				'title' => (string)($fetch['title'] ?? ''),
				'description' => (string)($fetch['description'] ?? ''),
				'inputs' => $this->listOf(value: ($fetch['inputs'] ?? [])),
				'outputs' => $this->listOf(value: ($fetch['outputs'] ?? [])),
			];
		}

		usort($listed, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

		return $listed;
	}//end listFor()

	/**
	 * The fetch with this slug, read in system context.
	 *
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function fetchBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		foreach ($this->allFetches(filters: ['slug' => $slug]) as $fetch) {
			if (($fetch['slug'] ?? null) === $slug) {
				return $fetch;
			}
		}

		return null;
	}//end fetchBySlug()

	/**
	 * Every service fetch, or those matching the filters, read in system context.
	 *
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function allFetches(array $filters = []): array {
		$found = $this->objectService->findAll(
			config: ['filters' => (['register' => self::REGISTER, 'schema' => self::SCHEMA] + $filters), 'limit' => 1000],
			_rbac: false
		);

		$results = ($found['results'] ?? $found);
		$fetches = [];
		foreach ((array)$results as $result) {
			if ($result instanceof ObjectEntity) {
				$fetches[] = $result->getObject();
			} else if (is_array($result) === true) {
				$fetches[] = $result;
			}
		}

		return $fetches;
	}//end allFetches()

	/**
	 * The fetch's source, read in system context.
	 *
	 * @param string $id The source id.
	 *
	 * @return ObjectEntity
	 *
	 * @throws ServiceFetchRefusal When the source cannot be read.
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function source(string $id): ObjectEntity {
		try {
			$source = $this->objectService->find(id: $id, register: self::REGISTER, schema: 'source', _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$source = null;
		}

		if ($id === '' || ($source instanceof ObjectEntity) === false) {
			throw new ServiceFetchRefusal(ServiceFetchRequestedEvent::SOURCE_UNAVAILABLE);
		}

		return $source;
	}//end source()

	/**
	 * Whether the fetch is enabled; a fetch without the flag is.
	 *
	 * @param array<string, mixed> $fetch The fetch.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function isEnabled(array $fetch): bool {
		return ($fetch['enabled'] ?? true) !== false;
	}//end isEnabled()

	/**
	 * Whether the app is in the fetch's allowed apps. Empty allows none.
	 *
	 * @param array<string, mixed> $fetch The fetch.
	 * @param string               $app   The app id.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function allows(array $fetch, string $app): bool {
		return $app !== '' && in_array($app, (array)($fetch['allowedApps'] ?? []), true) === true;
	}//end allows()

	/**
	 * A list of objects, or an empty list for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, 'is_array'));
	}//end listOf()
}//end class
