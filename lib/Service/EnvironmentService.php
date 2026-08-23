<?php

/**
 * Integriq environment service.
 *
 * CRUD over `environment`-schema OpenRegister objects (environments-and-
 * promotion REQ-001). An environment carries no credential or connection
 * material of its own — `sourceRef` points at an existing `source`-schema
 * object (type: api), reused unchanged by {@see PromotionService} via
 * {@see CallService}. This service does not touch CallService or the
 * credential broker at all; it only manages the environment metadata rows.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Manages `environment`-schema OpenRegister objects.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
 */
class EnvironmentService {
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	private const REGISTER = 'openconnector';

	private const SCHEMA = 'environment';

	private const SOURCE_SCHEMA = 'source';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService The OR object service.
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
	) {

	}//end __construct()

	/**
	 * List every registered environment.
	 *
	 * @return ObjectEntity[]
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
	 */
	public function list(): array {
		$result = $this->orObjectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA]]
		);
		$items = ($result['results'] ?? $result);

		return array_values(
			array_filter(
				$items,
				static fn ($item) => $item instanceof ObjectEntity
			)
		);

	}//end list()

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
	public function create(array $data): ObjectEntity {
		$sourceRef = ($data['sourceRef'] ?? null);
		if (is_string($sourceRef) === false || $sourceRef === '') {
			throw new InvalidArgumentException('Environment requires a sourceRef pointing at an existing Source object.');
		}

		if ($this->resolveSource(sourceRef: $sourceRef) === null) {
			throw new InvalidArgumentException(
				"Environment sourceRef '{$sourceRef}' does not resolve to an existing Source object."
			);
		}

		return $this->orObjectService->saveObject(object: $data, register: self::REGISTER, schema: self::SCHEMA);
	}//end create()

	/**
	 * Find an environment by its slug.
	 *
	 * @param string $slug The environment slug.
	 *
	 * @return ObjectEntity|null The environment object, or null when no environment has that slug.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001
	 */
	public function findBySlug(string $slug): ?ObjectEntity {
		$result = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
					'slug' => $slug,
				],
			]
		);
		$items = ($result['results'] ?? $result);

		foreach ($items as $item) {
			if ($item instanceof ObjectEntity) {
				return $item;
			}
		}

		return null;
	}//end findBySlug()

	/**
	 * Resolve an environment's connectivity Source object.
	 *
	 * @param string $sourceRef The `source`-schema object UUID.
	 *
	 * @return ObjectEntity|null The Source object, or null when it no longer resolves.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-an-environment-without-a-resolvable-sourceref-cannot-be-used-as-a-promotion-target
	 */
	public function resolveSource(string $sourceRef): ?ObjectEntity {
		try {
			$entity = $this->orObjectService->find(
				id: $sourceRef,
				register: self::REGISTER,
				schema: self::SOURCE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			return null;
		}

		return $entity;
	}//end resolveSource()
}//end class
