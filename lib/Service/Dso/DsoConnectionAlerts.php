<?php

/**
 * Integriq DsoConnectionAlerts.
 *
 * Tells the administrators when the DSO connection loses verzoeken: at most
 * one notification per reason per hour, to every member of the `admin` group.
 * The event is "nothing was stored", so there is no object event for an
 * `x-openregister-notifications` declaration (ADR-031) to hang on. This is
 * the one imperative notification of the change.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
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
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use DateTime;
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Kernel\AppIdentity;
use OCA\Integriq\Service\Portal\PortalConnectionAlertsInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Throttled admin notifications for the DSO connection.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */
class DsoConnectionAlerts implements PortalConnectionAlertsInterface {

	/**
	 * Seconds between two notifications for the same reason.
	 *
	 * @var int
	 */
	public const THROTTLE_SECONDS = 3600;

	/**
	 * App-config key suffix holding the last send time per channel and reason.
	 *
	 * The key is `<channel>_alert_last_<reason>`, so the DSO keys keep their
	 * old name (`dso_alert_last_<reason>`).
	 *
	 * @var string
	 */
	private const LAST_SENT_INFIX = '_alert_last_';

	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notificationManager Sends the notification.
	 * @param IGroupManager        $groupManager        Finds the administrators.
	 * @param IAppConfig           $appConfig           Remembers the last send per reason.
	 * @param ITimeFactory         $timeFactory         The clock.
	 * @param LoggerInterface      $logger              Diagnostics.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Notify the administrators, unless this reason was sent within the hour.
	 *
	 * The Open Formulieren intake uses the same alerts with its own channel, so
	 * the throttle and the rendered text are per intake.
	 *
	 * @param string $reason  The reason: a DsoConnectionUnavailableException reason or a REASON_* constant.
	 * @param string $channel The intake: DsoConnectionUnavailableException::CHANNEL_DSO or CHANNEL_OPEN_FORMULIEREN.
	 *
	 * @return bool True when a notification went out.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function notify(string $reason, string $channel = DsoConnectionUnavailableException::CHANNEL_DSO): bool {
		$notice = new DsoAlertNotice(
			reason: $reason,
			channel: (string)preg_replace('/[^a-z]/', '', $channel),
			now: $this->timeFactory->getTime()
		);
		if ($this->claimSlot(notice: $notice) === false) {
			return false;
		}

		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return false;
		}

		$sent = false;
		foreach ($admins->getUsers() as $admin) {
			$sent = $this->notifyAdmin(admin: $admin, notice: $notice) || $sent;
		}

		return $sent;

	}//end notify()

	/**
	 * Remember this send, unless the same channel and reason went out within the hour.
	 *
	 * @param DsoAlertNotice $notice The alert.
	 *
	 * @return bool False when the alert is throttled.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function claimSlot(DsoAlertNotice $notice): bool {
		$key = $notice->channel . self::LAST_SENT_INFIX . preg_replace('/[^a-z_]/', '', $notice->reason);
		$last = $this->appConfig->getValueInt(AppIdentity::APP_ID, $key, 0);
		if ($last > 0 && ($notice->now - $last) < self::THROTTLE_SECONDS) {
			return false;
		}

		$this->appConfig->setValueInt(AppIdentity::APP_ID, $key, $notice->now);
		return true;

	}//end claimSlot()

	/**
	 * Send the alert to one administrator; a failure is logged, not thrown.
	 *
	 * @param IUser          $admin  The administrator.
	 * @param DsoAlertNotice $notice The alert.
	 *
	 * @return bool True when the notification went out.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function notifyAdmin(IUser $admin, DsoAlertNotice $notice): bool {
		try {
			$this->notificationManager->notify($this->notificationFor(admin: $admin, notice: $notice));
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DsoConnectionAlerts] could not notify an administrator',
				['reason' => $notice->reason, 'exception' => $exception->getMessage()]
			);
			return false;
		}

	}//end notifyAdmin()

	/**
	 * The notification for one administrator.
	 *
	 * @param IUser          $admin  The administrator.
	 * @param DsoAlertNotice $notice The alert.
	 *
	 * @return INotification
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function notificationFor(IUser $admin, DsoAlertNotice $notice): INotification {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(AppIdentity::APP_ID)
			->setUser($admin->getUID())
			->setDateTime((new DateTime())->setTimestamp($notice->now))
			->setObject($notice->channel . '_connection', $notice->reason)
			->setSubject(self::SUBJECT, ['reason' => $notice->reason, 'channel' => $notice->channel]);
		return $notification;

	}//end notificationFor()
}//end class
