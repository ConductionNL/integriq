<?php

/**
 * Contract of the SourceMappingService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Public operations of {@see SourceMappingService} that other components call.
 */
interface SourceMappingServiceInterface {
	/**
	 * Gets a guzzle client based upon given config.
	 *
	 * @param array $config The config to be used for the client.
	 *
	 * @return Client The configured Guzzle client.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function getClient(array $config): Client;

	/**
	 * Save an object to MongoDB.
	 *
	 * @param array $data The data to be saved.
	 * @param array $config The configuration that should be used by the call.
	 *
	 * @return array The resulting object.
	 *
	 * @throws GuzzleException When the HTTP request fails.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function saveObject(array $data, array $config): array;

	/**
	 * Finds objects based upon a set of filters.
	 *
	 * @param array $filters The filters to compare the object to.
	 * @param array $config The configuration that should be used by the call.
	 *
	 * @return array The objects found for given filters.
	 *
	 * @throws GuzzleException When the HTTP request fails.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function findObjects(array $filters, array $config): array;

	/**
	 * Finds an object based upon a set of filters (usually the id).
	 *
	 * @param array $filters The filters to compare the objects to.
	 * @param array $config The config to be used by the call.
	 *
	 * @return array The resulting object.
	 *
	 * @throws GuzzleException When the HTTP request fails.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function findObject(array $filters, array $config): array;

	/**
	 * Updates an object in MongoDB.
	 *
	 * @param array $filters The filter to search the object with (id).
	 * @param array $update The fields that should be updated.
	 * @param array $config The configuration to be used by the call.
	 *
	 * @return array The updated object.
	 *
	 * @throws GuzzleException When the HTTP request fails.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 *
	 * @orphaned-write-capability exclude Deferred, NOT dismissed — tracked in
	 * ConductionNL/integriq#1535. Zero production callers, verified on both
	 * `origin/development` and the app-id rename branch, so the finding is
	 * pre-existing rather than rename damage. Most likely an unused arm of this
	 * Mongo-backend CRUD shim, in which case the fix is deletion — but that is
	 * unrelated behaviour change and does not belong in an app-id rename.
	 */
	public function updateObject(array $filters, array $update, array $config): array;

	/**
	 * Delete an object according to a filter (id specifically).
	 *
	 * @param array $filters The filters to use.
	 * @param array $config The config to be used by the call.
	 *
	 * @return array An empty array.
	 *
	 * @throws GuzzleException When the HTTP request fails.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function deleteObject(array $filters, array $config): array;

	/**
	 * Aggregates objects for search facets.
	 *
	 * @param array $filters The filters apply to the search request.
	 * @param array $pipeline The pipeline to use.
	 * @param array $config The configuration to use in the call.
	 *
	 * @return array The aggregation result.
	 *
	 * @throws GuzzleException When the HTTP request fails.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function aggregateObjects(array $filters, array $pipeline, array $config):array;

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
	 *
	 * @throws ContainerExceptionInterface When the container fails.
	 * @throws NotFoundExceptionInterface When the service is not bound.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function getOpenRegisters(): ?\OCA\OpenRegister\Service\ObjectService;

	/**
	 * Gets the appropriate mapper based on the object type.
	 *
	 * Either resolves an OpenRegister mapper by register + schema, or throws when
	 * an unknown object type is requested.
	 *
	 * @param string|null $objectType The objectType as string.
	 * @param int|null $schema The openregister schema.
	 * @param int|null $register The openregister register.
	 *
	 * @return mixed The appropriate mapper.
	 *
	 * @throws ContainerExceptionInterface When the container fails.
	 * @throws NotFoundExceptionInterface When the service is not bound.
	 * @throws InvalidArgumentException If an unknown object type is provided.
	 *
	 * @spec openspec/specs/object-service-shim/spec.md
	 */
	public function getMapper(?string $objectType = null, ?int $schema = null, ?int $register = null): mixed;
}
