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
use OCA\Integriq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Throttled admin notifications for the DSO connection.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */
class DsoConnectionAlerts {

	/**
	 * The notification subject.
	 *
	 * @var string
	 */
	public const SUBJECT = 'dso_connection_alert';

	/**
	 * Reason: OpenRegister refused a write anyway.
	 *
	 * @var string
	 */
	public const REASON_NOT_STORED = 'verzoek_not_stored';

	/**
	 * Reason: a bijlage job found no usable account.
	 *
	 * @var string
	 */
	public const REASON_JOB_ACCOUNT = 'job_account_unavailable';

	/**
	 * Reason: the migration created a connection without an account.
	 *
	 * @var string
	 */
	public const REASON_CHOOSE_ACCOUNT = 'choose_account';

	/**
	 * Seconds between two notifications for the same reason.
	 *
	 * @var int
	 */
	public const THROTTLE_SECONDS = 3600;

	/**
	 * App-config key prefix holding the last send time per reason.
	 *
	 * @var string
	 */
	private const LAST_SENT_PREFIX = 'dso_alert_last_';

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
	 * @param string $reason The reason: a DsoConnectionUnavailableException reason or a REASON_* constant.
	 *
	 * @return bool True when a notification went out.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function notify(string $reason): bool {
		$now = $this->timeFactory->getTime();
		$key = self::LAST_SENT_PREFIX . preg_replace('/[^a-z_]/', '', $reason);
		$last = $this->appConfig->getValueInt(Application::APP_ID, $key, 0);
		if ($last > 0 && ($now - $last) < self::THROTTLE_SECONDS) {
			return false;
		}

		$this->appConfig->setValueInt(Application::APP_ID, $key, $now);

		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return false;
		}

		$sent = false;
		foreach ($admins->getUsers() as $admin) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp(Application::APP_ID)
					->setUser($admin->getUID())
					->setDateTime((new DateTime())->setTimestamp($now))
					->setObject('dso_connection', $reason)
					->setSubject(self::SUBJECT, ['reason' => $reason]);
				$this->notificationManager->notify($notification);
				$sent = true;
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[DsoConnectionAlerts] could not notify an administrator',
					['reason' => $reason, 'exception' => $exception->getMessage()]
				);
			}
		}

		return $sent;

	}//end notify()
}//end class
