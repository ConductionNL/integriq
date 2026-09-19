<?php

/**
 * Integriq SCIM Provisioning Service.
 *
 * The push half of directory synchronisation. A customer with Entra or Okta
 * sends in-en-uitdienst the moment it happens; a customer with an LDAP has
 * nothing to push and is read on a schedule instead. They are not alternatives:
 * both end at the same Nextcloud membership, so both inherit the same mapping,
 * the same open-work report and the same run record.
 *
 * A deactivation disables the account and never deletes it. Deleting an account
 * deletes the trail behind every act it performed, which is the finding an
 * auditor writes up rather than the one they were looking for.
 *
 * No password is held. Nextcloud needs one to create an account at all, so a
 * random one is generated and discarded unread: the account authenticates
 * through the identity provider, not through anything integriq knows.
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

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Creates, changes and deactivates Nextcloud accounts on a SCIM call.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
 */
class ScimProvisioningService {

	/**
	 * The SCIM 2.0 user schema urn.
	 *
	 * @var string
	 */
	public const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';

	/**
	 * The SCIM 2.0 group schema urn.
	 *
	 * @var string
	 */
	public const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';

	/**
	 * Length of the throwaway password a created account is given.
	 *
	 * @var integer
	 */
	private const GENERATED_PASSWORD_LENGTH = 48;

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Nextcloud's account model.
	 * @param IGroupManager $groupManager Nextcloud's group model.
	 * @param ISecureRandom $secureRandom Source of the throwaway password.
	 * @param OpenWorkReporter $openWorkReporter Asks consumers what a leaver still holds.
	 * @param LoggerInterface $logger Logger for provisioning outcomes.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ISecureRandom $secureRandom,
		private readonly OpenWorkReporter $openWorkReporter,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create or update an account from a SCIM user resource.
	 *
	 * @param array<string,mixed> $resource The SCIM user resource.
	 *
	 * @return array<string,mixed> The SCIM user resource as it now stands.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function upsertUser(array $resource): array {
		$userId = (string)($resource['userName'] ?? $resource['id'] ?? '');
		$user = $this->userManager->get($userId);

		if ($user === null) {
			$user = $this->userManager->createUser(
				$userId,
				$this->secureRandom->generate(self::GENERATED_PASSWORD_LENGTH)
			);

			if (($user instanceof IUser) === false) {
				$this->logger->warning('[Scim] could not create the account ' . $userId);

				return $this->toUserResource(user: null, userId: $userId);
			}
		}

		if (isset($resource['displayName']) === true) {
			$user->setDisplayName((string)$resource['displayName']);
		}

		$email = ($resource['emails'][0]['value'] ?? null);
		if ($email !== null) {
			$user->setEMailAddress((string)$email);
		}

		if (array_key_exists('active', $resource) === true) {
			$this->setActive(user: $user, active: (bool)$resource['active']);
		}

		return $this->toUserResource(user: $user, userId: $userId);

	}//end upsertUser()

	/**
	 * Deactivate an account: disable it, and never delete it.
	 *
	 * @param string $userId The Nextcloud account id.
	 * @param array<int,string> $expectedConsumers Consumer ids to ask about the account's open work.
	 *
	 * @return array<string,mixed> What the account still holds, per consumer.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function deactivateUser(string $userId, array $expectedConsumers = []): array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return [];
		}

		$this->setActive(user: $user, active: false);

		return $this->openWorkReporter->report(userId: $userId, expectedConsumers: $expectedConsumers);

	}//end deactivateUser()

	/**
	 * Read one account as a SCIM user resource.
	 *
	 * @param string $userId The Nextcloud account id.
	 *
	 * @return array<string,mixed>|null The resource, or null when there is no such account.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function getUser(string $userId): ?array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return null;
		}

		return $this->toUserResource(user: $user, userId: $userId);

	}//end getUser()

	/**
	 * List accounts as SCIM user resources.
	 *
	 * @param string $filterUserName An exact `userName` to filter on, or the empty string.
	 * @param integer $limit How many resources to answer with.
	 *
	 * @return array<int,array<string,mixed>> The resources.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function listUsers(string $filterUserName = '', int $limit = 100): array {
		if ($filterUserName !== '') {
			$resource = $this->getUser(userId: $filterUserName);
			if ($resource === null) {
				return [];
			}

			return [$resource];
		}

		$resources = [];
		foreach ($this->userManager->search('', $limit) as $user) {
			$resources[] = $this->toUserResource(user: $user, userId: $user->getUID());
		}

		return $resources;

	}//end listUsers()

	/**
	 * List groups as SCIM group resources.
	 *
	 * @param string $filterDisplayName An exact `displayName` to filter on, or the empty string.
	 * @param integer $limit How many resources to answer with.
	 *
	 * @return array<int,array<string,mixed>> The resources.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function listGroups(string $filterDisplayName = '', int $limit = 100): array {
		$groups = $this->groupManager->search($filterDisplayName, $limit);

		$resources = [];
		foreach ($groups as $group) {
			if ($filterDisplayName !== '' && $group->getGID() !== $filterDisplayName) {
				continue;
			}

			$members = [];
			foreach ($group->getUsers() as $user) {
				$members[] = ['value' => $user->getUID(), 'display' => $user->getDisplayName()];
			}

			$resources[] = [
				'schemas' => [self::GROUP_SCHEMA],
				'id' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
				'members' => $members,
			];
		}

		return $resources;

	}//end listGroups()

	/**
	 * Set a group's membership from a SCIM group resource.
	 *
	 * @param string $groupId The Nextcloud group id.
	 * @param array<int,array<string,mixed>> $members The SCIM member list.
	 *
	 * @return boolean True when the group exists and was set.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function setGroupMembers(string $groupId, array $members): bool {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return false;
		}

		$wanted = [];
		foreach ($members as $member) {
			$value = (string)($member['value'] ?? '');
			if ($value !== '') {
				$wanted[$value] = true;
			}
		}

		foreach ($group->getUsers() as $user) {
			if (isset($wanted[$user->getUID()]) === false) {
				$group->removeUser($user);
				continue;
			}

			unset($wanted[$user->getUID()]);
		}

		foreach (array_keys($wanted) as $userId) {
			$user = $this->userManager->get((string)$userId);
			if ($user !== null) {
				$group->addUser($user);
			}
		}

		return true;

	}//end setGroupMembers()

	/**
	 * Enable or disable an account.
	 *
	 * @param IUser $user The account.
	 * @param boolean $active Whether the account should be enabled.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function setActive(IUser $user, bool $active): void {
		$user->setEnabled($active);

		$state = 'inactive';
		if ($active === true) {
			$state = 'active';
		}

		$this->logger->info('[Scim] account ' . $user->getUID() . ' set to ' . $state);

	}//end setActive()

	/**
	 * Shape an account as a SCIM user resource.
	 *
	 * @param IUser|null $user The account, or null when it could not be created.
	 * @param string $userId The account id.
	 *
	 * @return array<string,mixed> The SCIM user resource.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	private function toUserResource(?IUser $user, string $userId): array {
		if ($user === null) {
			return ['schemas' => [self::USER_SCHEMA], 'id' => $userId, 'userName' => $userId, 'active' => false];
		}

		$emails = [];
		$email = $user->getEMailAddress();
		if ($email !== null && $email !== '') {
			$emails[] = ['value' => $email, 'primary' => true];
		}

		$groups = [];
		foreach ($this->groupManager->getUserGroups($user) as $group) {
			$groups[] = ['value' => $group->getGID(), 'display' => $group->getDisplayName()];
		}

		return [
			'schemas' => [self::USER_SCHEMA],
			'id' => $user->getUID(),
			'userName' => $user->getUID(),
			'displayName' => $user->getDisplayName(),
			'active' => $user->isEnabled(),
			'emails' => $emails,
			'groups' => $groups,
		];

	}//end toUserResource()
}//end class
