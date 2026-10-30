<?php

/**
 * Contract of the ConnectionAlertRecipientResolver.
 *
 * @category Contract
 * @package  OCA\Integriq\Notification
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

namespace OCA\Integriq\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ConnectionAlertRecipientResolver} that other components call.
 */
interface AlertRecipientResolverInterface {
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
	public function resolve(ObjectEntity $object, array $context): array;

	/**
	 * The group named for connection alerts, or the admin group when none is.
	 *
	 * @return string A group id, never empty.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-an-opened-alert-notifies-the-group-an-administrator-named-req-crun-005
	 */
	public function namedGroup(): string;
}
