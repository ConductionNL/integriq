<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectDeletion.
 *
 * Same constructor as the OpenRegister value object, so a test builds and
 * reads it the way production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCP\IUser;

/**
 * One object to delete: its uuid, where it lives, who deletes it and whether permanently.
 */
final class ObjectDeletion {
	/**
	 * @param string $uuid The object uuid.
	 * @param ObjectFindTarget $target The register and schema.
	 * @param IUser|null $currentUser The deleting user, or null for the session user.
	 * @param bool $permanent Remove the object and its files instead of soft-deleting.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target = new ObjectFindTarget(),
		public readonly ?IUser $currentUser = null,
		public readonly bool $permanent = false,
	) {
	}
}
