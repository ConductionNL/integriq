<?php

/**
 * ForeignOrganisation: ObjectService callbacks that behave like OpenRegister's
 * organisation filter for an object owned by another organisation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Callbacks for `willReturnCallback()` on an ObjectService mock. With the
 * organisation filter on (OpenRegister's default) the foreign object is not
 * found or not listed; only a caller that switches the filter off reaches it.
 * A test using these fails as soon as the code under test bypasses the filter.
 */
class ForeignOrganisation {

	/**
	 * A find() callback for an object owned by another organisation.
	 *
	 * @param ObjectEntity $object The other organisation's object.
	 *
	 * @return callable The callback, positional as the ObjectService stub's find().
	 */
	public static function find(ObjectEntity $object): callable {
		return static function (
			$id,
			?string $register = null,
			?string $schema = null,
			bool $_rbac = true,
			bool $_multitenancy = true
		) use ($object): ObjectEntity {
			if ($_multitenancy === true) {
				throw new DoesNotExistException('Object not found in the active organisation');
			}

			return $object;
		};
	}//end find()

	/**
	 * A findAll() callback for a list that holds only another organisation's objects.
	 *
	 * @param array<int, ObjectEntity> $objects The other organisation's objects.
	 *
	 * @return callable The callback, positional as the ObjectService stub's findAll().
	 */
	public static function findAll(array $objects): callable {
		return static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($objects): array {
			if ($_multitenancy === true) {
				return [];
			}

			return $objects;
		};
	}//end findAll()

}//end class
