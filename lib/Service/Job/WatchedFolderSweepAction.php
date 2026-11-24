<?php

/**
 * Integriq Watched Folder Sweep Action.
 *
 * The scheduled half of the watched folder: the event listener queues a file the
 * moment Nextcloud announces it, and this action, run by a seeded `job` row
 * through `JobTask` and `JobService::run()`, lists every watched folder and
 * queues each file that has no contract yet, so a file no event announced
 * (an external storage, a missed event, a restart) is still picked up.
 *
 * It is a job action, not a `TimedJob` (REQ-DIC-005): the schedule is a register
 * record (`job` slug `watched-folder-sweep`) an administrator can disable or
 * re-time, not PHP code.
 *
 * @category Action
 * @package  OCA\Integriq\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Job;

use OCA\Integriq\Service\WatchedFolderService;

/**
 * Runs the watched-folder sweep as a scheduled job action.
 */
class WatchedFolderSweepAction {

	/**
	 * Constructor.
	 *
	 * @param WatchedFolderService $watchedFolders The sweep.
	 */
	public function __construct(
		private readonly WatchedFolderService $watchedFolders,
	) {
	}//end __construct()

	/**
	 * Sweep every watched folder and report what was queued.
	 *
	 * `level` is `SUCCESS` when every watched folder was listed and `WARNING`
	 * when a synchronization was stopped because its target does not resolve,
	 * naming each one, so the job log shows a misconfigured folder.
	 *
	 * @param array $arguments The job's arguments; none are read.
	 *
	 * @return array{level: string, message: string, stackTrace: list<string>} The job log entry.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $arguments is the job action signature JobService calls.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/tasks.md#task-6-the-listener-the-job-and-the-sweep
	 */
	public function run(array $arguments = []): array {
		$result  = $this->watchedFolders->sweep();
		$message = 'Watched-folder sweep queued ' . $result['queued'] . ' file(s)';

		if ($result['stopped'] === []) {
			return ['level' => 'SUCCESS', 'message' => $message, 'stackTrace' => [$message]];
		}

		$stopped = 'Stopped, the target does not resolve: ' . implode(', ', $result['stopped']);

		return ['level' => 'WARNING', 'message' => $message . '; ' . $stopped, 'stackTrace' => [$message, $stopped]];
	}//end run()
}//end class
