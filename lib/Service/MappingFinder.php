<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Finds a mapping object by uuid, id, slug or imported reference, for
 * MappingService and the mapping event a sibling app dispatches.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Resolves a mapping object, scoped to the caller or not.
 *
 * OpenRegister's find() throws DoesNotExistException for an identifier it
 * cannot resolve; the `slug`/`reference` fallback runs after it, so a mapping
 * known only by its imported reference still resolves
 * (mapping-woo-index-field-mapping D4).
 *
 * A sibling app's MappingExecutionRequestedEvent looks the mapping up unscoped:
 * the mapping's `callableBy` decides who may run it, and the caller can be
 * anonymous (the Woo-index harvester reading opencatalogi's sitemap).
 *
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
 */
final class MappingFinder {

	/**
	 * The register holding the mappings.
	 */
	private const REGISTER = 'integriq';

	/**
	 * The mapping schema.
	 */
	private const SCHEMA = 'mapping';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objects OpenRegister's object service.
	 */
	public function __construct(
		private readonly OrObjectService $objects,
	) {
	}//end __construct()

	/**
	 * Resolve a mapping by uuid, id or slug, then by imported identifiers.
	 *
	 * @param string $reference The uuid, id, slug or reference.
	 * @param bool   $scoped    Whether the caller's read rule and organisation narrow the lookup.
	 *
	 * @return ObjectEntity|null The mapping object, or null when nothing matches.
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function find(string $reference, bool $scoped): ?ObjectEntity {
		try {
			$object = $this->objects->find(
				id: $reference,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::SCHEMA),
				_rbac: $scoped,
				_multitenancy: $scoped
			);
		} catch (DoesNotExistException) {
			$object = null;
		}

		if ($object === null) {
			$object = $this->byIdentifier(identifier: $reference, scoped: $scoped);
		}

		return $object;
	}//end find()

	/**
	 * Find a mapping by imported configuration identifiers.
	 *
	 * @param string $identifier The mapping slug/reference to resolve.
	 * @param bool   $scoped     Whether the caller's read rule and organisation narrow the lookup.
	 *
	 * @return ObjectEntity|null The matching mapping object, when found.
	 */
	private function byIdentifier(string $identifier, bool $scoped): ?ObjectEntity {
		foreach (['slug', 'reference'] as $field) {
			try {
				$matches = $this->objects->findAll(
					config: [
						'filters' => [
							'register' => self::REGISTER,
							'schema' => self::SCHEMA,
							$field => $identifier,
						],
					],
					_rbac: $scoped,
					_multitenancy: $scoped
				);
			} catch (DoesNotExistException) {
				continue;
			}

			$results = ($matches['results'] ?? $matches);
			if (empty($results) === false) {
				return $results[0];
			}
		}

		return null;
	}//end byIdentifier()
}//end class
