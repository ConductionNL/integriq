<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\BulkBatchDefaults.
 *
 * Same constructor as the OpenRegister value object (with the defaults
 * openregister #9108 gives it), so a test builds and reads it the way
 * production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema a bulk save applies to rows that name none.
 */
final class BulkBatchDefaults {
	/**
	 * @param Register|string|int|null $register The register.
	 * @param Schema|string|int|null $schema The schema.
	 */
	public function __construct(
		public readonly Register|string|int|null $register = null,
		public readonly Schema|string|int|null $schema = null,
	) {
	}
}
