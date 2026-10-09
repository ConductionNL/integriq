<?php

/**
 * Stub for OCA\OpenRegister\Event\TaskTerminalEvent.
 *
 * Copies the real event's constructor and accessors
 * (`lib/Event/TaskTerminalEvent.php` in ConductionNL/openregister):
 * `__construct(Task $task, bool $committed = true)`, `getTask()`,
 * `isCommitted()`. Tests construct this real shape, never a faked event.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Task;
use OCP\EventDispatcher\Event;

/**
 * A task was persisted in a terminal state.
 */
class TaskTerminalEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param Task $task The task as persisted.
	 * @param bool $committed True when dispatched after the transaction closed.
	 */
	public function __construct(
		private readonly Task $task,
		private readonly bool $committed = true,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The terminal task.
	 *
	 * @return Task The task.
	 */
	public function getTask(): Task {
		return $this->task;
	}//end getTask()

	/**
	 * Whether the transaction that moved the task has closed.
	 *
	 * @return bool True after commit.
	 */
	public function isCommitted(): bool {
		return $this->committed;
	}//end isCommitted()
}
