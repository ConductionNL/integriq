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
use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCP\IL10N;
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
		$channel = (string)($notification->getSubjectParameters()['channel'] ?? DsoConnectionUnavailableException::CHANNEL_DSO);

		if ($channel === DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN) {
			$subject = $this->openFormulierenSubject(l: $l, reason: $reason);
			return $this->finish(notification: $notification, subject: $subject);
		}

		$webhook = WebhookProfiles::byChannel(channel: $channel);
		if ($webhook !== null) {
			$subject = $this->webhookSubject(l: $l, label: $webhook->label, reason: $reason);
			return $this->finish(notification: $notification, subject: $subject);
		}

		$subject = match ($reason) {
			'no_connection', 'ambiguous_connection' => $l->t('DSO-LV pushes are refused: no DSO connection is configured.'),
			'no_account', 'account_unknown', 'account_disabled' => $l->t('DSO-LV pushes are refused: the DSO connection has no usable account.'),
			'account_lacks_rights', 'rights_unverifiable' => $l->t('DSO-LV pushes are refused: the DSO connection account cannot store verzoeken.'),
			DsoConnectionAlerts::REASON_NOT_STORED => $l->t('A DSO verzoek could not be stored. DSO-LV will deliver it again.'),
			DsoConnectionAlerts::REASON_JOB_ACCOUNT => $l->t('DSO bijlagen were not downloaded: the account that stored the verzoek is no longer usable.'),
			DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT => $l->t('Choose the account the DSO intake acts as.'),
			default => $l->t('The DSO connection needs attention.'),
		};

		return $this->finish(notification: $notification, subject: $subject);
	}//end prepare()

	/**
	 * The text of an Open Formulieren connection alert.
	 *
	 * @param IL10N  $l      The localisation.
	 * @param string $reason The reason.
	 *
	 * @return string The parsed subject.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
	 */
	private function openFormulierenSubject(IL10N $l, string $reason): string {
		return match ($reason) {
			'no_connection', 'ambiguous_connection' => $l->t(
				'Open Formulieren submissions are refused: no Open Formulieren connection is configured.'
			),
			'no_account', 'account_unknown', 'account_disabled' => $l->t(
				'Open Formulieren submissions are refused: the Open Formulieren connection has no usable account.'
			),
			'account_lacks_rights', 'rights_unverifiable' => $l->t(
				'Open Formulieren submissions are refused: the Open Formulieren connection account cannot store submissions.'
			),
			DsoConnectionAlerts::REASON_SUBMISSION_NOT_STORED => $l->t(
				'An Open Formulieren submission could not be stored. Open Formulieren will deliver it again.'
			),
			DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT => $l->t('Choose the account the Open Formulieren intake acts as.'),
			default => $l->t('The Open Formulieren connection needs attention.'),
		};

	}//end openFormulierenSubject()

	/**
	 * The subject of an alert of a signed webhook on the consumer model.
	 *
	 * @param IL10N  $l      The localisation.
	 * @param string $label  The webhook's partner name.
	 * @param string $reason The alert reason.
	 *
	 * @return string The subject.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-a-connection-without-a-usable-account-refuses-with-503
	 */
	private function webhookSubject(IL10N $l, string $label, string $reason): string {
		return match ($reason) {
			'no_connection', 'ambiguous_connection' => $l->t('%1$s deliveries are refused: no %1$s connection is configured.', [$label]),
			'no_account', 'account_unknown', 'account_disabled' => $l->t('%1$s deliveries are refused: the %1$s connection has no usable account.', [$label]),
			'account_lacks_rights', 'rights_unverifiable' => $l->t('%1$s deliveries are refused: the %1$s connection account cannot store them.', [$label]),
			DsoConnectionAlerts::REASON_DELIVERY_NOT_STORED => $l->t('A %s delivery could not be stored. The sender will deliver it again.', [$label]),
			DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT => $l->t('Choose the account the %s webhook acts as.', [$label]),
			default => $l->t('The %s connection needs attention.', [$label]),
		};

	}//end webhookSubject()

	/**
	 * Set the subject, the link to the admin section and the icon.
	 *
	 * @param INotification $notification The notification.
	 * @param string        $subject      The parsed subject.
	 *
	 * @return INotification The prepared notification.
	 */
	private function finish(INotification $notification, string $subject): INotification {
		$notification->setParsedSubject($subject);
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => Application::APP_ID]));
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg')
			)
		);

		return $notification;
	}//end finish()

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
