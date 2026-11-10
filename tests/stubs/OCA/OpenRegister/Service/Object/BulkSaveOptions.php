<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\BulkSaveOptions.
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

/**
 * How a bulk save runs: batch defaults, validation, events and audit.
 */
final class BulkSaveOptions {
	/**
	 * @param BulkBatchDefaults $defaults The register and schema for rows that name none.
	 * @param bool $validation Validate each object against its schema.
	 * @param bool $events Dispatch object events.
	 * @param bool $audit Record audit-trail rows for the batch.
	 */
	public function __construct(
		public readonly BulkBatchDefaults $defaults = new BulkBatchDefaults(),
		public readonly bool $validation = false,
		public readonly bool $events = false,
		public readonly bool $audit = true,
	) {
	}
}
