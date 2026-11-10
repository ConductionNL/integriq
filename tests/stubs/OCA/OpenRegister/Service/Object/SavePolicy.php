<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\SavePolicy.
 *
 * Same constructor as the OpenRegister value object (with the defaults
 * openregister #9123 gives it), so a test builds and reads it the way
 * production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * How a save behaves: silently, validated, create-only, deduplication override.
 */
final class SavePolicy {
	/**
	 * @param bool $silent Skip events and audit.
	 * @param bool $validation Validate against the schema.
	 * @param bool $failIfExists Refuse to overwrite an existing object.
	 * @param bool $dedupOverride Save even when deduplication would merge.
	 */
	public function __construct(
		public readonly bool $silent = false,
		public readonly bool $validation = true,
		public readonly bool $failIfExists = false,
		public readonly bool $dedupOverride = false,
	) {
	}
}
