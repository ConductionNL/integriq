<?php

/**
 * IntakeAccountCheck
 *
 * Checks the Nextcloud account an intake connection acts as, for the settings
 * forms of the DSO and Open Formulieren connections: it puts the chosen
 * account in the connection's intake group while its rights are checked,
 * warns for an administrator account, answers a field error with a 400, and
 * takes the previous account out of the intake group after a save.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-dso-connections-account-is-chosen-and-checked-by-an-administrator-req-dso-071
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\Intake\IntakeGroupsInterface;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\Portal\PortalConnectionInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;

/**
 * The account checks one intake connection's settings form shares with the other.
 *
 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-verzoeken-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-dso-072
 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
 */
final class IntakeAccountCheck {

	/**
	 * Constructor.
	 *
	 * @param PortalConnectionInterface|OpenFormulierenConnection $connection   Checks the account's rights.
	 * @param IntakeGroupsInterface                               $groups       Enrols and withdraws the account.
	 * @param IGroupManager                                       $groupManager Tells an administrator account apart.
	 * @param IL10N                                               $l            Warnings.
	 * @param string                                              $intakeGroup  The connection's intake group.
	 */
	public function __construct(
		private readonly PortalConnectionInterface|OpenFormulierenConnection $connection,
		private readonly IntakeGroupsInterface $groups,
		private readonly IGroupManager $groupManager,
		private readonly IL10N $l,
		private readonly string $intakeGroup,
	) {

	}//end __construct()

	/**
	 * The 400 answer for an account's field error, or null when there is none.
	 *
	 * @param string|null $fieldError The field error, or null.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-verzoeken-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-dso-072
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
	 */
	public function refusal(?string $fieldError): ?JSONResponse {
		if ($fieldError === null) {
			return null;
		}

		return new JSONResponse(
			['errors' => [$fieldError], 'fieldErrors' => ['userId' => $fieldError]],
			Http::STATUS_BAD_REQUEST
		);

	}//end refusal()

	/**
	 * The rights the account lacks, checked while it is in the intake group.
	 *
	 * The authorization block grants the intake group, so the chosen account
	 * joins it before its rights are checked. It leaves again when the check
	 * still refuses it and it was not a member before.
	 *
	 * @param string $userId The chosen uid.
	 *
	 * @return list<string>|null The missing rights, or null when they could not be checked.
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-verzoeken-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-dso-072
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
	 */
	public function missingRights(string $userId): ?array {
		$wasMember = $this->groups->isMember(groupId: $this->intakeGroup, userId: $userId);
		$this->groups->enrol(groupId: $this->intakeGroup, userId: $userId);

		$missing = $this->connection->missingRights(userId: $userId);
		if ($missing !== null && $missing !== [] && $wasMember === false) {
			$this->groups->withdraw(groupId: $this->intakeGroup, userId: $userId);
		}

		return $missing;

	}//end missingRights()

	/**
	 * Warn, without refusing, when the account is an administrator.
	 *
	 * @param string       $userId   The chosen uid.
	 * @param list<string> $warnings Warnings to add to; passed by reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-verzoeken-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-dso-072
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
	 */
	public function warnAdministrator(string $userId, array &$warnings): void {
		if ($this->groupManager->isAdmin($userId) === true) {
			$warnings[] = $this->l->t('Account %s is an administrator. A dedicated account keeps the audit trail readable.', [$userId]);
		}

	}//end warnAdministrator()

	/**
	 * Take the account the connection used before out of the intake group.
	 *
	 * @param ObjectEntity|null $consumer The consumer as it was before the save.
	 * @param string            $userId   The account it has now, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/dso-omgevingsloket/spec.md#requirement-verzoeken-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-dso-072
	 * @spec openspec/changes/bsn-intake-records-access-rules/specs/open-formulieren-intake/spec.md#requirement-submissions-are-open-to-the-intake-account-the-handlers-and-administrators-only-req-008
	 */
	public function withdrawPrevious(?ObjectEntity $consumer, string $userId): void {
		$previous = (string)(($consumer?->getObject() ?? [])['userId'] ?? '');
		if ($previous !== '' && $previous !== $userId) {
			$this->groups->withdraw(groupId: $this->intakeGroup, userId: $previous);
		}

	}//end withdrawPrevious()
}//end class
