<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\UnlockRequest.
 *
 * Same constructor as the OpenRegister value object (with the defaults
 * openregister #9095 gives it), so a test builds and reads it the way
 * production does when the peer app is not installed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * One lock to release.
 */
final class UnlockRequest {
	/**
	 * @param string $identifier The object id or uuid.
	 * @param bool $advisory Whether the lock is an advisory flow lock.
	 * @param string|null $runUuid The flow run that holds it.
	 * @param bool $break Release a lock another process holds.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly bool $advisory = false,
		public readonly ?string $runUuid = null,
		public readonly bool $break = false,
	) {
	}
}
