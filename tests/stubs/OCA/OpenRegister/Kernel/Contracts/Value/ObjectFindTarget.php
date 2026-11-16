<?php

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget: the
 * constructor as on openregister main, for unit tests without the app.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Which register and schema an object lives in.
 */
final class ObjectFindTarget {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register The register.
	 * @param Schema|string|int|null   $schema   The schema.
	 */
	public function __construct(
		public readonly Register|string|int|null $register=null,
		public readonly Schema|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
