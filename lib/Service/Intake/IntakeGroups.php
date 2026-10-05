<?php

/**
 * Integriq Intake Groups.
 *
 * The Nextcloud groups the authorization blocks of `dso_verzoek`,
 * `openformulieren_submission`, `intake_message` and `verdict` name. All four
 * hold what a citizen or a partner sent in (`intake_message` can hold a BSN),
 * so OpenRegister grants them to these groups only (plus administrators):
 *
 * - `dso-intake`, `openformulieren-intake`, `intakekanalen-intake` and
 *   `verdicts-intake`: the intake accounts. They may create and update.
 *   integriq puts the account of a connection in its group when an
 *   administrator chooses it, and the repair step does the same for
 *   connections that already have one.
 * - `dso-behandelaars`, `openformulieren-behandelaars`,
 *   `intakekanalen-behandelaars` and `verdicts-behandelaars`: the handlers.
 *   They may read and update. An administrator fills them in Nextcloud's user
 *   management (or through LDAP, SAML or OIDC group sync).
 *
 * OpenRegister can name a single account (`user:<uid>`), but the intake
 * account differs per instance and a register file is the same everywhere,
 * so the rules name groups. The names are fixed: the register import rewrites
 * a schema's authorization block on every upgrade, so a name configured on
 * one instance would be overwritten by the next import.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Intake;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the intake and handler groups, and enrols intake accounts.
 *
 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
 */
class IntakeGroups {

	/**
	 * The group of the DSO STAM intake account: create and update on `dso_verzoek`.
	 *
	 * @var string
	 */
	public const DSO_INTAKE = 'dso-intake';

	/**
	 * The group of the DSO handlers: read and update on `dso_verzoek`.
	 *
	 * @var string
	 */
	public const DSO_HANDLERS = 'dso-behandelaars';

	/**
	 * The group of the Open Formulieren intake account: create and update on `openformulieren_submission`.
	 *
	 * @var string
	 */
	public const OPEN_FORMULIEREN_INTAKE = 'openformulieren-intake';

	/**
	 * The group of the Open Formulieren handlers: read and update on `openformulieren_submission`.
	 *
	 * @var string
	 */
	public const OPEN_FORMULIEREN_HANDLERS = 'openformulieren-behandelaars';

	/**
	 * The group of the intake channel accounts: create and update on `intake_message`.
	 *
	 * @var string
	 */
	public const INTAKE_CHANNELS_INTAKE = 'intakekanalen-intake';

	/**
	 * The group of the intake message handlers: read and update on `intake_message`.
	 *
	 * @var string
	 */
	public const INTAKE_CHANNELS_HANDLERS = 'intakekanalen-behandelaars';

	/**
	 * The group of the verdicts webhook account: create and update on `verdict`.
	 *
	 * @var string
	 */
	public const VERDICTS_INTAKE = 'verdicts-intake';

	/**
	 * The group of the verdict handlers: read and update on `verdict`.
	 *
	 * @var string
	 */
	public const VERDICTS_HANDLERS = 'verdicts-behandelaars';

	/**
	 * Every group the authorization blocks name.
	 *
	 * @var list<string>
	 */
	public const ALL = [
		self::DSO_INTAKE,
		self::DSO_HANDLERS,
		self::OPEN_FORMULIEREN_INTAKE,
		self::OPEN_FORMULIEREN_HANDLERS,
		self::INTAKE_CHANNELS_INTAKE,
		self::INTAKE_CHANNELS_HANDLERS,
		self::VERDICTS_INTAKE,
		self::VERDICTS_HANDLERS,
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager Creates groups and changes membership.
	 * @param IUserManager    $userManager  Resolves the account to enrol.
	 * @param LoggerInterface $logger       Records what changed.
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/design.md
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create the group when it does not exist yet.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return IGroup|null The group, or null when the group backend refused it.
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-2
	 */
	public function ensure(string $groupId): ?IGroup {
		$group = $this->groupManager->get($groupId);
		if ($group !== null) {
			return $group;
		}

		try {
			$group = $this->groupManager->createGroup($groupId);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[IntakeGroups] could not create group ' . $groupId,
				['exception' => $exception->getMessage()]
			);
			return null;
		}

		if ($group !== null) {
			$this->logger->info('[IntakeGroups] created group ' . $groupId);
		}

		return $group;

	}//end ensure()

	/**
	 * Whether the account is a member of the group.
	 *
	 * @param string $groupId The group id.
	 * @param string $userId  The uid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-2
	 */
	public function isMember(string $groupId, string $userId): bool {
		return $this->groupManager->isInGroup($userId, $groupId);

	}//end isMember()

	/**
	 * Whether the group has at least one member.
	 *
	 * A group that does not exist yet has none. The settings sections use
	 * this to say that nobody can read the intake records yet.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/intake-handler-group-notice/specs/intake-access/spec.md#requirement-the-connection-settings-say-when-nobody-can-read-the-intake-records-req-iac-001
	 */
	public function hasMembers(string $groupId): bool {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			return false;
		}

		return (int)$group->count() > 0;

	}//end hasMembers()

	/**
	 * Its state for a settings section: the group id and whether it is empty.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return array{id: string, empty: bool}
	 *
	 * @spec openspec/changes/intake-handler-group-notice/specs/intake-access/spec.md#requirement-the-connection-settings-say-when-nobody-can-read-the-intake-records-req-iac-001
	 */
	public function describe(string $groupId): array {
		return ['id' => $groupId, 'empty' => ($this->hasMembers(groupId: $groupId) === false)];

	}//end describe()

	/**
	 * Put the account in the group, creating the group when needed.
	 *
	 * @param string $groupId The group id.
	 * @param string $userId  The uid.
	 *
	 * @return bool True when the account is a member afterwards.
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-2
	 */
	public function enrol(string $groupId, string $userId): bool {
		$user = $this->userManager->get($userId);
		$group = $this->ensure(groupId: $groupId);
		if ($user === null || $group === null) {
			return false;
		}

		if ($group->inGroup($user) === true) {
			return true;
		}

		try {
			$group->addUser($user);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[IntakeGroups] could not add ' . $userId . ' to ' . $groupId,
				['exception' => $exception->getMessage()]
			);
			return false;
		}

		$this->logger->info('[IntakeGroups] added ' . $userId . ' to ' . $groupId);

		return true;

	}//end enrol()

	/**
	 * Take the account out of the group, when it is in it.
	 *
	 * @param string $groupId The group id.
	 * @param string $userId  The uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-2
	 */
	public function withdraw(string $groupId, string $userId): void {
		$user = $this->userManager->get($userId);
		$group = $this->groupManager->get($groupId);
		if ($user === null || $group === null || $group->inGroup($user) === false) {
			return;
		}

		try {
			$group->removeUser($user);
			$this->logger->info('[IntakeGroups] removed ' . $userId . ' from ' . $groupId);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[IntakeGroups] could not remove ' . $userId . ' from ' . $groupId,
				['exception' => $exception->getMessage()]
			);
		}

	}//end withdraw()
}//end class
