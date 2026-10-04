<?php

/**
 * Integriq DsoConnectionNotifier.
 *
 * Renders the administrator notifications of the DSO connection: pushes
 * refused because the connection or its account is not usable, a verzoek that
 * could not be stored, a bijlage job without a usable account, and the
 * migration's request to choose the account.
 *
 * @category Notification
 * @package  OCA\Integriq\Notification
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

namespace OCA\Integriq\Notification;

use InvalidArgumentException;
use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Notifier for the DSO connection alerts.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
 */
class DsoConnectionNotifier implements INotifier {

	/**
	 * Constructor.
	 *
	 * @param IFactory      $l10nFactory  Localisation.
	 * @param IURLGenerator $urlGenerator Icon and settings link.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The notifier id. Distinct from the approval notifier's.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function getID(): string {
		return Application::APP_ID . '_dso_connection';
	}//end getID()

	/**
	 * The notifier name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('DSO connection');
	}//end getName()

	/**
	 * Prepare a DSO connection notification for display.
	 *
	 * @param INotification $notification The notification.
	 * @param string        $languageCode The language code.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @throws InvalidArgumentException When the notification is not a DSO connection alert.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== DsoConnectionAlerts::SUBJECT) {
			throw $this->unknownNotification();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$reason = (string)($notification->getSubjectParameters()['reason'] ?? '');

		$subject = match ($reason) {
			'no_connection', 'ambiguous_connection' => $l->t('DSO-LV pushes are refused: no DSO connection is configured.'),
			'no_account', 'account_unknown', 'account_disabled' => $l->t('DSO-LV pushes are refused: the DSO connection has no usable account.'),
			'account_lacks_rights', 'rights_unverifiable' => $l->t('DSO-LV pushes are refused: the DSO connection account cannot store verzoeken.'),
			DsoConnectionAlerts::REASON_NOT_STORED => $l->t('A DSO verzoek could not be stored. DSO-LV will deliver it again.'),
			DsoConnectionAlerts::REASON_JOB_ACCOUNT => $l->t('DSO bijlagen were not downloaded: the account that stored the verzoek is no longer usable.'),
			DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT => $l->t('Choose the account the DSO intake acts as.'),
			default => $l->t('The DSO connection needs attention.'),
		};

		$notification->setParsedSubject($subject);
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]));
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg')
			)
		);

		return $notification;
	}//end prepare()

	/**
	 * The exception that declines a notification this notifier does not own.
	 *
	 * @return InvalidArgumentException UnknownNotificationException where the platform has it.
	 */
	private function unknownNotification(): InvalidArgumentException {
		$class = 'OCP\\Notification\\UnknownNotificationException';
		if (class_exists($class) === true) {
			return new $class();
		}

		return new InvalidArgumentException();
	}//end unknownNotification()
}//end class
