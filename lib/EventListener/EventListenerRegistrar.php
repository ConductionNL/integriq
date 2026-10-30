<?php

/**
 * Integriq EventListenerRegistrar.
 *
 * Registers the listeners of the EventListener component.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Entry point of this component's container wiring for Application.
 *
 * The bindings themselves live in {@see EventListenerBindings}, inside the component.
 *
 * @spec exclude container wiring only, it forwards to the component's bindings and has no behaviour of its own
 */
final class EventListenerRegistrar {
	/**
	 * Register the listeners that Nextcloud attaches after register() returns.
	 *
	 * @param IRegistrationContext $context The app's registration context.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's listeners and has no behaviour of its own
	 */
	public function register(IRegistrationContext $context): void {
		(new EventListenerBindings())->register(context: $context);
	}//end register()

	/**
	 * Register the listeners on the event dispatcher, in the order they must run.
	 *
	 * Listeners on the same event run in registration order, so this order is
	 * part of the behaviour.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring only, it registers this component's listeners and has no behaviour of its own
	 */
	public function registerListeners(IEventDispatcher $dispatcher): void {
		(new EventListenerBindings())->registerListeners(dispatcher: $dispatcher);
	}//end registerListeners()

	/**
	 * Register the Tables row listeners; the caller checks that Tables is enabled.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 */
	public function registerTablesListeners(IEventDispatcher $dispatcher): void {
		(new EventListenerBindings())->registerTablesListeners(dispatcher: $dispatcher);
	}//end registerTablesListeners()

	/**
	 * Register the Forms submission listener; the caller checks that Forms is enabled.
	 *
	 * @param IEventDispatcher $dispatcher The event dispatcher.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md
	 */
	public function registerFormsListeners(IEventDispatcher $dispatcher): void {
		(new EventListenerBindings())->registerFormsListeners(dispatcher: $dispatcher);
	}//end registerFormsListeners()
}//end class
