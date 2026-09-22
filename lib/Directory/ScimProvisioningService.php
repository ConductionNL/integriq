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

use OCA\Integriq\Exception\DirectorySyncRefusalException;
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
	 * The groups SCIM may never write, whatever the caller presents.
	 *
	 * Not configurable, by design. A deployment that could switch this off would
	 * eventually be a deployment that had.
	 *
	 * @var array<int,string>
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	private const PRIVILEGED_GROUPS = ['admin'];

	/**
	 * What a caller is told when it names a privileged group.
	 *
	 * Deliberately says nothing about which groups are privileged or why. A
	 * caller probing the boundary should learn only that it exists.
	 *
	 * @var string
	 */
	private const REFUSAL_DETAIL_PRIVILEGED = 'This group cannot be managed over SCIM.';

	/**
	 * What a caller is told when it names a privileged ACCOUNT.
	 *
	 * Same reasoning as {@see REFUSAL_DETAIL_PRIVILEGED}: the caller learns that
	 * a boundary exists and nothing about where it runs.
	 *
	 * @var string
	 */
	private const REFUSAL_DETAIL_PRIVILEGED_USER = 'This account cannot be managed over SCIM.';

	/**
	 * The most resources one list call may answer with.
	 *
	 * SCIM lets the caller name a page size and the consumer supplied it
	 * unbounded, so a single request could walk the whole account estate —
	 * every display name and e-mail address on the instance — in one answer
	 * (integriq#2104 review 5264751700, blocker 3). The cap is applied to the
	 * value the caller asked for, not substituted for it, so a smaller page
	 * size is still honoured.
	 *
	 * @var integer
	 */
	private const MAX_PAGE_SIZE = 200;

	/**
	 * Clamp a caller-supplied SCIM `count` to a page size that is safe to pass on.
	 *
	 * Shared by both list routes deliberately. The first cap shipped as a bare
	 * `min()` inside `listUsers()`, which capped the ceiling — the direction that
	 * was never the risk, since a caller asking for more than the cap always got
	 * the cap — and left the bottom open. Nextcloud's `Database::fixLimit()`
	 * returns the limit only when `is_int($limit) && $limit >= 0` and `null`
	 * otherwise, and `null` means UNBOUNDED, so `?count=-1` walked the whole
	 * estate. `listGroups()` meanwhile never got a cap at all and answers with
	 * every group's complete membership. Both are integriq#2104 review
	 * 5266971176; one helper so the pair cannot drift apart again.
	 *
	 * RFC 7644 §3.4.2.4: `count` is a non-negative integer, "a negative value
	 * SHALL be interpreted as '0'", and 0 means no resources are returned. The
	 * callers therefore answer an empty list without reaching the backend at all,
	 * rather than passing 0 down and trusting every user/group backend to read it
	 * as `LIMIT 0` instead of "no limit".
	 *
	 * @param integer $requested The caller's `count`, unvalidated.
	 *
	 * @return integer A page size in [0, MAX_PAGE_SIZE].
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007
	 */
	private function pageSize(int $requested): int {
		return max(0, min($requested, self::MAX_PAGE_SIZE));

	}//end pageSize()

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Nextcloud's account model.
	 * @param IGroupManager $groupManager Nextcloud's group model.
	 * @param ISecureRandom $secureRandom Source of the throwaway password.
	 * @param OpenWorkReporter $openWorkReporter Asks consumers what a leaver still holds.
	 * @param LoggerInterface $logger Logger for provisioning outcomes.
	 * @param DirectorySource $directorySource Supplies the configured directory connections.
	 * @param GroupMappingResolver $mappingResolver Supplies the groups a connection declares it manages.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ISecureRandom $secureRandom,
		private readonly OpenWorkReporter $openWorkReporter,
		private readonly LoggerInterface $logger,
		private readonly DirectorySource $directorySource,
		private readonly GroupMappingResolver $mappingResolver,
	) {

	}//end __construct()

	/**
	 * Refuse a group write, naming the group for the log and not for the caller.
	 *
	 * @param string $groupId The group that was named.
	 * @param string $consumerLabel The consumer the call was answered as.
	 * @param string $reason What was refused, for the operator reading the log.
	 * @param string $detail What the caller is told.
	 *
	 * @return never
	 *
	 * @throws DirectorySyncRefusalException Always — that is the point.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	private function refuse(string $groupId, string $consumerLabel, string $reason, string $detail): never {
		$this->logger->warning(
			'[Scim] consumer ' . $consumerLabel . ' was refused a write to the group ' . $groupId . ': ' . $reason
		);

		throw new DirectorySyncRefusalException(
			message: $reason,
			context: ['group' => $groupId, 'consumer' => $consumerLabel, 'detail' => $detail]
		);

	}//end refuse()

	/**
	 * Refuse the write when the named account holds a privileged group membership.
	 *
	 * The consumer resolved by {@see \OCA\Integriq\Controller\ScimController}
	 * was recorded in the log line and nothing else, so a valid consumer key
	 * could disable `admin`, or rewrite any account's e-mail address and thereby
	 * take over its password reset (integriq#2104 review 5264751700, blocker 3).
	 * `assertWritableGroup()` guards the group ROUTES; this guards the user ones,
	 * which reach the same privilege by a different door.
	 *
	 * An account that does not exist is not refused — there is nothing to
	 * protect yet, and `upsertUser()` legitimately creates accounts. A newly
	 * created account holds no group membership, so it cannot be privileged.
	 *
	 * The per-consumer scoping this does NOT do — which consumer may manage
	 * which accounts — needs a permission property on `consumer` and is tracked
	 * as ConductionNL/integriq#2112.
	 *
	 * @param string $userId The account the caller named.
	 * @param string $consumerLabel The consumer the call was answered as.
	 *
	 * @return void
	 *
	 * @throws DirectorySyncRefusalException When the account is privileged.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-a-scim-call-is-answered-as-a-named-consumer-req-ds-007
	 */
	private function assertWritableUser(string $userId, string $consumerLabel): void {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return;
		}

		foreach (self::PRIVILEGED_GROUPS as $privilegedGroup) {
			if ($this->groupManager->isInGroup($userId, $privilegedGroup) !== true) {
				continue;
			}

			$this->logger->warning(
				'[Scim] consumer ' . $consumerLabel . ' was refused a write to the account ' . $userId
				. ': the account holds a privileged group membership'
			);

			throw new DirectorySyncRefusalException(
				message: 'the account holds a privileged group membership and is never writable over SCIM',
				context: [
					'user' => $userId,
					'consumer' => $consumerLabel,
					'detail' => self::REFUSAL_DETAIL_PRIVILEGED_USER,
				]
			);
		}

	}//end assertWritableUser()

	/**
	 * Refuse the write unless this group is one SCIM may manage.
	 *
	 * @param string $groupId The group the caller named.
	 * @param string $consumerLabel The consumer the call was answered as.
	 *
	 * @return void
	 *
	 * @throws DirectorySyncRefusalException When the group is privileged, or no
	 *                                       directory connection declares it managed.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	private function assertWritableGroup(string $groupId, string $consumerLabel): void {
		// Checked first and independently of the allow-list, so that an operator
		// who mistakenly declares `admin` managed still cannot write it OVER SCIM.
		// Scope matters and the comment used to overstate it: this guard sits on
		// the SCIM route only. `DirectorySyncService::write()` calls
		// IGroupManager::addUser()/removeUser() directly and honours exactly the
		// misconfiguration described above, as does GroupMappingResolver's
		// createGroup(). That path is admin-triggered — DirectorySyncController is
		// `#[AuthorizedAdminSetting]`, and DirectorySyncJob runs as the system —
		// so it is a narrower exposure, not a closed one (integriq#2104 review
		// 5264751700). Lifting the assertion to a shared place both routes call is
		// its own change.
		if (in_array(needle: $groupId, haystack: self::PRIVILEGED_GROUPS, strict: true) === true) {
			$this->refuse(
				groupId: $groupId,
				consumerLabel: $consumerLabel,
				reason: 'the group is privileged and is never writable over SCIM',
				detail: self::REFUSAL_DETAIL_PRIVILEGED
			);
		}

		if (in_array(needle: $groupId, haystack: $this->managedGroupUnion(), strict: true) === true) {
			return;
		}

		// This refusal names the group to the caller, unlike the one above. An
		// unmanaged group is a configuration mistake an operator has to be able
		// to diagnose; a privileged group is a boundary an attacker should learn
		// nothing about.
		$this->refuse(
			groupId: $groupId,
			consumerLabel: $consumerLabel,
			reason: 'no directory connection declares the group as managed',
			detail: 'The group \'' . $groupId . '\' is not managed by a directory connection.'
		);

	}//end assertWritableGroup()

	/**
	 * Every group any configured directory connection declares that it manages.
	 *
	 * Read in system context: the connection is consulted here as policy, never
	 * rendered to the caller. An RBAC read would answer nothing for a consumer
	 * that is not an administrator, and an empty answer is indistinguishable
	 * from "this instance manages no groups" — which would refuse every write
	 * rather than the intended ones.
	 *
	 * @return array<int,string> The union of declared managed groups, possibly empty.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	private function managedGroupUnion(): array {
		$union = [];
		foreach ($this->directorySource->findConnectionsForPolicy() as $connection) {
			$configuration = (array)($connection->getObject()['configuration'] ?? []);
			foreach ($this->mappingResolver->managedGroups(configuration: $configuration) as $groupId) {
				$union[$groupId] = true;
			}
		}

		return array_keys($union);

	}//end managedGroupUnion()

	/**
	 * Create or update an account from a SCIM user resource.
	 *
	 * @param array<string,mixed> $resource The SCIM user resource.
	 * @param string $consumerLabel The consumer the call was answered as.
	 *
	 * @return array<string,mixed> The SCIM user resource as it now stands.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function upsertUser(array $resource, string $consumerLabel): array {
		$userId = (string)($resource['userName'] ?? $resource['id'] ?? '');
		$this->assertWritableUser(userId: $userId, consumerLabel: $consumerLabel);
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
	 * @param string $consumerLabel The consumer the call was answered as.
	 * @param array<int,string> $expectedConsumers Consumer ids to ask about the account's open work.
	 *
	 * @return array<string,mixed> What the account still holds, per consumer.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function deactivateUser(string $userId, string $consumerLabel, array $expectedConsumers = []): array {
		$this->assertWritableUser(userId: $userId, consumerLabel: $consumerLabel);

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
	 * @param integer $limit How many resources to answer with; clamped to [0, MAX_PAGE_SIZE] by pageSize().
	 *
	 * @return array<int,array<string,mixed>> The resources.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function listUsers(string $filterUserName = '', int $limit = 100): array {
		// Clamped BEFORE the exact-filter branch, so both list routes agree on
		// `count <= 0`. listGroups() clamps first and answers `[]`; this route
		// used to answer one resource for `?filter=userName eq "x"&count=-1`
		// (integriq#2104 review 5266971176). RFC 7644 §3.4.2.4 makes 0 mean "no
		// resources", and a filter does not change that.
		$pageSize = $this->pageSize(requested: $limit);
		if ($pageSize === 0) {
			return [];
		}

		if ($filterUserName !== '') {
			$resource = $this->getUser(userId: $filterUserName);
			if ($resource === null) {
				return [];
			}

			return [$resource];
		}

		$resources = [];
		foreach ($this->userManager->search('', $pageSize) as $user) {
			$resources[] = $this->toUserResource(user: $user, userId: $user->getUID());
		}

		return $resources;

	}//end listUsers()

	/**
	 * List groups as SCIM group resources.
	 *
	 * @param string $filterDisplayName An exact `displayName` to filter on, or the empty string.
	 * @param integer $limit How many resources to answer with; clamped to [0, MAX_PAGE_SIZE] by pageSize().
	 *
	 * @return array<int,array<string,mixed>> The resources.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function listGroups(string $filterDisplayName = '', int $limit = 100): array {
		$pageSize = $this->pageSize(requested: $limit);
		if ($pageSize === 0) {
			return [];
		}

		$groups = $this->groupManager->search($filterDisplayName, $pageSize);

		$resources = [];
		foreach ($groups as $group) {
			if ($filterDisplayName !== '' && $group->getGID() !== $filterDisplayName) {
				continue;
			}

			// DELIBERATELY UNBOUNDED, and the bound belongs elsewhere.
			//
			// `pageSize()` above caps how many GROUPS answer, not how much data,
			// so `?count=1` returns one group carrying its complete membership —
			// on an `everyone`-style group, every uid and display name on the
			// instance (integriq#2104 review, Wilco). That walks around the cap
			// the Users route has.
			//
			// It is not fixed by trimming this list, because SCIM puts the data
			// here on purpose. RFC 7643 §4.1.2 on `User.groups`: "Since this
			// attribute has a mutability of `readOnly`, group membership changes
			// MUST be applied via the `Group` Resource" — so Group is the
			// AUTHORITATIVE membership resource and `User.groups` is a derived
			// projection. `Group.members` carries `returned: "default"` (§4.2),
			// meaning a conformant provider answers it unless the client narrows
			// the request. We already accept the write side here
			// (`PATCH /Groups/{id}`); refusing the read side would leave us
			// conformant in neither direction.
			//
			// The RFC also offers no bound for a single large group: it has
			// pagination for RESOURCES (§3.4.2.4) and attribute selection
			// (§3.4.2.5), but none for a multi-valued attribute. So the limit
			// cannot be a protocol one — it is a question of which consumer key
			// may read this at all, which is ConductionNL/integriq#2112.
			//
			// Pinned by testAGroupsFullMembershipIsReturned() so this stays a
			// decision rather than drifting into an accident.
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
	 * @param string $consumerLabel The consumer this call was answered as, for the log.
	 *
	 * @return boolean True when the group exists and was set, false when it does not exist.
	 *
	 * @throws DirectorySyncRefusalException When the group is privileged, or is not
	 *                                       declared managed by any directory connection.
	 *                                       A refusal is distinct from the false return:
	 *                                       false means "no such group", the exception
	 *                                       means "not yours to write".
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	public function setGroupMembers(string $groupId, array $members, string $consumerLabel): bool {
		// Refuse before every read and every write, including the lookup below.
		// The ordering is the requirement, not an optimisation: this method
		// reconciles membership, so it REMOVES anyone absent from $members. A
		// refusal placed after the removal loop would still empty the group it
		// was meant to protect.
		$this->assertWritableGroup(groupId: $groupId, consumerLabel: $consumerLabel);

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
