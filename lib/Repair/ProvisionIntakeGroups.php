<?php

/**
 * Integriq ProvisionIntakeGroups repair step.
 *
 * `dso_verzoek` and `openformulieren_submission` carry BSNs. Since
 * bsn-intake-records-access-rules their authorization blocks grant them to
 * four groups only (plus administrators): the intake groups `dso-intake` and
 * `openformulieren-intake`, and the handler groups `dso-behandelaars` and
 * `openformulieren-behandelaars`. Since intake-message-and-verdict-access-rules
 * `intake_message` and `verdict` follow the same pattern with
 * `intakekanalen-intake`, `intakekanalen-behandelaars`, `verdicts-intake` and
 * `verdicts-behandelaars`.
 *
 * This step makes an upgraded instance keep working. It creates the eight
 * groups when they are missing, and puts the account each connection
 * already acts as in its intake group. Without that, the account that stored
 * submissions until now would lose `create` and `update`, and every push would
 * answer 503. It never adds anyone to a handler group: who may read BSNs is
 * an administrator's choice.
 *
 * Idempotent. The consumer reads are engine reads of admin configuration;
 * nothing is written to OpenRegister.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
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
 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Intake\IntakeGroups;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Creates the intake and handler groups and enrols the existing intake accounts.
 *
 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-3
 */
class ProvisionIntakeGroups implements IRepairStep {

	/**
	 * The intake group of each connection type.
	 *
	 * @var array<string, string>
	 */
	private const INTAKE_GROUP_OF = [
		DsoConnection::AUTHORIZATION_TYPE => IntakeGroups::DSO_INTAKE,
		OpenFormulierenConnection::AUTHORIZATION_TYPE => IntakeGroups::OPEN_FORMULIEREN_INTAKE,
	];

	/**
	 * Constructor.
	 *
	 * @param IntakeGroups       $groups    Creates the groups and enrols the accounts.
	 * @param ContainerInterface $container Resolves the OpenRegister-backed connection lazily.
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-3
	 */
	public function __construct(
		private readonly IntakeGroups $groups,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-3
	 */
	public function getName(): string {
		return 'Create the intake and handler groups for DSO verzoeken, Open Formulieren submissions, intake messages and verdicts';

	}//end getName()

	/**
	 * Create the groups and enrol each connection's account in its intake group.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/tasks.md#task-3
	 */
	public function run(IOutput $output): void {
		foreach (IntakeGroups::ALL as $groupId) {
			if ($this->groups->ensure(groupId: $groupId) === null) {
				$output->warning('Could not create group ' . $groupId . '.');
			}
		}

		try {
			$connection = $this->container->get(DsoConnection::class);
		} catch (Throwable $exception) {
			$output->warning('Intake accounts not enrolled: OpenRegister is not available (' . $exception->getMessage() . ').');
			return;
		}

		foreach ($this->intakeGroupOf() as $type => $groupId) {
			foreach ($connection->findConsumers(authorizationType: $type) as $consumer) {
				$userId = (string)($consumer->getObject()['userId'] ?? '');
				if ($userId === '' || $this->groups->isMember(groupId: $groupId, userId: $userId) === true) {
					continue;
				}

				if ($this->groups->enrol(groupId: $groupId, userId: $userId) === true) {
					$output->info('Added the ' . $type . ' intake account ' . $userId . ' to group ' . $groupId . '.');
				}
			}
		}

	}//end run()

	/**
	 * The intake group of each consumer type: DSO, Open Formulieren, and every
	 * webhook whose schema grants one (the intake channels and the verdicts).
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/intake-message-and-verdict-access-rules/specs/intake-access/spec.md#scenario-an-upgraded-instance-keeps-its-webhook-accounts
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WebhookProfiles is a static catalogue of constants, as in WebhookConnectionsSettingsController.
	 */
	private function intakeGroupOf(): array {
		$groupOf = self::INTAKE_GROUP_OF;
		foreach (WebhookProfiles::all() as $profile) {
			if ($profile->intakeGroup !== null) {
				$groupOf[$profile->authorizationType] = $profile->intakeGroup;
			}
		}

		return $groupOf;

	}//end intakeGroupOf()
}//end class
