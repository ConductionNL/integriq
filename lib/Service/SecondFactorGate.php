<?php

/**
 * Integriq second-factor gate for the API login.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Decides whether an account has, or must have, a second factor.
 *
 * Kept apart from UserController so the controller stays within the
 * class-complexity limit of the PHPMD gate.
 *
 * @spec openspec/specs/user-management-and-login/spec.md
 */
class SecondFactorGate {

	/**
	 * Constructor.
	 *
	 * @param IRegistry       $twoFactorRegistry The two-factor provider registry.
	 * @param IConfig         $config            The system configuration, for the enforcement settings.
	 * @param IGroupManager   $groupManager      The group manager, for the enforcement groups.
	 * @param LoggerInterface $logger            The logger for the fail-closed case.
	 */
	public function __construct(
		private readonly IRegistry $twoFactorRegistry,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the account has, or must have, a second factor.
	 *
	 * /api/user/login sets the session directly and never runs core's
	 * two-factor preparation, so a session it created would count as
	 * two-factor-complete. Refusing every account that has an enabled provider
	 * (backup codes included), or that falls under the instance's two-factor
	 * enforcement, keeps the endpoint from bypassing the second factor. Fails
	 * closed: when the registry cannot be read the account is treated as
	 * requiring a second factor.
	 *
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when the login must be refused.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function requiresSecondFactor(IUser $user): bool {
		try {
			$states = $this->twoFactorRegistry->getProviderStates($user);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Integriq API login: two-factor provider states unreadable, refusing',
				['exception' => $e->getMessage()]
			);
			return true;
		}

		if (in_array(needle: true, haystack: $states, strict: true) === true) {
			return true;
		}

		return $this->isTwoFactorEnforcedFor(user: $user);
	}//end requiresSecondFactor()

	/**
	 * Whether the instance's two-factor enforcement applies to this account.
	 *
	 * Mirrors core's enforcement settings: `twofactor_enforced` switches it on,
	 * `twofactor_enforced_groups` narrows it to those groups (empty means every
	 * account), and `twofactor_enforced_excluded_groups` exempts groups unless
	 * the account is also in an enforced group.
	 *
	 * @param IUser $user The authenticated user.
	 *
	 * @return bool True when enforcement applies.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function isTwoFactorEnforcedFor(IUser $user): bool {
		$enforced = $this->config->getSystemValue('twofactor_enforced', 'false');
		if ($enforced !== true && $enforced !== 'true') {
			return false;
		}

		// Same order as core's MandatoryTwoFactor: an enforced group wins over an
		// excluded one, and naming enforced groups limits enforcement to them.
		$uid = $user->getUID();
		$enforcedGroups = (array)$this->config->getSystemValue('twofactor_enforced_groups', []);
		foreach ($enforcedGroups as $group) {
			if ($this->groupManager->isInGroup($uid, (string)$group) === true) {
				return true;
			}
		}

		foreach ((array)$this->config->getSystemValue('twofactor_enforced_excluded_groups', []) as $group) {
			if ($this->groupManager->isInGroup($uid, (string)$group) === true) {
				return false;
			}
		}

		return $enforcedGroups === [];
	}//end isTwoFactorEnforcedFor()
}//end class
