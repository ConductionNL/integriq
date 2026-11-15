<?php

/**
 * Stub for OCA\OpenRegister\Service\OrganisationService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for OCA\OpenRegister\Service\OrganisationService.
 */
class OrganisationService {
	public function getOrganisation(string $userId): ?array {
		return null;
	}

	public function setOrganisation(string $userId, array $data): bool {
		return true;
	}

	/**
	 * The caller's currently-active organisation.
	 *
	 * The real signature is
	 * `getActiveOrganisation(?array $preloadedOrgs = null): ?Organisation`.
	 * The return type is WIDENED to `?object` here for one stated reason:
	 * OCA\OpenRegister\Db\Organisation is not stubbed in this repository, so
	 * naming it would make this file unloadable. The same widening is already
	 * used by the RegisterMapper/SchemaMapper stubs, which return `?object`
	 * where the real classes return Register/Schema.
	 *
	 * @param array|null $preloadedOrgs Optional pre-fetched organisation list.
	 *
	 * @return object|null
	 */
	public function getActiveOrganisation(?array $preloadedOrgs = null): ?object {
		return null;
	}

	/**
	 * The caller's organisation statistics (real signature).
	 *
	 * @return array
	 */
	public function getUserOrganisationStats(): array {
		return [];
	}

	/**
	 * Set the caller's active organisation (real signature).
	 *
	 * @param string $organisationUuid The organisation uuid.
	 *
	 * @return bool
	 */
	public function setActiveOrganisation(string $organisationUuid): bool {
		return true;
	}

	/**
	 * The caller's organisations (real signature).
	 *
	 * @param bool $_useCache Whether to use the cache.
	 *
	 * @return array
	 */
	public function getUserOrganisations(bool $_useCache = true): array {
		return [];
	}
}
