<?php

/**
 * Contract of the EnvironmentService.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see EnvironmentService} that other components call.
 */
interface EnvironmentServiceInterface {
	/**
	 * List every registered environment.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
	 */
	public function list(): array;

	/**
	 * Create a new environment. Validates that `sourceRef` resolves to an
	 * existing `source`-schema object before writing (REQ-001 scenario 1).
	 *
	 * @param array<string,mixed> $data The environment payload (name, slug, role, sourceRef, description).
	 *
	 * @return ObjectEntity The created environment object.
	 *
	 * @throws InvalidArgumentException When `sourceRef` is missing or does not resolve.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-creating-an-environment-requires-an-existing-source-reference
	 */
	public function create(array $data): ObjectEntity;

	/**
	 * Find an environment by its slug.
	 *
	 * @param string $slug The environment slug.
	 *
	 * @return ObjectEntity|null The environment object, or null when no environment has that slug.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
	 */
	public function findBySlug(string $slug): ?ObjectEntity;

	/**
	 * Resolve an environment's connectivity Source object.
	 *
	 * @param string $sourceRef The `source`-schema object UUID.
	 *
	 * @return ObjectEntity|null The Source object, or null when it no longer resolves.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-an-environment-without-a-resolvable-sourceref-cannot-be-used-as-a-promotion-target
	 */
	public function resolveSource(string $sourceRef): ?ObjectEntity;
}
