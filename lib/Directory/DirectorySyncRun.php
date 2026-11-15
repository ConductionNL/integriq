<?php

/**
 * Integriq Directory Sync Run.
 *
 * The state of one directory run in DirectorySyncService: the connection,
 * when the run started, whether it is a preview, whether an administrator
 * confirmed the removals, and the plan once worked out.
 *
 * @category Directory
 * @package  OCA\Integriq\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One directory run in progress. Built and used by DirectorySyncService only.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
 */
final class DirectorySyncRun {
	/**
	 * Whether this is a preview that writes no membership.
	 *
	 * @var boolean
	 */
	public bool $dryRun = false;

	/**
	 * Whether an administrator confirmed a previously guarded run.
	 *
	 * @var boolean
	 */
	public bool $confirmRemovals = false;

	/**
	 * The plan: additions, removals, managed groups and current totals.
	 *
	 * @var array<string,mixed>
	 */
	public array $plan = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 * @param float        $startedAt  The run start, from microtime().
	 */
	public function __construct(
		public readonly ObjectEntity $connection,
		public readonly float $startedAt,
	) {
	}//end __construct()

	/**
	 * The connection configuration.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function configuration(): array {
		return (array)($this->connection->getObject()['configuration'] ?? []);

	}//end configuration()
}//end class
