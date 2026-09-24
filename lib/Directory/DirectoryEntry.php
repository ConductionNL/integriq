<?php

/**
 * Integriq Directory Entry.
 *
 * One account as the customer's directory describes it: an identifier, the
 * directory groups it belongs to, and whether the directory still considers it
 * active. Integriq holds no password and no second account record, so this
 * value object deliberately carries no credential field at all.
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

use JsonSerializable;

/**
 * One account as read from the directory.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */
class DirectoryEntry implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string $userId The Nextcloud account id this entry maps onto.
	 * @param array<int,string> $directoryGroups The directory group names the entry belongs to.
	 * @param boolean $active Whether the directory still lists the account as active.
	 * @param array<string,mixed> $attributes Free-form directory attributes the mapping may read.
	 * @param string|null $displayName The directory display name, for the run report only.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function __construct(
		private readonly string $userId,
		private readonly array $directoryGroups = [],
		private readonly bool $active = true,
		private readonly array $attributes = [],
		private readonly ?string $displayName = null,
	) {

	}//end __construct()

	/**
	 * The Nextcloud account id this entry maps onto.
	 *
	 * @return string The account id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function getUserId(): string {
		return $this->userId;

	}//end getUserId()

	/**
	 * The directory group names this entry belongs to.
	 *
	 * @return array<int,string> The directory group names.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function getDirectoryGroups(): array {
		return $this->directoryGroups;

	}//end getDirectoryGroups()

	/**
	 * Whether the directory still lists the account as active.
	 *
	 * @return boolean True when active.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function isActive(): bool {
		return $this->active;

	}//end isActive()

	/**
	 * The free-form directory attributes the mapping may read.
	 *
	 * @return array<string,mixed> The attributes.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function getAttributes(): array {
		return $this->attributes;

	}//end getAttributes()

	/**
	 * The directory display name, for the run report only.
	 *
	 * @return string|null The display name.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	public function getDisplayName(): ?string {
		return $this->displayName;

	}//end getDisplayName()

	/**
	 * Build an entry from the directory's own row shape.
	 *
	 * @param array<string,mixed> $row One directory row.
	 *
	 * @return self The entry.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public static function fromArray(array $row): self {
		$groups = [];
		foreach (($row['groups'] ?? []) as $group) {
			if (is_string($group) === true && $group !== '') {
				$groups[] = $group;
				continue;
			}

			// SCIM answers a group membership as `{value, display}`; take the
			// display name, because that is what a mapping is written against.
			if (is_array($group) === true) {
				$name = ($group['display'] ?? $group['value'] ?? '');
				if (is_string($name) === true && $name !== '') {
					$groups[] = $name;
				}
			}
		}

		$active = true;
		if (array_key_exists('active', $row) === true) {
			$active = (bool)$row['active'];
		}

		$displayName = null;
		if (isset($row['displayName']) === true) {
			$displayName = (string)$row['displayName'];
		}

		return new self(
			userId: (string)($row['userName'] ?? $row['userId'] ?? $row['id'] ?? ''),
			directoryGroups: array_values(array_unique($groups)),
			active: $active,
			attributes: ($row['attributes'] ?? []),
			displayName: $displayName,
		);

	}//end fromArray()

	/**
	 * Serialise the entry for a run report.
	 *
	 * @return array<string,mixed> The serialised entry.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	public function jsonSerialize(): array {
		return [
			'userId' => $this->userId,
			'displayName' => $this->displayName,
			'groups' => $this->directoryGroups,
			'active' => $this->active,
		];

	}//end jsonSerialize()
}//end class
