<?php

/**
 * Integriq NotificationRegistrar.
 *
 * Registers the notifiers of the Notification component.
 *
 * @category Notification
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the services the Notification component builds itself, so Application
 * does not name the classes behind them.
 *
 * @spec exclude container wiring only, the registrar has no behaviour of its own
 */
final class NotificationRegistrar {
	/**
	 * Register this component's services on the app's registration context.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's services and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		$this->registerNotifiers(context: $context);
	}//end register()

	/**
	 * Registers the notifiers.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerNotifiers(IRegistrationContext $context): void {
		// HITL approval workflow: the actionable approver notification is
		// dispatched imperatively (ApprovalService::notifyApprovers(), see
		// openspec/changes/archive/2026-07-15-hitl-approval-rule-action/design.md Decision 4) —
		// without a notifier registered under this app id, the notification
		// manager silently drops it when preparing it for display.
		$context->registerNotifierService(\OCA\Integriq\Notification\ApprovalNotifier::class);
		// DSO intake (dso-intake-through-an-integriq-connection): admin alerts when DSO-LV pushes are refused.
		$context->registerNotifierService(\OCA\Integriq\Notification\DsoConnectionNotifier::class);
	}//end registerNotifiers()
}
