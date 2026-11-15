<?php

/**
 * Integriq Integration Object Lookup.
 *
 * Finds a source, synchronization or job for an occ command by its uuid or
 * its slug, and lists them. The commands run from the shell, with no user
 * session, so the objects are read without RBAC or tenancy, the same way the
 * screens' own actions read them (`_rbac: false`).
 *
 * @category Command
 * @package  OCA\Integriq\Command\Integration
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
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Command\Integration;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * Resolves and lists integriq objects for the occ commands.
 */
class IntegrationObjectLookup {

	/**
	 * The register every integriq object lives in.
	 */
	public const REGISTER = 'integriq';

	/**
	 * Most objects a list command prints.
	 */
	public const LIST_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService Reads the objects.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The object with this uuid, or else the one object with this slug.
	 *
	 * A slug that two objects share names neither: running one of them on a
	 * guess is how a nightly script runs the wrong synchronization.
	 *
	 * @param string $schema    The schema: source, synchronization or job.
	 * @param string $reference The uuid or slug.
	 *
	 * @return ObjectEntity|null The object, or null when none or more than one matches.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	public function resolve(string $schema, string $reference): ?ObjectEntity {
		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		try {
			$found = $this->objectService->find(
				id: $reference,
				register: self::REGISTER,
				schema: $schema,
				_rbac: false,
				// Unscoped: an occ command, run by the operator without a user session.
				_multitenancy: false
			);
			if ($found instanceof ObjectEntity) {
				return $found;
			}
		} catch (Throwable) {
			// Not a uuid of this schema: try it as a slug.
		}

		$bySlug = $this->findAll(schema: $schema, filters: ['slug' => $reference], limit: 2);
		if (count($bySlug) !== 1) {
			return null;
		}

		return $bySlug[0];

	}//end resolve()

	/**
	 * Every object of a schema, up to LIST_LIMIT.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, ObjectEntity> The objects.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	public function list(string $schema): array {
		return $this->findAll(schema: $schema, filters: [], limit: self::LIST_LIMIT);

	}//end list()

	/**
	 * The objects of a schema matching the filters.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters Field filters.
	 * @param int                  $limit   The most to return.
	 *
	 * @return array<int, ObjectEntity> The objects.
	 */
	private function findAll(string $schema, array $filters, int $limit): array {
		$found   = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => $limit,
			],
			_rbac: false,
			// Unscoped: an occ command, run by the operator without a user session.
			_multitenancy: false
		);
		$results = ($found['results'] ?? $found);

		return array_values(
			array_filter((array) $results, static fn ($result): bool => $result instanceof ObjectEntity)
		);

	}//end findAll()

}//end class
