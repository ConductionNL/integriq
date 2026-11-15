<?php

/**
 * Integriq organisation boundary.
 *
 * Reads the owning organisation of an integriq object and says whether two
 * objects may meet: a path that runs without OpenRegister's organisation
 * filter uses it to keep one organisation's objects away from another's.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
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
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Organisation checks for unscoped system paths.
 *
 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
 */
trait OrganisationBoundary {

	/**
	 * The owning organisation of an object, or '' when it carries none.
	 *
	 * Reads the entity column of an ObjectEntity, or `@self.organisation` of a
	 * serialised object (a uuid, or an expanded organisation with a uuid or id).
	 *
	 * @param ObjectEntity|array|null $object The object or its serialised form.
	 *
	 * @return string The organisation uuid, or '' when there is none.
	 *
	 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
	 */
	private function organisationOf(ObjectEntity|array|null $object): string {
		$value = null;
		if ($object instanceof ObjectEntity === true) {
			$value = $object->getOrganisation();
		} elseif (is_array($object) === true) {
			$value = ($object['@self']['organisation'] ?? null);
		}

		if (is_array($value) === true) {
			$value = ($value['uuid'] ?? $value['id'] ?? null);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end organisationOf()

	/**
	 * Whether two organisations may meet: they are equal, or either is unset
	 * (an object created before multitenancy was configured).
	 *
	 * @param string $first  The first organisation uuid, or ''.
	 * @param string $second The second organisation uuid, or ''.
	 *
	 * @return bool False only when both are set and differ.
	 *
	 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
	 */
	private function sameOrganisation(string $first, string $second): bool {
		if ($first === '' || $second === '') {
			return true;
		}

		return $first === $second;
	}//end sameOrganisation()

	/**
	 * The `@self` block that files a new record under an object's organisation.
	 *
	 * OpenRegister honours it when the caller is an admin or a member of that
	 * organisation, and otherwise stamps the caller's active organisation.
	 *
	 * @param ObjectEntity|array|null $object The object whose organisation the record belongs to.
	 *
	 * @return array{'@self'?: array{organisation: string}} The block, or [] when the object has no organisation.
	 *
	 * @spec openspec/changes/integriq-tenant-scoping/specs/tenant-scoping/spec.md#requirement-a-user-only-reaches-their-own-organisations-integriq-objects-req-ten-001
	 */
	private function organisationSelf(ObjectEntity|array|null $object): array {
		$organisation = $this->organisationOf(object: $object);
		if ($organisation === '') {
			return [];
		}

		return ['@self' => ['organisation' => $organisation]];
	}//end organisationSelf()

}//end trait
