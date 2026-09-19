<?php

/**
 * Integriq Group Mapping Resolver.
 *
 * Turns what the directory says about an account into the Nextcloud groups it
 * should be in. The mapping is configuration on the connection, not code,
 * because organisations differ: one customer has `OU=Vergunningen`, another has
 * a `department` attribute and one flat group. A mapping in code would mean a
 * release per customer.
 *
 * An unknown target group is a decision, not a default. The connection declares
 * whether a missing Nextcloud group is created or named in a refusal. Dropping
 * the membership silently is the third behaviour and it is the one that
 * produces a permission nobody can explain, so it is not offered.
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

use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCP\IGroupManager;
use OCP\IL10N;

/**
 * Resolves declared mappings onto Nextcloud group ids.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
 */
class GroupMappingResolver {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Nextcloud's group model, the only group model there is.
	 * @param IL10N $l10n Translations, so a refusal reads as a sentence.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l10n,
	) {

	}//end __construct()

	/**
	 * The Nextcloud groups this connection's mapping can ever target.
	 *
	 * The sync removes a membership only from a group the mapping owns, so a
	 * group an administrator maintains by hand is never touched by a run.
	 *
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<int,string> The Nextcloud group ids the mapping targets.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function managedGroups(array $configuration): array {
		$targets = [];
		foreach ($this->rules(configuration: $configuration) as $rule) {
			$target = (string)($rule['group'] ?? '');
			if ($target !== '') {
				$targets[$target] = true;
			}
		}

		return array_keys($targets);

	}//end managedGroups()

	/**
	 * The Nextcloud groups one directory entry should be in.
	 *
	 * Many directory groups may name one Nextcloud group, which is the common
	 * case: `OU=Vergunningen` and `OU=Toezicht` both onto `behandelaars`.
	 *
	 * @param DirectoryEntry $entry The directory entry.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<int,string> The Nextcloud group ids.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function resolve(DirectoryEntry $entry, array $configuration): array {
		$targets = [];

		foreach ($this->rules(configuration: $configuration) as $rule) {
			if ($this->matches(entry: $entry, rule: $rule) === false) {
				continue;
			}

			$target = (string)($rule['group'] ?? '');
			if ($target !== '') {
				$targets[$target] = true;
			}
		}

		return array_keys($targets);

	}//end resolve()

	/**
	 * Make sure every Nextcloud group the mapping targets exists.
	 *
	 * Called once per run BEFORE anything is written, so a refusal stops the
	 * run while every membership is still where it was.
	 *
	 * @param array<string,mixed> $configuration The connection configuration.
	 * @param boolean $dryRun Whether this is a preview, in which case nothing is created.
	 *
	 * @return array<int,string> The Nextcloud group ids created by this call.
	 *
	 * @throws DirectorySyncRefusalException When a target group is missing and creation is off.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	public function ensureTargets(array $configuration, bool $dryRun = false): array {
		$createMissing = (bool)($configuration['mapping']['createMissingGroups'] ?? false);
		$created = [];

		foreach ($this->managedGroups(configuration: $configuration) as $groupId) {
			if ($this->groupManager->groupExists($groupId) === true) {
				continue;
			}

			if ($createMissing === false) {
				throw new DirectorySyncRefusalException(
					message: $this->l10n->t(
						'The mapping names the Nextcloud group "%1$s", which does not exist. Creating missing groups is off for this connection. Nothing was changed.',
						[$groupId]
					),
					context: ['reason' => 'unknown-target-group', 'group' => $groupId]
				);
			}

			if ($dryRun === true) {
				$created[] = $groupId;
				continue;
			}

			$this->groupManager->createGroup($groupId);
			$created[] = $groupId;
		}

		return $created;

	}//end ensureTargets()

	/**
	 * The mapping rules declared on the connection.
	 *
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<int,array<string,mixed>> The rules.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	private function rules(array $configuration): array {
		$rules = ($configuration['mapping']['rules'] ?? []);
		if (is_array($rules) === false) {
			return [];
		}

		$valid = [];
		foreach ($rules as $rule) {
			if (is_array($rule) === true) {
				$valid[] = $rule;
			}
		}

		return $valid;

	}//end rules()

	/**
	 * Whether one rule matches one directory entry.
	 *
	 * A rule matches on a directory group name, or on a directory attribute
	 * value, which is the other shape customers arrive with.
	 *
	 * @param DirectoryEntry $entry The directory entry.
	 * @param array<string,mixed> $rule The mapping rule.
	 *
	 * @return boolean True when the rule applies to this entry.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	private function matches(DirectoryEntry $entry, array $rule): bool {
		$directoryGroup = (string)($rule['directoryGroup'] ?? '');
		if ($directoryGroup !== '') {
			return in_array($directoryGroup, $entry->getDirectoryGroups(), true);
		}

		$attribute = (string)($rule['attribute'] ?? '');
		if ($attribute === '') {
			return false;
		}

		$expected = ($rule['equals'] ?? null);
		$actual = ($entry->getAttributes()[$attribute] ?? null);

		if ($expected === null) {
			return $actual !== null && $actual !== '';
		}

		return is_scalar($actual) === true && (string)$actual === (string)$expected;

	}//end matches()
}//end class
