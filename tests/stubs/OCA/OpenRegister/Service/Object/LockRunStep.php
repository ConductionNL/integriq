<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\LockRunStep.
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
 * The flow run and node that hold an advisory lock, if any.
 */
final class LockRunStep {
	/**
	 * @param string|null $runUuid The flow run.
	 * @param string|null $nodeId The node in that run.
	 */
	public function __construct(
		public readonly ?string $runUuid,
		public readonly ?string $nodeId,
	) {
	}
}
