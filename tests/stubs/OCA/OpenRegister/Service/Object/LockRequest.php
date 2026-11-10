<?php

/**
 * Stub for OCA\OpenRegister\Service\Object\LockRequest.
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
 * One lock to take: the object, the process holding it and for how long.
 */
final class LockRequest {
	/** @var string|null The flow run holding the lock. */
	public readonly ?string $runUuid;

	/** @var string|null The node in that run. */
	public readonly ?string $nodeId;

	/**
	 * @param string $identifier The object id or uuid.
	 * @param string|null $process The process holding the lock.
	 * @param int|null $duration The lock lifetime in seconds.
	 * @param LockRunStep $runStep The flow run and node, for an advisory lock.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly ?string $process = null,
		public readonly ?int $duration = null,
		LockRunStep $runStep = new LockRunStep(runUuid: null, nodeId: null),
	) {
		$this->runUuid = $runStep->runUuid;
		$this->nodeId = $runStep->nodeId;
	}
}
