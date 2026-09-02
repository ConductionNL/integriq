<?php

/**
 * Stub for OCA\OpenRegister\Service\Task\TaskService.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the
 * standalone composer dev-environment. This stub satisfies `use` statements
 * and PHPUnit mock-builder calls for the shared task service so unit tests
 * can run without the peer app.
 *
 * The signatures COPY the real service's
 * (`lib/Service/Task/TaskService.php` in ConductionNL/openregister):
 * `import(array $data, ?string $actor): Task` (the trusted creation path)
 * and `applyTimerOutcome(string $uuid, string $outcome, string $source,
 * string $reason): Task` (the outcome path a decision or sweep closes a
 * task through). A stub written from integriq's call sites instead of the
 * real signatures would encode the caller's own bug and could not fail.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;

/**
 * Minimal stub for OCA\OpenRegister\Service\Task\TaskService.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The real signatures are the point.
 */
class TaskService {

	/**
	 * Create a task on the trusted path (real: validates, persists, audits).
	 *
	 * @param array<string, mixed> $data The task fields.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task An empty task entity.
	 */
	public function import(array $data, ?string $actor): Task {
		return new Task();
	}//end import()

	/**
	 * Apply a declared outcome to a task (real: idempotent on terminal rows).
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome The declared outcome.
	 * @param string $source The applying source.
	 * @param string $reason The audited reason.
	 *
	 * @return Task An empty task entity.
	 */
	public function applyTimerOutcome(string $uuid, string $outcome, string $source, string $reason): Task {
		return new Task();
	}//end applyTimerOutcome()
}
