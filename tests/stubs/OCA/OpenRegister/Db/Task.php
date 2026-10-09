<?php

/**
 * Stub for OCA\OpenRegister\Db\Task.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the
 * standalone composer dev-environment. This stub satisfies `use` statements
 * and mock-builder calls for the shared task entity so unit tests can run
 * without the peer app. It uses the real Entity base so the magic
 * __call-based accessors (`getUuid()`, `getOnTimeout()`, ...) resolve the
 * way they do in production; a hand-rolled stub with only declared methods
 * would fatal on exactly the accessor a test forgot (a fake that agrees
 * with the caller cannot fail).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Minimal stub for OCA\OpenRegister\Db\Task.
 */
class Task extends Entity {

	public const STATE_AVAILABLE = 'available';

	public const STATE_ENABLED = 'enabled';

	public const STATE_ACTIVE = 'active';

	public const STATE_COMPLETED = 'completed';

	public const STATE_TERMINATED = 'terminated';

	public const STATE_DISABLED = 'disabled';

	/**
	 * Copied from the real entity: the states a task never leaves.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_STATES = [
		self::STATE_COMPLETED,
		self::STATE_TERMINATED,
		self::STATE_DISABLED,
	];

	/** @var string|null */
	protected $uuid = null;

	/** @var string|null */
	protected $state = null;

	/** @var string|null */
	protected $outcome = null;

	/** @var string|null */
	protected $onTimeout = null;

	/** @var string|null */
	protected $onReject = null;

	/** @var string|null */
	protected $requester = null;

	/** @var string|null */
	protected $appId = null;

	/** @var array|null */
	protected $metadata = null;

	/** @var array|null */
	protected $candidateGroups = null;

	protected $completedBy = null;

	protected $resultText = null;

	protected $comment = null;

	/**
	 * Whether the task is in a terminal state (real: the same list).
	 *
	 * @return bool True when the state is terminal.
	 */
	public function isInTerminalState(): bool {
		return in_array($this->state, self::TERMINAL_STATES, true);
	}//end isInTerminalState()
}
