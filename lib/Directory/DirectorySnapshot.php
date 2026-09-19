<?php

/**
 * Integriq Directory Snapshot.
 *
 * What one read of the directory returned: the entries, the directory group
 * names seen, and whether the read completed. Completeness is separate from
 * emptiness on purpose — a directory that answered half its users during an
 * outage is the shape that empties every Nextcloud group at once, and the
 * deletion-ratio guard needs to be able to tell the two apart.
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
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

/**
 * The result of one read of the directory.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */
class DirectorySnapshot {

	/**
	 * Constructor.
	 *
	 * @param array<int,DirectoryEntry> $entries The entries the directory answered with.
	 * @param boolean $complete Whether the read reached the end of the directory.
	 * @param array<int,array<string,mixed>> $failures Rows the reader could not turn into an entry.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function __construct(
		private readonly array $entries = [],
		private readonly bool $complete = true,
		private readonly array $failures = [],
	) {

	}//end __construct()

	/**
	 * The entries the directory answered with.
	 *
	 * @return array<int,DirectoryEntry> The entries.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function getEntries(): array {
		return $this->entries;

	}//end getEntries()

	/**
	 * Whether the read reached the end of the directory.
	 *
	 * @return boolean True when the read completed.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	public function isComplete(): bool {
		return $this->complete;

	}//end isComplete()

	/**
	 * Rows the reader could not turn into an entry, with their reason.
	 *
	 * @return array<int,array<string,mixed>> The per-row failures.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	public function getFailures(): array {
		return $this->failures;

	}//end getFailures()

	/**
	 * Every distinct directory group name seen across the entries.
	 *
	 * @return array<int,string> The directory group names.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function getDirectoryGroups(): array {
		$names = [];
		foreach ($this->entries as $entry) {
			foreach ($entry->getDirectoryGroups() as $name) {
				$names[$name] = true;
			}
		}

		return array_keys($names);

	}//end getDirectoryGroups()
}//end class
