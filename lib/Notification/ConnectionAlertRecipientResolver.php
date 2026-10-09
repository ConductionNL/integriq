<?php

/**
 * Integriq ConnectionAlertRecipientResolver: who hears about an opened
 * connection alert.
 *
 * @category Notification
 * @package  OCA\Integriq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Notification;

use OCA\Integriq\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * The members of the group named in the app setting `connection_alert_group`.
 *
 * The `threshold-passed` rule on `connection_alert` names this class as an
 * `expression` recipient, and so does every other integriq alert rule in the
 * register (failed jobs, deliveries, payments, approval requests). So
 * OpenRegister's engine sends the notification and integriq never calls the
 * notification manager. Until an administrator names
 * another group, the members of `admin` are told (Ruben, 29 Sep 2026): every
 * instance has that group, where the `openconnector-ops` the change first
 * named exists on none. An empty setting counts as unset.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 * @spec openspec/specs/openconnector-notifications/spec.md
 */
class ConnectionAlertRecipientResolver implements RecipientResolverInterface {

	/**
	 * The app setting that names the group.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'connection_alert_group';

	/**
	 * The group told when no other group is named.
	 *
	 * @var string
	 */
	public const DEFAULT_GROUP = 'admin';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IGroupManager $groupManager The group manager.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uids of the named group's members, or none when that group does not exist.
	 *
	 * @param ObjectEntity $object The alert.
	 * @param array<string, mixed> $context The trigger's extras.
	 *
	 * @return array<int, string> Nextcloud uids.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface hands every
	 *   resolver the object and the context; who hears about an alert depends
	 *   on the setting alone, not on the alert.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$groupId = $this->namedGroup();
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			$this->logger->warning(
				'[ConnectionAlertRecipientResolver] the group named for connection alerts does not exist; nobody is notified',
				['group' => $groupId]
			);
			return [];
		}

		$uids = [];
		foreach ($group->getUsers() as $user) {
			$uids[] = $user->getUID();
		}

		return array_values(array_unique($uids));
	}//end resolve()

	/**
	 * The group named for connection alerts, or the admin group when none is.
	 *
	 * @return string A group id, never empty.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	public function namedGroup(): string {
		$groupId = trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
		if ($groupId === '') {
			return self::DEFAULT_GROUP;
		}

		return $groupId;
	}//end namedGroup()
}//end class
