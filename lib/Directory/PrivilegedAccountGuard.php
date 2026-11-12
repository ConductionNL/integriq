<?php

/**
 * Integriq privileged account guard.
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
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Settings\IManager as ISettingsManager;
use Psr\Log\LoggerInterface;

/**
 * Decides whether an account is too privileged to be written over SCIM.
 *
 * Kept apart from ScimProvisioningService so that service stays within the
 * complexity and coupling limits of the PHPMD gate.
 *
 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
 */
class PrivilegedAccountGuard {

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
	public const PRIVILEGED_GROUPS = ['admin'];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Nextcloud's group model.
	 * @param ISubAdmin $subAdmin Tells whether an account is a group sub-admin.
	 * @param ISettingsManager $settingsManager Tells whether an account holds delegated admin settings.
	 * @param LoggerInterface $logger Logger for the fail-closed case.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly ISubAdmin $subAdmin,
		private readonly ISettingsManager $settingsManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the account must never be written over SCIM.
	 *
	 * True for a member of a privileged group and for an account that
	 * administers others without being in `admin` (iq-a-f5): a group sub-admin
	 * manages the accounts of their groups, and an account with delegated admin
	 * settings can configure the instance; rewriting the e-mail address of
	 * either hands over its password reset. Fails closed: when the question
	 * cannot be answered the account is treated as privileged.
	 *
	 * @param IUser $user The account the caller named.
	 *
	 * @return bool True when the account must not be written over SCIM.
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	public function isPrivileged(IUser $user): bool {
		try {
			foreach (self::PRIVILEGED_GROUPS as $privilegedGroup) {
				if ($this->groupManager->isInGroup($user->getUID(), $privilegedGroup) === true) {
					return true;
				}
			}

			if ($this->subAdmin->isSubAdmin($user) === true) {
				return true;
			}

			return $this->settingsManager->getAllAllowedAdminSettings($user) !== [];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[Scim] could not decide whether an account is privileged; treating it as privileged',
				['exception' => $e->getMessage()]
			);
			return true;
		}
	}//end isPrivileged()
}//end class
