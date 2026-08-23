<?php

/**
 * Integriq FSC Call Service.
 *
 * Core of the fsc-connectivity change: resolves the configured FSC
 * (Federatieve Service Connectiviteit) source + provider binding, drives
 * `callService()` (resolve an organisation+service via the directory,
 * cache the resolution as an `fsc_service` record, dispatch the call via
 * the provider, persist an `fsc_call` audit record either way), and
 * `listResolvableServices()` (a read of the cache for sibling-app
 * discovery). Mirrors {@see IwmoIjwSyncService} (provider seam + per-attempt
 * persistence) and {@see KissSyncService} (single active source resolution).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/fsc-connectivity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use OCA\Integriq\Exception\FscConnectivityException;
use OCA\Integriq\Exception\FscDirectoryException;
use OCA\Integriq\Service\Fsc\FscConnectivityProviderInterface;
use OCA\Integriq\Service\Fsc\FscDirectoryClient;
use OCA\Integriq\Service\Fsc\LogFscConnectivityProvider;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Drives the FSC directory-resolve-then-call path plus its persistence/observability.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/fsc-connectivity/spec.md
 */
class FscCallService {

	/**
	 * OpenRegister register slug holding FSC sources, service cache, and call log records.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	public const REGISTER = 'openconnector';

	/**
	 * OR schema slug for an FSC source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a cached `fsc_service` directory resolution.
	 *
	 * @var string
	 */
	public const SCHEMA_SERVICE = 'fsc_service';

	/**
	 * OR schema slug for an `fsc_call` audit record.
	 *
	 * @var string
	 */
	public const SCHEMA_CALL = 'fsc_call';

	/**
	 * `source.type` value identifying an FSC source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'fsc';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/cache/log persistence.
	 * @param LogFscConnectivityProvider $logProvider The sandbox provider binding.
	 * @param FscDirectoryClient $restProvider The generic REST provider binding.
	 * @param RawSourceResolver $rawSourceResolver Re-resolves the located source raw (ocon#242).
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogFscConnectivityProvider $logProvider,
		private readonly FscDirectoryClient $restProvider,
		private readonly RawSourceResolver $rawSourceResolver,
	) {

	}//end __construct()

	/**
	 * Resolve an organisation+service via the directory and dispatch one call.
	 *
	 * @param array $input The call payload: `organisation`, `service`, optional `method`
	 *                     (defaults `POST`), optional `payload` (defaults `[]`).
	 *
	 * @return array{ref: string, statusCode: int, body: mixed} The transport outcome.
	 *
	 * @throws FscConnectivityException When no active source is configured, or a
	 *                                  transport/config failure occurs (a `status: failed`
	 *                                  `fsc_call` IS persisted first).
	 * @throws FscDirectoryException When the organisation/service cannot be resolved
	 *                               (no `fsc_call` record is persisted — nothing was attempted).
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-call-routing-through-the-provider-seam-req-003
	 */
	public function callService(array $input): array {
		$source = $this->resolveActiveSource();
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);
		$directoryConf = ($configuration['directory'] ?? []);

		$organisation = (string)($input['organisation'] ?? '');
		$service = (string)($input['service'] ?? '');
		$method = strtoupper((string)($input['method'] ?? 'POST'));
		$payload = [];
		if (is_array($input['payload'] ?? null) === true) {
			$payload = $input['payload'];
		}

		// Resolution failures propagate WITHOUT persisting an fsc_call record —
		// nothing was actually attempted against a routable endpoint yet.
		$resolution = $provider->resolveService(
			directoryConfig: $directoryConf,
			organisation: $organisation,
			service: $service
		);

		$this->cacheResolution(resolution: $resolution, provider: $provider);

		$status = 'sent';
		$error = null;
		$result = ['ref' => '', 'statusCode' => 0, 'body' => null];
		try {
			$result = $provider->call(
				directoryConfig: $directoryConf,
				resolution: $resolution,
				method: $method,
				payload: $payload
			);
		} catch (FscConnectivityException $exception) {
			$status = 'failed';
			$error = $exception->getMessage();
		}

		$persistedRef = $result['ref'];
		if ($persistedRef === '') {
			$persistedRef = null;
		}

		$this->objectService->saveObject(
			object: [
				'organisation' => $organisation,
				'service' => $service,
				'method' => $method,
				'status' => $status,
				'ref' => $persistedRef,
				'error' => $error,
				'syncedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_CALL
		);

		if ($status === 'failed') {
			throw new FscConnectivityException(message: (string)$error);
		}

		return $result;
	}//end callService()

	/**
	 * List the current `fsc_service` directory cache for the active source.
	 *
	 * Never throws — an unconfigured instance simply has nothing cached,
	 * which is not an error condition for a read (mirrors "unconfigured ->
	 * clean not-configured, no HTTP" but for a list endpoint that is
	 * naturally empty rather than naturally failing).
	 *
	 * @return array<int, array<string, mixed>> The cached resolutions.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#scenario-listing-services-when-unconfigured-returns-an-empty-list-not-an-error
	 */
	public function listResolvableServices(): array {
		try {
			$this->resolveActiveSource();
		} catch (FscConnectivityException) {
			return [];
		}

		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_SERVICE,
				],
			],
		);
		$results = ($matches['results'] ?? $matches);

		return array_map(static fn (ObjectEntity $entity): array => $entity->getObject(), $results);
	}//end listResolvableServices()

	/**
	 * Resolve the single active FSC source (`type=fsc`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws FscConnectivityException When no active FSC source is configured.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#scenario-no-active-source-produces-a-clean-not-configured-failure
	 */
	public function resolveActiveSource(): ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_SOURCE,
					'type' => self::SOURCE_TYPE,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			throw new FscConnectivityException(
				message: 'No active FSC source is configured (register "openconnector", schema "source", '
					. 'type "fsc", isEnabled=true). Configure one before using FSC connectivity.'
			);
		}

		return $this->rawSourceResolver->resolveRaw(source: $results[0]);
	}//end resolveActiveSource()

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The FSC source's `configuration` object.
	 *
	 * @return FscConnectivityProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): FscConnectivityProviderInterface {
		$provider = ($configuration['provider'] ?? 'log');
		if ($provider === 'rest') {
			return $this->restProvider;
		}

		return $this->logProvider;
	}//end resolveProvider()

	/**
	 * Upsert an `fsc_service` cache record for a successful resolution —
	 * finds an existing row for the same organisation+service and updates
	 * it, else creates a new one, so repeated resolves never duplicate.
	 *
	 * @param array $resolution The resolution returned by `resolveService()`.
	 * @param FscConnectivityProviderInterface $provider The provider that produced it (for `resolvedVia`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md
	 */
	private function cacheResolution(array $resolution, FscConnectivityProviderInterface $provider): void {
		$existing = $this->findCachedService(
			organisation: (string)$resolution['organisation'],
			service: (string)$resolution['service']
		);

		$this->objectService->saveObject(
			object: [
				'organisation' => $resolution['organisation'],
				'service' => $resolution['service'],
				'endpoint' => $resolution['endpoint'],
				'grantRequired' => ($resolution['grantRequired'] ?? false),
				'resolvedVia' => $provider->getProviderId(),
				'resolvedAt' => (new DateTime())->format('c'),
			],
			register: self::REGISTER,
			schema: self::SCHEMA_SERVICE,
			uuid: ($existing?->getUuid())
		);

	}//end cacheResolution()

	/**
	 * Find an existing `fsc_service` cache row for an organisation+service.
	 *
	 * @param string $organisation The organisation identifier.
	 * @param string $service The service identifier.
	 *
	 * @return ObjectEntity|null The matching row, or null when none matches.
	 */
	private function findCachedService(string $organisation, string $service): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_SERVICE,
					'organisation' => $organisation,
					'service' => $service,
				],
				'limit' => 1,
			]
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findCachedService()
}//end class
