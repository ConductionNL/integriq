<?php

/**
 * Contract of the ScimProvisioningService.
 *
 * @category Contract
 * @package  OCA\Integriq\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

use OCA\Integriq\Exception\DirectorySyncRefusalException;

/**
 * Public operations of {@see ScimProvisioningService} that other components call.
 */
interface ScimProvisioningServiceInterface {
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
	public function upsertUser(array $resource, string $consumerLabel): array;

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
	public function deactivateUser(string $userId, string $consumerLabel, array $expectedConsumers = []): array;

	/**
	 * Read one account as a SCIM user resource.
	 *
	 * @param string $userId The Nextcloud account id.
	 *
	 * @return array<string,mixed>|null The resource, or null when there is no such account.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
	 */
	public function getUser(string $userId): ?array;

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
	public function listUsers(string $filterUserName = '', int $limit = 100): array;

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
	public function listGroups(string $filterDisplayName = '', int $limit = 100): array;

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
	public function setGroupMembers(string $groupId, array $members, string $consumerLabel): bool;
}
