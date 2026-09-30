<?php

/**
 * Resolves an endpoint's `targetId` to the register and schema ids it names.
 *
 * An endpoint targeted its register and schema by database id (`"20/111"`),
 * and ids differ per instance, so an endpoint could not be shipped as seed
 * configuration: the ORI endpoints serve decidiq's register wherever it is
 * installed. A `targetId` may now name both by slug (`"decidiq/meeting"`).
 * The schema slug is looked up among the register's OWN schemas, because
 * slugs such as `person` exist in more than one register.
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
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-name-its-target-register-and-schema-by-slug-req-ep-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Turns `register/schema`, by id or by slug, into the two ids.
 *
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-name-its-target-register-and-schema-by-slug-req-ep-011
 */
class EndpointTargetResolver {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper OpenRegister's register mapper.
	 * @param SchemaMapper   $schemaMapper   OpenRegister's schema mapper.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * Resolve a `targetId` to `[registerId, schemaId]`.
	 *
	 * Two numeric parts are returned as they are, so every endpoint stored
	 * with ids behaves exactly as before and costs no lookup. Otherwise the
	 * register is found by id or slug and the schema by id or slug among the
	 * register's own schemas. The lookup reads configuration, not data, so it
	 * runs without RBAC: an anonymous caller of a public endpoint must reach
	 * the same target an administrator configured.
	 *
	 * @param string $targetId The endpoint's `targetId`, `register/schema`.
	 *
	 * @return array{0: int, 1: int} The register id and the schema id.
	 *
	 * @throws DoesNotExistException When the register, or the schema within it, does not exist.
	 *
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-an-endpoint-may-name-its-target-register-and-schema-by-slug-req-ep-011
	 */
	public function resolve(string $targetId): array {
		$parts = explode('/', $targetId, 2);
		$register = trim($parts[0]);
		$schema = trim($parts[1] ?? '');

		if (ctype_digit($register) === true && ctype_digit($schema) === true) {
			return [(int)$register, (int)$schema];
		}

		if ($register === '' || $schema === '') {
			throw new DoesNotExistException('Endpoint target "' . $targetId . '" does not name a register and a schema.');
		}

		try {
			$registerEntity = $this->registerMapper->find(id: $register, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$registerEntity = null;
		}

		if ($registerEntity === null) {
			throw new DoesNotExistException('Endpoint target register "' . $register . '" does not exist.');
		}

		$schemaId = $this->findSchemaInRegister(schemaIds: (array)($registerEntity->getSchemas() ?? []), schema: $schema);
		if ($schemaId === null) {
			throw new DoesNotExistException('Endpoint target schema "' . $schema . '" does not exist in register "' . $register . '".');
		}

		return [(int)$registerEntity->getId(), $schemaId];
	}//end resolve()

	/**
	 * Find a schema, by id or slug, among a register's own schemas.
	 *
	 * @param array  $schemaIds The register's schema ids.
	 * @param string $schema    The schema id or slug the endpoint names.
	 *
	 * @return integer|null The schema id, or null when the register holds no such schema.
	 */
	private function findSchemaInRegister(array $schemaIds, string $schema): ?int {
		foreach ($schemaIds as $schemaId) {
			if ((string)$schemaId === $schema) {
				return (int)$schemaId;
			}

			try {
				$entity = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				continue;
			}

			if ($entity !== null && strcasecmp((string)$entity->getSlug(), $schema) === 0) {
				return (int)$entity->getId();
			}
		}

		return null;
	}//end findSchemaInRegister()
}//end class
