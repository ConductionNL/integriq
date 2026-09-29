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
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
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
 * `expression` recipient, so OpenRegister's engine sends the notification and
 * integriq never calls the notification manager. There is no default group:
 * the group the change first named, `openconnector-ops`, exists on no
 * instance, and which group looks after connections is each organisation's
 * call. Until an administrator names one, an opened alert notifies nobody and
 * shows on the alerts page only.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
 */
class ConnectionAlertRecipientResolver implements RecipientResolverInterface {

	/**
	 * The app setting that names the group.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'connection_alert_group';

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
	 * The uids of the named group's members, or none.
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
	 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$groupId = trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
		if ($groupId === '') {
			return [];
		}

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
}//end class
