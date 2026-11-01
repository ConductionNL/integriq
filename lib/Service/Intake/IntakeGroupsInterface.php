<?php

/**
 * Contract of the IntakeGroups.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Intake
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

namespace OCA\Integriq\Service\Intake;

use OCP\IGroup;

/**
 * Public operations of {@see IntakeGroups} that other components call.
 */
interface IntakeGroupsInterface {
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
	 * The group of the digital post service account: create, read and update on `digitalPostMessage`.
	 *
	 * @var string
	 */
	public const DIGITAL_POST_SENDERS = 'digitale-post-verzenders';

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
		self::DIGITAL_POST_SENDERS,
	];

	/**
	 * Create the group when it does not exist yet.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return IGroup|null The group, or null when the group backend refused it.
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-2
	 */
	public function ensure(string $groupId): ?IGroup;

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
	public function isMember(string $groupId, string $userId): bool;

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
	public function hasMembers(string $groupId): bool;

	/**
	 * Its state for a settings section: the group id and whether it is empty.
	 *
	 * @param string $groupId The group id.
	 *
	 * @return array{id: string, empty: bool}
	 *
	 * @spec openspec/changes/intake-handler-group-notice/specs/intake-access/spec.md#requirement-the-connection-settings-say-when-nobody-can-read-the-intake-records-req-iac-001
	 */
	public function describe(string $groupId): array;

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
	public function enrol(string $groupId, string $userId): bool;

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
	public function withdraw(string $groupId, string $userId): void;
}
