<?php

/**
 * Integriq DeveloperPortalAccess.
 *
 * Who a developer is: a Nextcloud account in the group an administrator names
 * in the admin settings, `integriq-developers` until one is named. Guest
 * accounts from the Guests app count like any other member
 * (access-developer-portal-and-subscriptions D1).
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Reads and judges membership of the developer group.
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
 */
class DeveloperPortalAccess {

	/**
	 * App config key holding the developer group id.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'developer_portal_group';

	/**
	 * The group used until an administrator names another.
	 *
	 * @var string
	 */
	public const DEFAULT_GROUP = 'integriq-developers';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    The app configuration.
	 * @param IGroupManager $groupManager The group manager.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The developer group in force.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#d1-who-a-developer-is
	 */
	public function group(): string {
		$group = trim($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, self::DEFAULT_GROUP));
		if ($group === '') {
			return self::DEFAULT_GROUP;
		}

		return $group;
	}//end group()

	/**
	 * Whether an account is a developer.
	 *
	 * @param IUser $user The account.
	 *
	 * @return bool True for a member of the developer group.
	 *
	 * @spec openspec/specs/api-product-gateway/spec.md#requirement-a-developer-finds-the-published-api-products-req-devp-001
	 */
	public function isDeveloper(IUser $user): bool {
		return $this->groupManager->isInGroup($user->getUID(), $this->group());
	}//end isDeveloper()

	/**
	 * How many accounts are in the developer group, or null when the group does not exist.
	 *
	 * The admin page shows it, so an empty group that hides the portal from
	 * everyone is visible (design Risks).
	 *
	 * @return int|null The member count.
	 *
	 * @spec openspec/changes/archive/2026-11-25-access-developer-portal-and-subscriptions/design.md#risks
	 */
	public function memberCount(): ?int {
		$group = $this->groupManager->get($this->group());
		if ($group === null) {
			return null;
		}

		$count = $group->count();
		if (is_int($count) === false) {
			return null;
		}

		return $count;
	}//end memberCount()
}//end class
