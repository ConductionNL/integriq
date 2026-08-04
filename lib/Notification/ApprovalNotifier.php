<?php

/**
 * OpenConnector Approval Notifier.
 *
 * Prepares the imperatively-dispatched HITL approval notification
 * (`ApprovalService::notifyApprovers()`) for display: resolves the parsed
 * subject text and the Approve/Reject action labels. Without an INotifier
 * registered for this app id, a notification created via
 * `OCP\Notification\IManager` is silently dropped when the client asks the
 * server to prepare it — this class is required for the notification to be
 * visible at all, not merely for nicer copy.
 *
 * @category Notification
 * @package  OCA\OpenConnector\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Notification;

use OCA\OpenConnector\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Notifier for the imperatively-dispatched `approval_pending` notification.
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
class ApprovalNotifier implements INotifier
{
    /**
     * Constructor.
     *
     * @param IFactory      $l10nFactory  The l10n factory.
     * @param IURLGenerator $urlGenerator The URL generator (app icon).
     */
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Get the notifier ID.
     *
     * @return string
     *
     * @spec openspec/specs/approval-workflow/spec.md
     */
    public function getID(): string
    {
        return Application::APP_ID;

    }//end getID()

    /**
     * Get the notifier name.
     *
     * @return string
     *
     * @spec openspec/specs/approval-workflow/spec.md
     */
    public function getName(): string
    {
        return $this->l10nFactory->get(Application::APP_ID)->t('OpenConnector');

    }//end getName()

    /**
     * The exception that declines a notification this notifier does not own.
     *
     * Every registered notifier is offered EVERY notification, so declining one
     * that is not ours is the normal case, not an error. Nextcloud deprecated
     * InvalidArgumentException for exactly this and logs a warning on each
     * throw, which turned routine declines into a flood — dozens of identical
     * warnings within one second on a single dashboard load, drowning the log
     * that real problems surface in.
     *
     * The replacement is chosen at RUNTIME rather than named directly, because
     * this app declares `min-version="28"` and UnknownNotificationException is
     * `@since 30.0.0`. Referencing it unconditionally would turn a log-noise fix
     * into a fatal error on NC 28 and 29 — which is what psalm caught here,
     * analysing against the pinned `nextcloud/ocp: dev-stable29` stubs. It
     * extends \InvalidArgumentException, so callers behave identically either
     * way and the fallback is a true equivalent rather than a degradation.
     *
     * @return \InvalidArgumentException UnknownNotificationException where the platform has it.
     *
     * @spec openspec/specs/approval-workflow/spec.md
     */
    private function unknownNotification(): \InvalidArgumentException
    {
        $class = 'OCP\\Notification\\UnknownNotificationException';
        if (class_exists($class) === true) {
            return new $class();
        }

        return new \InvalidArgumentException();

    }//end unknownNotification()

    /**
     * Prepare an `approval_pending` notification for display: parsed
     * subject text, app icon, and parsed labels for the Approve/Reject
     * deep-link actions created by `ApprovalService::notifyApprovers()`.
     *
     * @param INotification $notification The notification to prepare.
     * @param string        $languageCode The language code.
     *
     * @return INotification The prepared notification.
     *
     * @throws \InvalidArgumentException When this notifier does not own the notification's app/subject.
     *
     * @spec openspec/specs/approval-workflow/spec.md
     */
    public function prepare(INotification $notification, string $languageCode): INotification
    {
        if ($notification->getApp() !== Application::APP_ID) {
            throw $this->unknownNotification();
        }

        if ($notification->getSubject() !== 'approval_pending') {
            throw $this->unknownNotification();
        }

        $l      = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();
        $group  = (string) ($params['approverGroup'] ?? '');

        $notification->setParsedSubject($l->t('Approval requested (%1$s)', [$group]));
        $notification->setRichSubject(
            $l->t('Approval requested ({group})'),
            ['group' => ['type' => 'highlight', 'id' => $group, 'name' => $group]]
        );

        $notification->setIcon(
            $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg')
            )
        );

        foreach ($notification->getActions() as $action) {
            $label = $l->t('Reject');
            if ($action->getLabel() === 'approve') {
                $label = $l->t('Approve');
            }

            $notification->addParsedAction($action->setParsedLabel($label));
        }

        return $notification;

    }//end prepare()
}//end class
