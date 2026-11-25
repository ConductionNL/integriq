<?php

/**
 * Stub of OpenRegister's PermissionAsk for unit tests.
 *
 * The constructor and askedBy() of openregister main 55f2aa3e, the parts
 * integriq's DsoAccountRights calls. about() and check() are left out: they
 * pull in ObjectEntity and PermissionCheck, which nothing here exercises.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * One hasPermission() question exactly as the caller put it.
 */
final class PermissionAsk {

	/**
	 * The uid asked about, null for the session user.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The owner of the object asked about.
	 *
	 * @var string|null
	 */
	public ?string $objectOwner = null;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema The schema.
	 * @param string $action The action.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $action,
	) {
	}//end __construct()

	/**
	 * Who asks, and who owns the object.
	 *
	 * @param string|null $userId      The uid.
	 * @param string|null $objectOwner The owner.
	 *
	 * @return self
	 */
	public function askedBy(?string $userId, ?string $objectOwner): self {
		$this->userId      = $userId;
		$this->objectOwner = $objectOwner;

		return $this;
	}//end askedBy()
}//end class
